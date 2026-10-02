<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Declarer a la main les vehicules compatibles avec une piece.
 *
 * L'API savait deja ecrire ces lignes ; ce qu'elle ne savait pas faire, c'est
 * les rendre. `PartResource` publiait « Toyota Corolla », « 1.4 D-4D », la
 * finition par son nom — des libelles, quand l'ecriture attend `trim_id` et
 * `engine_type_id`. Un ecran d'edition qui relisait la liste et la renvoyait
 * perdait donc la finition, la motorisation, la transmission et le code
 * moteur a chaque enregistrement, sans message et sans erreur.
 *
 * C'est le genre de perte qu'aucun test d'ecriture ne voit, parce que
 * l'ecriture va bien. Le test central ici est donc un aller-retour complet :
 * on lit ce que l'API rend, on le renvoie tel quel, et on exige que rien
 * n'ait bouge.
 *
 * Deuxieme chose verrouillee : l'origine. Toute ligne passee par l'API est
 * une declaration, c'est elle qui met la saisie hors d'atteinte du recalcul
 * du catalogue (voir BackfillFitmentsTest).
 */
class CompatibiliteSaisieTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $modele;
    private Trim $finition;
    private EngineType $motorisation;
    private Drivetrain $transmission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $marque = Brand::create(['name' => 'Marque Test', 'slug' => 'marque-test', 'is_active' => true]);

        $this->modele = VehicleModel::create([
            'brand_id'         => $marque->id,
            'name'             => 'Modele Test',
            'production_start' => 2008,
            'production_end'   => 2016,
            'is_active'        => true,
        ]);

        $this->motorisation = EngineType::create(['code' => 'diesel-test', 'label' => 'Diesel essai']);
        $this->transmission = Drivetrain::create(['code' => 'fwd-test', 'label' => 'Traction essai']);
        $this->finition     = Trim::create(['vehicle_model_id' => $this->modele->id, 'name' => 'Finition essai']);
    }

    /** Le corps minimal que StorePartRequest exige, compatibilites a part. */
    private function corps(array $extra = []): array
    {
        return array_merge([
            // Le SKU est facultatif (voir SkuFacultatifTest) ; il est
            // renseigne ici parce qu'une piece en stock en porte une.
            'sku'              => 'ESSAI-'.bin2hex(random_bytes(4)),
            'name'             => 'Piece essai',
            'part_category_id' => PartCategory::query()->value('id'),
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'selling_price'    => 15000,
        ], $extra);
    }

    /** Une ligne de compatibilite qui renseigne tout ce que l'API accepte. */
    private function ligneComplete(): array
    {
        return [
            'vehicle_model_id' => $this->modele->id,
            'trim_id'          => $this->finition->id,
            'engine_type_id'   => $this->motorisation->id,
            'drivetrain_id'    => $this->transmission->id,
            'engine_code'      => '1ND-TV',
            'year_from'        => 2010,
            'year_to'          => 2014,
            'position'         => 'avant gauche',
            'notes'            => 'verifie sur le vehicule du client',
        ];
    }

    public function test_une_compatibilite_saisie_porte_l_origine_declaree(): void
    {
        $reponse = $this->postJson('/api/parts', $this->corps([
            'fitments' => [['vehicle_model_id' => $this->modele->id]],
        ]));

        $reponse->assertCreated();

        $piece = Part::findOrFail($reponse->json('data.id'));

        $this->assertCount(1, $piece->fitments);
        $this->assertSame(FitmentSource::Declared, $piece->fitments->first()->source);
    }

    /**
     * Le test central : lire, renvoyer, et ne rien perdre.
     *
     * Il echoue des qu'une cle manque a la ressource, parce que la cle
     * manquante ne revient pas dans le PUT et que la colonne retombe a NULL.
     */
    public function test_relire_et_renvoyer_une_compatibilite_ne_perd_rien(): void
    {
        $creation = $this->postJson('/api/parts', $this->corps([
            'fitments' => [$this->ligneComplete()],
        ]))->assertCreated();

        $id = $creation->json('data.id');

        // Ce que l'ecran d'edition recoit.
        $lues = $this->getJson("/api/parts/{$id}")->assertOk()->json('data.fitments');

        $this->assertCount(1, $lues);

        // Ce que l'ecran renvoie : exactement ce qu'il a recu, debarrasse des
        // libelles d'affichage que l'ecriture n'attend pas.
        $renvoyees = array_map(
            fn (array $l) => array_diff_key($l, array_flip(['id', 'vehicle_model', 'brand_id', 'trim', 'engine_type', 'source'])),
            $lues,
        );

        $this->putJson("/api/parts/{$id}", $this->corps(['fitments' => $renvoyees]))->assertOk();

        $apres = Part::findOrFail($id)->fitments()->first();

        foreach ($this->ligneComplete() as $colonne => $attendu) {
            $this->assertSame(
                $attendu,
                $apres->{$colonne},
                "La colonne « {$colonne} » a ete perdue dans l'aller-retour.",
            );
        }
    }

    /** La ressource rend les identifiants, pas seulement les libelles. */
    public function test_la_ressource_rend_de_quoi_reconstruire_la_saisie(): void
    {
        $id = $this->postJson('/api/parts', $this->corps([
            'fitments' => [$this->ligneComplete()],
        ]))->assertCreated()->json('data.id');

        $ligne = $this->getJson("/api/parts/{$id}")->assertOk()->json('data.fitments.0');

        foreach (['vehicle_model_id', 'brand_id', 'trim_id', 'engine_type_id', 'drivetrain_id'] as $cle) {
            $this->assertArrayHasKey($cle, $ligne);
            $this->assertNotNull($ligne[$cle], "« {$cle} » est rendu vide : l'ecran ne peut pas le repositionner.");
        }

        // Le libelle reste, l'ecran s'en sert pour afficher sans recharger
        // tout le referentiel.
        $this->assertNotNull($ligne['vehicle_model']);
        $this->assertSame('declared', $ligne['source']);
    }

    /**
     * Modifier remplace la liste entiere, et c'est le comportement voulu :
     * l'ecran affiche tout, celui qui enregistre repond de ce qu'il a laisse.
     * Le pendant de cette regle est que l'ecran doit charger l'existant —
     * c'est l'affaire du test Flutter.
     */
    public function test_modifier_remplace_la_liste_complete(): void
    {
        $id = $this->postJson('/api/parts', $this->corps([
            'fitments' => [$this->ligneComplete()],
        ]))->assertCreated()->json('data.id');

        $autre = VehicleModel::create([
            'brand_id'         => $this->modele->brand_id,
            'name'             => 'Autre modele',
            'production_start' => 2012,
            'is_active'        => true,
        ]);

        $this->putJson("/api/parts/{$id}", $this->corps([
            'fitments' => [['vehicle_model_id' => $autre->id]],
        ]))->assertOk();

        $restantes = Part::findOrFail($id)->fitments()->get();

        $this->assertCount(1, $restantes);
        $this->assertSame($autre->id, $restantes->first()->vehicle_model_id);
    }

    /**
     * Une ligne fabriquee qu'on garde a l'ecran devient une declaration.
     *
     * C'est une decision, pas un effet de bord : la liste affichee distingue
     * les deux origines, donc laisser une ligne proposee puis enregistrer,
     * c'est en repondre. L'effet utile est qu'une piece se « verifie » au fil
     * des editions, et sort alors de la portee du recalcul.
     */
    public function test_garder_une_ligne_proposee_la_transforme_en_declaration(): void
    {
        $piece = Part::factory()->create();
        $piece->fitments()->create([
            'vehicle_model_id' => $this->modele->id,
            'source'           => FitmentSource::Generated,
        ]);

        $this->putJson("/api/parts/{$piece->id}", $this->corps([
            'name'     => $piece->name,
            'fitments' => [['vehicle_model_id' => $this->modele->id]],
        ]))->assertOk();

        $this->assertSame(FitmentSource::Declared, $piece->fitments()->first()->source);
    }

    // ── Les accessoires, qui ont leur propre controleur ──────────────────

    /**
     * Tout ce qui precede vaut pour les pieces. Les accessoires passent par un
     * autre controleur, une autre requete et une autre ressource — trois
     * endroits ou la regle peut manquer sans que rien ne le dise.
     *
     * Leur table ne porte ni code moteur ni position, mais elle porte la
     * motorisation, la transmission et les notes, que la requete n'acceptait
     * pas : elles etaient donc videes a chaque modification.
     */
    public function test_un_accessoire_fait_l_aller_retour_sans_rien_perdre(): void
    {
        $corps = [
            'sku'           => 'ACC-'.bin2hex(random_bytes(4)),
            'name'          => 'Tapis essai',
            'category'      => 'confort',
            'selling_price' => 9000,
        ];

        $ligne = [
            'vehicle_model_id' => $this->modele->id,
            'trim_id'          => $this->finition->id,
            'engine_type_id'   => $this->motorisation->id,
            'drivetrain_id'    => $this->transmission->id,
            'year_from'        => 2010,
            'year_to'          => 2014,
            'notes'            => 'pose verifie',
        ];

        $creation = $this->postJson('/api/accessories', $corps + ['fitments' => [$ligne]]);
        $creation->assertCreated();

        $id = $creation->json('data.id');

        $lues = $this->getJson("/api/accessories/{$id}")->assertOk()->json('data.fitments');

        $this->assertCount(1, $lues);
        $this->assertSame('declared', $lues[0]['source']);

        $renvoyees = array_map(
            fn (array $l) => array_diff_key($l, array_flip(['id', 'vehicle_model', 'brand_id', 'trim', 'source'])),
            $lues,
        );

        $this->putJson("/api/accessories/{$id}", $corps + ['fitments' => $renvoyees])->assertOk();

        $apres = Accessory::findOrFail($id)->fitments()->first();

        foreach ($ligne as $colonne => $attendu) {
            $this->assertSame(
                $attendu,
                $apres->{$colonne},
                "La colonne « {$colonne} » a ete perdue dans l'aller-retour de l'accessoire.",
            );
        }

        $this->assertSame(FitmentSource::Declared, $apres->source);
    }

    /**
     * Une requete ne choisit pas l'origine de ce qu'elle ecrit.
     *
     * Sans quoi il suffirait d'envoyer `source: generated` pour qu'une saisie
     * devienne effacable par le recalcul — ou l'inverse, qu'une fabrication
     * se fasse passer pour verifiee.
     */
    public function test_la_requete_ne_peut_pas_choisir_l_origine(): void
    {
        $id = $this->postJson('/api/parts', $this->corps([
            'fitments' => [[
                'vehicle_model_id' => $this->modele->id,
                'source'           => FitmentSource::Generated->value,
            ]],
        ]))->assertCreated()->json('data.id');

        $this->assertSame(
            FitmentSource::Declared,
            Part::findOrFail($id)->fitments()->first()->source,
        );
    }
}
