<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\TriPublic;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le tri du catalogue public.
 *
 * Deux defauts de production, tous deux atteignables SANS COMPTE, releves le
 * 24/10/2026 sur `main` et anterieurs a toute branche. `sort_by` et `sort_dir`
 * allaient bruts du parametre d'URL a `orderBy()`.
 *
 * UN. Un sens invalide rendait 500.
 *
 * DEUX. Trier sur une colonne absente de la reponse la divulgue par l'ordre.
 * Trois colonnes seulement sont au schema sans etre dans aucune ressource
 * publique : `vehicles.purchase_price`, `parts.cost_price`,
 * `accessories.cost_price`. Le prix d'achat, donc la marge — que
 * `PagesProduitTest::test_aucune_donnee_interne_ne_fuit_dans_la_page` traque
 * deja dans le CORPS des pages publiques. Ce banc ferme l'autre bout.
 *
 * ── POURQUOI `/api/catalog/...` ET NON `/api/vehicles` ─────────────────────
 *
 * Les memes methodes de controleur servent deux familles de routes.
 * `/api/vehicles` est reservee au personnel (`auth:sanctum` + `EnsureStaff`)
 * et rend 401 a un anonyme : un banc qui la viserait passerait au vert sans
 * rien prouver, puisque la requete n'atteindrait jamais le `orderBy`.
 * `/api/catalog/vehicles` ne porte que le groupe `api`. C'est la la surface
 * reelle, et c'est elle qu'on interroge ici.
 *
 * Les jeux sont construits pour que l'ordre par defaut et l'ordre par prix
 * d'achat DIVERGENT, et chaque test l'affirme avant de conclure : sans cela
 * l'assertion passerait quoi qu'il arrive, et c'est exactement par la qu'un
 * test de ce dossier s'est deja revele inerte.
 */
class TriPublicTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, int> les identifiants, dans l'ordre rendu */
    private function idsRendus(string $url): array
    {
        $reponse = $this->getJson($url);
        $reponse->assertOk();

        return array_column($reponse->json('data'), 'id');
    }

    // ── Fabriques, reduites au strict necessaire ─────────────────────────────

    /**
     * Un vehicule du catalogue, cree a une date MAITRISEE.
     *
     * `created_at` n'est pas `fillable` : le passer a `create()` ne fait rien,
     * les horodatages automatiques l'ecrasent. Or l'ordre par defaut des
     * vehicules est precisement `created_at desc` — sans controle sur cette
     * date, les jeux de ce banc ne divergeraient pas de l'ordre par prix et
     * les assertions ne prouveraient rien. D'ou le voyage dans le temps, qui
     * deplace `now()` et donc l'horodatage pose par Eloquent.
     */
    private function vehicule(int $prixAchat, int $prixVente, string $quand): Vehicle
    {
        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota', 'is_active' => true]);
        $modele = VehicleModel::firstOrCreate(
            ['slug' => 'corolla'],
            ['brand_id' => $marque->id, 'name' => 'Corolla', 'is_active' => true],
        );
        $motorisation = EngineType::firstOrCreate(
            ['code' => 'petrol'],
            ['label' => 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
        );

        $this->travelTo(Carbon::parse($quand));

        $vehicule = Vehicle::create([
            'reference'           => 'VH-'.str_pad((string) (Vehicle::count() + 1), 4, '0', STR_PAD_LEFT),
            'registration_status' => 'unregistered',
            'brand_id'            => $marque->id,
            'vehicle_model_id'    => $modele->id,
            'engine_type_id'      => $motorisation->id,
            'manufacturing_year'  => 2015,
            'condition'           => 'used',
            'status'              => 'in_stock',
            'availability'        => 'sale',
            'purchase_price'      => $prixAchat,
            'sale_price'          => $prixVente,
            'currency'            => 'XOF',
        ]);

        $this->travelBack();

        return $vehicule;
    }

    private function piece(string $nom, int $prixAchat): Part
    {
        $categorie = PartCategory::firstOrCreate(
            ['slug' => 'filtration'],
            ['name' => 'Filtration', 'parent_id' => null],
        );

        return Part::create([
            'sku'              => 'PRT-'.strtoupper(substr(md5($nom), 0, 8)),
            'name'             => $nom,
            'part_category_id' => $categorie->id,
            'manufacturer_id'  => Manufacturer::firstOrCreate(['name' => 'Bosch'])->id,
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'cost_price'       => $prixAchat,
            'selling_price'    => $prixAchat * 2,
            'currency'         => 'XOF',
            'vat_rate'         => 18.00,
            'stock_quantity'   => 4,
            'is_active'        => true,
        ]);
    }

    private function accessoire(string $nom, int $prixAchat): Accessory
    {
        return Accessory::create([
            'sku'             => 'ACC-'.strtoupper(substr(md5($nom), 0, 8)),
            'name'            => $nom,
            'category'        => 'confort',
            'manufacturer_id' => Manufacturer::firstOrCreate(['name' => 'Bosch'])->id,
            'cost_price'      => $prixAchat,
            'selling_price'   => $prixAchat * 2,
            'currency'        => 'XOF',
            'vat_rate'        => 18.00,
            'stock_quantity'  => 4,
            'is_active'       => true,
        ]);
    }

    // ── DEFAUT UN : le 500 sur un parametre invalide ─────────────────────────

    #[DataProvider('catalogues')]
    public function test_un_tri_invalide_ne_rend_pas_une_erreur_serveur(string $route): void
    {
        foreach ([
            '?sort_dir=nimportequoi',
            '?sort_dir=',
            '?sort_by=colonne_qui_n_existe_pas',
            '?sort_by=id%3B%20DROP%20TABLE%20users',
            '?sort_by=&sort_dir=ASC',
        ] as $parametre) {
            // Et non 500 : la liste se rend dans son ordre par defaut.
            $this->getJson($route.$parametre)->assertOk();
        }
    }

    /** @return array<string, array{0: string}> */
    public static function catalogues(): array
    {
        return [
            'vehicules'   => ['/api/catalog/vehicles'],
            'pieces'      => ['/api/catalog/parts'],
            'accessoires' => ['/api/catalog/accessories'],
        ];
    }

    /**
     * La contre-epreuve : ces routes sont bien ANONYMES.
     *
     * Si quelqu'un y posait un jour `auth:sanctum`, les tests ci-dessus
     * passeraient au vert en 401 sans plus rien prouver. Celui-ci tombe
     * d'abord.
     */
    #[DataProvider('catalogues')]
    public function test_le_catalogue_repond_sans_compte(string $route): void
    {
        $this->getJson($route)->assertOk();
    }

    /** Un sens ecrit en capitales reste un sens valide. */
    public function test_le_sens_est_lu_sans_egard_a_la_casse(): void
    {
        $this->vehicule(1_000_000, 2_000_000, '2026-10-01 10:00:00');
        $this->vehicule(4_000_000, 9_000_000, '2026-10-02 10:00:00');

        $this->assertSame(
            $this->idsRendus('/api/catalog/vehicles?sort_by=sale_price&sort_dir=asc'),
            $this->idsRendus('/api/catalog/vehicles?sort_by=sale_price&sort_dir=ASC'),
        );
    }

    // ── DEFAUT DEUX : l'oracle sur le prix d'achat ───────────────────────────

    /**
     * La colonne et le SENS sont valides SEPAREMENT.
     *
     * Une colonne refusee retombe sur `created_at`, mais un sens valide est
     * honore quand meme : `?sort_by=purchase_price&sort_dir=asc` rend donc
     * `created_at asc`, et non l'ordre par defaut complet. C'est voulu — `asc`
     * est une demande legitime, et les deux retombees sont des ordres
     * chronologiques qui ne disent rien du prix d'achat. Ce test fixe cette
     * regle, qui ne se devine pas a la lecture de l'appel.
     */
    public function test_trier_les_vehicules_par_prix_d_achat_est_refuse(): void
    {
        $vieux = $this->vehicule(1_000_000, 3_000_000, '2026-10-01 10:00:00');
        $moyen = $this->vehicule(9_000_000, 12_000_000, '2026-10-02 10:00:00');
        $neuf  = $this->vehicule(5_000_000, 7_000_000, '2026-10-03 10:00:00');

        $chronologique = [
            'asc'  => [$vieux->id, $moyen->id, $neuf->id],
            'desc' => [$neuf->id, $moyen->id, $vieux->id],
        ];
        $parPrixDAchat = [
            'asc'  => [$vieux->id, $neuf->id, $moyen->id],
            'desc' => [$moyen->id, $neuf->id, $vieux->id],
        ];

        foreach (['asc', 'desc'] as $sens) {
            // Le jeu est bien discriminant : si ces deux ordres etaient egaux,
            // l'assertion suivante ne prouverait rien.
            $this->assertNotSame($chronologique[$sens], $parPrixDAchat[$sens]);

            $rendu = $this->idsRendus("/api/catalog/vehicles?sort_by=purchase_price&sort_dir={$sens}");

            $this->assertSame($chronologique[$sens], $rendu);
            $this->assertNotSame(
                $parPrixDAchat[$sens],
                $rendu,
                "Le catalogue s'est laisse classer par prix d'achat ({$sens}) : la marge est deductible de l'ordre.",
            );
        }
    }

    /** Idem sur les pieces, dont l'ordre par defaut est le nom croissant. */
    public function test_trier_les_pieces_par_prix_de_revient_est_refuse(): void
    {
        $alpha = $this->piece('Alpha', 3000);
        $beta  = $this->piece('Beta', 1000);
        $gamma = $this->piece('Gamma', 2000);

        $defaut = [$alpha->id, $beta->id, $gamma->id];
        $this->assertNotSame($defaut, [$beta->id, $gamma->id, $alpha->id]);

        $this->assertSame($defaut, $this->idsRendus('/api/catalog/parts?sort_by=cost_price&sort_dir=asc'));
    }

    /** Et sur les accessoires, qui portent la meme colonne cachee. */
    public function test_trier_les_accessoires_par_prix_de_revient_est_refuse(): void
    {
        $alpha = $this->accessoire('Alpha', 3000);
        $beta  = $this->accessoire('Beta', 1000);
        $gamma = $this->accessoire('Gamma', 2000);

        $defaut = [$alpha->id, $beta->id, $gamma->id];
        $this->assertNotSame($defaut, [$beta->id, $gamma->id, $alpha->id]);

        $this->assertSame($defaut, $this->idsRendus('/api/catalog/accessories?sort_by=cost_price&sort_dir=asc'));
    }

    // ── Ce que la severite ne doit pas couter ────────────────────────────────

    /** Un tri legitime fonctionne : la liste blanche ne ferme pas la porte a tous. */
    public function test_un_tri_autorise_est_honore(): void
    {
        $cher      = $this->vehicule(8_000_000, 12_000_000, '2026-10-01 10:00:00');
        $bonMarche = $this->vehicule(1_000_000, 2_000_000, '2026-10-02 10:00:00');

        $this->assertSame(
            [$bonMarche->id, $cher->id],
            $this->idsRendus('/api/catalog/vehicles?sort_by=sale_price&sort_dir=asc'),
        );
        $this->assertSame(
            [$cher->id, $bonMarche->id],
            $this->idsRendus('/api/catalog/vehicles?sort_by=sale_price&sort_dir=desc'),
        );
    }

    // ── La frontiere, nommement ──────────────────────────────────────────────

    /**
     * Le test qui tombera si quelqu'un elargit la liste blanche sans y penser.
     *
     * Les trois premieres colonnes sont l'oracle. Les suivantes sont deja dans
     * la reponse publique sur `main`, donc inoffensives a trier pour
     * l'instant — mais le jour ou une redaction les retirera du corps de la
     * reponse, les laisser triables la rendrait decorative. Aucun autre test
     * de la suite ne verrait ca tomber.
     */
    public function test_aucune_colonne_de_gestion_n_est_triable(): void
    {
        $interdites = [
            'purchase_price', 'cost_price', 'margin',
            'stock_quantity', 'storage_location', 'vin', 'plate_number',
            'internal_reference', 'supplier_id',
        ];

        foreach (['vehicules' => TriPublic::vehicules(), 'articles' => TriPublic::articles()] as $nom => $permises) {
            $fautives = array_values(array_intersect($permises, $interdites));

            $this->assertSame([], $fautives, sprintf(
                'La liste blanche « %s » autorise %s : un visiteur anonyme pourrait classer le catalogue dessus.',
                $nom,
                implode(', ', $fautives)
            ));
        }
    }
}
