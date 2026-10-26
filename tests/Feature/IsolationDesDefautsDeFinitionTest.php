<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Manufacturer;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les valeurs par defaut d'une finition n'entrent PAS dans le calcul de
 * compatibilite.
 *
 * ── POURQUOI CE BANC EXISTE, ET POURQUOI AVANT TOUTE MIGRATION ─────────────
 *
 * `CompatibilityService::partsForOwnedVehicle` porte une affirmation forte :
 * « Une valeur par defaut de finition est une aide de saisie, pas la
 * configuration constatee du vehicule. Seules les valeurs explicites reduisent
 * la recherche. » Rien ne la mesurait.
 *
 * Elle est pourtant ce qui rend une correction de defaut SANS DANGER. Si un
 * defaut de finition entrait dans la recherche, corriger
 * `trims.default_drivetrain_id` — ce que `catalog:correct-trims` sait faire, et
 * ce qu'une migration de donnees pourrait faire — changerait d'un coup les
 * pieces proposees a TOUS les clients dont le vehicule porte cette finition,
 * sans qu'une seule ligne de leur garage ait bouge. Une correction juste
 * deviendrait une regression invisible.
 *
 * D'ou l'ordre : mesurer l'isolation d'abord, corriger les defauts ensuite.
 *
 * ── LE PIEGE QUE CE BANC SURVEILLE, ET IL EST DEJA EN PLACE ────────────────
 *
 * `OwnedVehicle` definit deux accesseurs qui, eux, RETOMBENT sur le defaut de
 * la finition :
 *
 *     getEffectiveEngineTypeIdAttribute() -> engine_type_id ?? trim->default_engine_type_id
 *     getEffectiveDrivetrainIdAttribute() -> drivetrain_id  ?? trim->default_drivetrain_id
 *
 * Leur docblock annonce « retenu pour le calcul de compatibilite ». Or le
 * calcul de compatibilite ne les lit PAS : il passe les colonnes brutes.
 * Verifie le 26/10/2026 — `grep -rn effective_engine_type_id app/ tests/
 * database/ resources/` ne rend AUCUNE occurrence hors de leur definition.
 *
 * Ce sont donc deux accesseurs morts, portant un nom et un commentaire qui
 * INVITENT a cabler ce que le service refuse deliberement de cabler. Le jour ou
 * quelqu'un « repare » cette incoherence dans le sens qui parait evident, c'est
 * `test_cabler_les_accesseurs_effectifs_casserait_l_isolation` qui tombe
 * d'abord, et qui dit pourquoi.
 */
class IsolationDesDefautsDeFinitionTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $modele;
    private Trim $finition;
    private Part $pieceTractionAvant;
    private Part $pieceSansMotricite;
    private Drivetrain $avant;
    private Drivetrain $integrale;
    private EngineType $essence;
    private EngineType $hybride;

    protected function setUp(): void
    {
        parent::setUp();

        $marque = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->modele = VehicleModel::create([
            'brand_id' => $marque->id, 'name' => 'Highlander', 'slug' => 'highlander',
        ]);

        $this->avant = Drivetrain::create(['code' => 'FWD', 'label' => 'Traction avant']);
        $this->integrale = Drivetrain::create(['code' => 'AWD', 'label' => 'Integrale']);
        $this->essence = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->hybride = EngineType::create(['code' => 'hybrid', 'label' => 'Hybride']);

        // La finition porte des defauts, et ce sont EUX qui ne doivent pas
        // filtrer : integrale et hybride.
        $this->finition = Trim::create([
            'vehicle_model_id' => $this->modele->id,
            'name' => 'Limited',
            'rank' => 3,
            'default_engine_type_id' => $this->hybride->id,
            'default_drivetrain_id' => $this->integrale->id,
        ]);

        $categorie = PartCategory::create(['name' => 'Freinage', 'slug' => 'freinage']);
        $fabricant = Manufacturer::create(['name' => 'Bosch']);

        // Une piece declaree pour la TRACTION AVANT et l'ESSENCE : exactement
        // ce que les defauts de la finition excluraient s'ils filtraient.
        $this->pieceTractionAvant = $this->piece('Plaquettes avant FWD', $categorie, $fabricant);
        DB::table('part_fitments')->insert([
            'part_id' => $this->pieceTractionAvant->id,
            'vehicle_model_id' => $this->modele->id,
            'drivetrain_id' => $this->avant->id,
            'engine_type_id' => $this->essence->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Et une piece sans contrainte : elle doit toujours sortir, quoi qu'il
        // arrive. Sa presence est ce qui distingue « la requete filtre trop »
        // de « la requete ne rend rien du tout ».
        $this->pieceSansMotricite = $this->piece('Filtre a huile universel', $categorie, $fabricant);
        DB::table('part_fitments')->insert([
            'part_id' => $this->pieceSansMotricite->id,
            'vehicle_model_id' => $this->modele->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function piece(string $nom, PartCategory $categorie, Manufacturer $fabricant): Part
    {
        return Part::create([
            'sku' => 'PRT-'.strtoupper(substr(md5($nom), 0, 8)),
            'name' => $nom,
            'part_category_id' => $categorie->id,
            'manufacturer_id' => $fabricant->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 9000,
            'currency' => 'XOF',
            'vat_rate' => 18.00,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);
    }

    /** Un vehicule de garage portant la finition, sans motricite ni moteur saisis. */
    private function vehiculeDeGarage(array $attributs = []): OwnedVehicle
    {
        return OwnedVehicle::create(array_replace([
            'user_id' => User::factory()->create(['role' => 'client'])->id,
            'brand_id' => $this->modele->brand_id,
            'vehicle_model_id' => $this->modele->id,
            'trim_id' => $this->finition->id,
            'manufacturing_year' => 2021,
        ], $attributs));
    }

    private function service(): CompatibilityService
    {
        return app(CompatibilityService::class);
    }

    // ── LE COEUR : le defaut de la finition ne filtre pas ────────────────────

    public function test_un_defaut_de_finition_ne_retrecit_pas_le_garage(): void
    {
        $vehicule = $this->vehiculeDeGarage();

        // La finition dit « integrale, hybride ». La piece est declaree
        // « traction avant, essence ». Si le defaut filtrait, elle disparaitrait.
        $ids = $this->service()->partsForOwnedVehicle($vehicule)->pluck('id')->all();

        $this->assertContains(
            $this->pieceTractionAvant->id,
            $ids,
            'Le defaut de la finition a filtre : une piece qui convient est cachee au client.',
        );
        $this->assertContains($this->pieceSansMotricite->id, $ids);
    }

    /**
     * LE TEST A FAIRE AVANT TOUTE MIGRATION DE DEFAUTS.
     *
     * On change le defaut de la finition sous le vehicule, sans toucher au
     * vehicule, et on exige que le resultat soit IDENTIQUE. C'est la propriete
     * qui rend `catalog:correct-trims` — et toute migration de donnees qui
     * ferait la meme chose — sans effet sur ce que les clients voient.
     */
    public function test_changer_le_defaut_de_la_finition_ne_change_rien_pour_un_vehicule_existant(): void
    {
        $vehicule = $this->vehiculeDeGarage();

        $avant = $this->service()->partsForOwnedVehicle($vehicule)->pluck('id')->sort()->values()->all();

        foreach ([
            ['default_drivetrain_id' => $this->avant->id, 'default_engine_type_id' => $this->essence->id],
            ['default_drivetrain_id' => null, 'default_engine_type_id' => null],
            ['default_drivetrain_id' => $this->integrale->id, 'default_engine_type_id' => $this->hybride->id],
        ] as $defauts) {
            $this->finition->update($defauts);

            $apres = $this->service()
                ->partsForOwnedVehicle($vehicule->fresh())
                ->pluck('id')->sort()->values()->all();

            $this->assertSame(
                $avant,
                $apres,
                'Corriger un defaut de finition a change les pieces proposees a un client : '
                .'une correction juste deviendrait une regression invisible.',
            );
        }
    }

    /**
     * LA CONTRE-EPREUVE, sans laquelle le test ci-dessus ne prouve rien.
     *
     * Un service qui ignorerait TOUT rendrait les memes pieces quoi qu'il
     * arrive, et passerait les deux tests precedents a vide. Il faut donc
     * montrer qu'une valeur EXPLICITE sur le vehicule, elle, filtre bien.
     */
    public function test_une_valeur_explicite_sur_le_vehicule_filtre_bien(): void
    {
        $vehicule = $this->vehiculeDeGarage(['drivetrain_id' => $this->integrale->id]);

        $ids = $this->service()->partsForOwnedVehicle($vehicule)->pluck('id')->all();

        $this->assertNotContains(
            $this->pieceTractionAvant->id,
            $ids,
            'Une motricite saisie par le client doit filtrer : sinon le banc ci-dessus passe a vide.',
        );
        $this->assertContains($this->pieceSansMotricite->id, $ids);
    }

    public function test_un_moteur_explicite_sur_le_vehicule_filtre_bien(): void
    {
        $vehicule = $this->vehiculeDeGarage(['engine_type_id' => $this->hybride->id]);

        $ids = $this->service()->partsForOwnedVehicle($vehicule)->pluck('id')->all();

        $this->assertNotContains($this->pieceTractionAvant->id, $ids);
        $this->assertContains($this->pieceSansMotricite->id, $ids);
    }

    // ── La meme isolation sur une annonce du parc ────────────────────────────

    public function test_un_defaut_de_finition_ne_retrecit_pas_non_plus_une_annonce_du_parc(): void
    {
        // `applyVehicleCriteria` lit aussi les colonnes brutes de `vehicles`.
        // La propriete est la meme, et elle merite d'etre tenue des deux cotes :
        // une migration de defauts toucherait les deux chemins a la fois.
        $annonce = Vehicle::create([
            'reference' => 'VH-ISO-0001',
            'registration_status' => 'unregistered',
            'brand_id' => $this->modele->brand_id,
            'vehicle_model_id' => $this->modele->id,
            'trim_id' => $this->finition->id,
            'engine_type_id' => $this->essence->id,
            'manufacturing_year' => 2021,
            'condition' => 'used',
            'status' => 'in_stock',
            'availability' => 'sale',
            'sale_price' => 4_500_000,
            'currency' => 'XOF',
        ]);

        $avant = $this->service()->partsForVehicle($annonce)->pluck('id')->sort()->values()->all();

        $this->finition->update(['default_drivetrain_id' => $this->avant->id]);

        $this->assertSame(
            $avant,
            $this->service()->partsForVehicle($annonce->fresh())->pluck('id')->sort()->values()->all(),
        );
        $this->assertContains($this->pieceTractionAvant->id, $avant);
    }

    // ── Le piege : deux accesseurs morts qui invitent a casser l'isolation ──

    public function test_les_accesseurs_effectifs_retombent_bien_sur_le_defaut(): void
    {
        // Ils font ce que leur nom dit — ce n'est pas eux qui sont fautifs,
        // c'est leur docblock qui annonce un role qu'ils n'ont pas.
        $vehicule = $this->vehiculeDeGarage();

        $this->assertSame($this->integrale->id, $vehicule->effective_drivetrain_id);
        $this->assertSame($this->hybride->id, $vehicule->effective_engine_type_id);

        // Et une valeur saisie l'emporte sur le defaut.
        $explicite = $this->vehiculeDeGarage(['drivetrain_id' => $this->avant->id]);
        $this->assertSame($this->avant->id, $explicite->effective_drivetrain_id);
    }

    /**
     * Ce qui arriverait si on cablait les accesseurs « effectifs ».
     *
     * Ils s'appellent « effectifs », leur docblock dit « retenu pour le calcul
     * de compatibilite », et AUCUN code ne les lit. L'incoherence est tentante a
     * reparer dans le sens qui parait evident : les brancher. Ce test montre ce
     * que cela couterait, en comparant les deux resultats sur le meme vehicule.
     *
     * Il ne dit pas qu'il faut les garder ni les retirer — c'est une decision de
     * produit. Il dit qu'on ne peut pas les brancher par inadvertance.
     */
    public function test_cabler_les_accesseurs_effectifs_casserait_l_isolation(): void
    {
        $vehicule = $this->vehiculeDeGarage();

        $reel = $this->service()->partsForOwnedVehicle($vehicule)->pluck('id')->sort()->values()->all();

        // Ce que rendrait le service s'il lisait `effective_*` au lieu des
        // colonnes brutes.
        $siOnCablait = $this->service()->partsForCriteria(
            $vehicule->vehicle_model_id,
            $vehicule->manufacturing_year,
            $vehicule->trim_id,
            $vehicule->effective_engine_type_id,
            $vehicule->effective_drivetrain_id,
            $vehicule->effective_engine_code,
        )->pluck('id')->sort()->values()->all();

        $this->assertNotSame(
            $reel,
            $siOnCablait,
            'Les deux chemins donnent le meme resultat : ce banc ne surveille plus rien, '
            .'la fixture a perdu sa piece discriminante.',
        );
        $this->assertContains($this->pieceTractionAvant->id, $reel);
        $this->assertNotContains(
            $this->pieceTractionAvant->id,
            $siOnCablait,
            'Cabler les accesseurs effectifs cacherait une piece qui convient.',
        );
    }
}
