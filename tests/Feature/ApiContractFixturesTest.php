<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Contrat entre l'API et l'application Flutter.
 *
 * Le defaut le plus couteux du projet n'etait pas une regle metier fausse mais
 * une difference de type : Laravel serialise les colonnes `decimal` en chaines
 * de caracteres, l'application les lisait en `num?`. Un seul champ mal type a
 * fait tomber le catalogue entier — pas une fiche, pas un ecran : tout.
 *
 * Ce test enregistre de vraies reponses d'API dans tests/Fixtures/api/. Les
 * memes fichiers sont copies dans le depot mobile, ou un test verifie que
 * chaque modele sait les lire. Les deux depots parlent ainsi de la meme charge
 * utile, produite par le vrai serialiseur plutot que redigee a la main — une
 * charge ecrite a la main aurait porte des nombres, et n'aurait jamais revele
 * le probleme.
 *
 * Regenerer apres toute modification d'une ressource :
 *   UPDATE_FIXTURES=1 php artisan test --filter=ApiContractFixturesTest
 *   cp tests/Fixtures/api/*.json ../autoparc-mobile/test/fixtures/
 */
class ApiContractFixturesTest extends TestCase
{
    use RefreshDatabase;

    private const DOSSIER = 'tests/Fixtures/api';

    protected function setUp(): void
    {
        parent::setUp();

        // Limite le bruit dans les diffs quand on regenere.
        fake()->seed(2026);

        if (! is_dir(base_path(self::DOSSIER))) {
            mkdir(base_path(self::DOSSIER), 0777, true);
        }
    }

    /**
     * N'ecrit que sur demande explicite.
     *
     * Les factories tirent des valeurs au hasard : ecrire a chaque execution
     * salissait l'arbre de travail apres le moindre `php artisan test`. Les
     * assertions, elles, tournent toujours — c'est la partie qui protege.
     */
    private function capture(string $nom, TestResponse $response): array
    {
        $response->assertOk();

        if (env('UPDATE_FIXTURES')) {
            file_put_contents(
                base_path(self::DOSSIER."/{$nom}.json"),
                json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        return $response->json();
    }

    public function test_les_charges_utiles_du_catalogue_sont_capturees(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $vehicule = Vehicle::factory()->create([
            'sale_price'           => 2450000,
            'consumption_combined' => 9.4,
            'deal_type'            => 'good_deal',
        ]);
        $this->seed(\Database\Seeders\VehicleFeaturesSeeder::class);

        // ── Liste des vehicules ──────────────────────────────────────────────
        $liste = $this->capture('vehicles_list', $this->getJson('/api/catalog/vehicles?per_page=3'));
        $this->assertNotEmpty($liste['data'], 'La liste alimente la grille du catalogue.');

        // Le champ qui avait tout casse : serialise en chaine, lu en nombre.
        $this->assertIsString(
            $liste['data'][0]['commercial']['sale_price'],
            'Un prix `decimal` sort en chaine : la fixture doit le refleter, sinon elle ne protege de rien.',
        );

        // ── Fiche vehicule ───────────────────────────────────────────────────
        $fiche = $this->capture('vehicle_detail', $this->getJson("/api/catalog/vehicles/{$vehicule->id}"));

        foreach (['identity', 'commercial', 'technical', 'features'] as $bloc) {
            $this->assertArrayHasKey($bloc, $fiche['data'], "La fiche doit porter le bloc « {$bloc} ».");
        }
        $this->assertArrayHasKey('is_full_option', $fiche['data']);
        $this->assertArrayHasKey('optional_feature_count', $fiche['data']);

        // ── Pieces et accessoires ────────────────────────────────────────────
        $categorie = PartCategory::create(['name' => 'Filtres', 'slug' => 'filtres-fixture']);
        $piece = Part::create([
            'sku' => 'PRT-FIXTURE', 'name' => 'Filtre a huile',
            'part_category_id' => $categorie->id,
            'manufacturer_id'  => Manufacturer::create(['name' => 'Bosch fixture'])->id,
            'type' => 'aftermarket', 'condition' => 'new',
            'cost_price' => 2000, 'selling_price' => 4500, 'currency' => 'XOF',
            'vat_rate' => 18.00, 'stock_quantity' => 7, 'is_active' => true, 'is_available' => true,
        ]);

        $this->capture('parts_list', $this->getJson('/api/catalog/parts?per_page=3'));
        $this->capture('part_detail', $this->getJson("/api/catalog/parts/{$piece->id}"));

        \App\Models\Accessory::factory()->create();
        $this->capture('accessories_list', $this->getJson('/api/catalog/accessories?per_page=3'));

        // ── Referentiels ─────────────────────────────────────────────────────
        $refs = $this->capture('catalog_sync', $this->getJson('/api/catalog/sync'));
        $this->assertArrayHasKey('server_time', $refs, 'Le curseur du mode incremental.');
        foreach (['brands', 'vehicle_models', 'colors', 'features', 'locations'] as $table) {
            $this->assertArrayHasKey($table, $refs);
        }

        $this->capture('part_categories', $this->getJson('/api/catalog/part-categories'));
        $this->capture('countries', $this->getJson('/api/catalog/countries'));
    }

    public function test_la_charge_utile_du_garage_est_capturee(): void
    {
        $user  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'client-fixture']);
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota-fixture', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Corolla', 'slug' => 'corolla-fixture', 'is_active' => true,
        ]);

        OwnedVehicle::create([
            'user_id'                     => $user->id,
            'brand_id'                    => $brand->id,
            'vehicle_model_id'            => $model->id,
            'manufacturing_year'          => 2018,
            'nickname'                    => 'La Corolla',
            'mileage_km'                  => 96000,
            'technical_inspection_expiry' => '2027-03-01',
            'insurance_expiry'            => '2027-01-15',
            'last_service_mileage_km'     => 90000,
            'service_interval_km'         => 10000,
        ]);

        $garage = $this->capture(
            'my_vehicles',
            $this->actingAsClient($user)->getJson('/api/my/vehicles'),
        );

        $this->assertArrayHasKey('deadlines', $garage['data'][0], 'Les echeances alimentent les rappels d\'entretien.');
    }
}
