<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use App\Services\VinDecodeService;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Les formes collees Mazda2, Mazda3 et Mazda6, ainsi que l'alias « 3 »,
 * ne doivent plus apparaitre dans le selecteur. Le decodage VIN reconnait
 * encore les formes collees par normalisation, sans ces alias.
 */
class RetirerAliasNomsCollesTest extends TestCase
{
    use RefreshDatabase;

    private function semer(): void
    {
        $this->seed(BrandsSeeder::class);
        Artisan::call('db:seed', ['--class' => VehicleModelsSeeder::class, '--force' => true]);
    }

    private function mazda(): Brand
    {
        return Brand::where('slug', 'mazda')->firstOrFail();
    }

    private function migration()
    {
        return require database_path('migrations/2026_11_17_174500_retirer_les_alias_de_noms_colles.php');
    }


    public function test_le_seeder_ne_pose_plus_les_alias_colles(): void
    {
        $this->semer();
        $marque = $this->mazda();

        $deux = VehicleModel::where('brand_id', $marque->id)->where('slug', 'mazda2-dj')->firstOrFail();
        $this->assertSame('Mazda 2', $deux->name);
        $this->assertSame(['Demio'], $deux->aliases);
        $this->assertNotContains('Mazda2', $deux->aliases ?? []);

        foreach (['mazda3-bk', 'mazda3-bl', 'mazda3-bm', 'mazda3-bp'] as $slug) {
            $modele = VehicleModel::where('brand_id', $marque->id)->where('slug', $slug)->firstOrFail();
            $this->assertSame('Mazda 3', $modele->name);
            $this->assertSame(['Axela'], $modele->aliases);
            $this->assertNotContains('Mazda3', $modele->aliases);
            $this->assertNotContains('3', $modele->aliases);
        }

        foreach (['mazda6-gg', 'mazda6-gh', 'mazda6-gj'] as $slug) {
            $modele = VehicleModel::where('brand_id', $marque->id)->where('slug', $slug)->firstOrFail();
            $this->assertSame('Mazda 6', $modele->name);
            $this->assertSame(['Atenza'], $modele->aliases);
            $this->assertNotContains('Mazda6', $modele->aliases);
        }

        foreach (['mazda5-premacy-cp', 'mazda5-premacy-cr', 'mazda5-premacy-cw'] as $slug) {
            $modele = VehicleModel::where('brand_id', $marque->id)->where('slug', $slug)->firstOrFail();
            $this->assertSame('Mazda 5 / Premacy', $modele->name);
            $this->assertSame($slug, $modele->slug);
        }

        $this->assertSame(0, VehicleModel::where('slug', 'like', 'mazda-5%')->count());
        $this->assertSame(0, VehicleModel::where('brand_id', $marque->id)->where('name', 'like', 'Mazda5%')->count());
    }

    public function test_la_migration_retire_les_alias_colles_et_corrige_mazda_5(): void
    {
        $marqueId = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true])->id;

        $deux = VehicleModel::create([
            'brand_id' => $marqueId, 'name' => 'Mazda 2', 'slug' => 'mazda2-dj',
            'generation' => 'DJ', 'is_active' => true, 'aliases' => ['Mazda2', 'Demio'],
        ]);
        $trois = VehicleModel::create([
            'brand_id' => $marqueId, 'name' => 'Mazda 3', 'slug' => 'mazda3-bp',
            'generation' => 'BP', 'is_active' => true, 'aliases' => ['Mazda3', '3', 'Axela'],
        ]);
        $six = VehicleModel::create([
            'brand_id' => $marqueId, 'name' => 'Mazda 6', 'slug' => 'mazda6-gj',
            'generation' => 'GJ', 'is_active' => true, 'aliases' => ['Mazda6', 'Atenza'],
        ]);
        $cinq = VehicleModel::create([
            'brand_id' => $marqueId, 'name' => 'Mazda5 / Premacy', 'slug' => 'mazda5-premacy-cw',
            'generation' => 'CW', 'body_type' => 'monospace', 'segment' => 'C',
            'production_start' => 2010, 'production_end' => 2018, 'is_active' => true,
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['Demio'], $deux->fresh()->aliases);
        $this->assertSame('Mazda 2', $deux->fresh()->name);
        $this->assertSame($deux->id, $deux->fresh()->id);

        $this->assertSame(['Axela'], $trois->fresh()->aliases);
        $this->assertNotContains('3', $trois->fresh()->aliases);
        $this->assertSame('Mazda 3', $trois->fresh()->name);
        $this->assertSame($trois->id, $trois->fresh()->id);

        $this->assertSame(['Atenza'], $six->fresh()->aliases);
        $this->assertSame(['Mazda 5 / Premacy', 'mazda5-premacy-cw'], [
            $cinq->fresh()->name,
            $cinq->fresh()->slug,
        ]);
        $this->assertSame($cinq->id, $cinq->fresh()->id);
        $this->assertSame(4, VehicleModel::where('brand_id', $marqueId)->count());
    }

    public function test_le_vin_trouve_encore_les_modeles_quand_nhtsa_renvoie_la_forme_collee(): void
    {
        $this->semer();

        $modeleNhtsa = '';
        $annee = '';
        Http::fake(function () use (&$modeleNhtsa, &$annee) {
            return Http::response([
                'Results' => [[
                    'Make' => 'MAZDA',
                    'Model' => $modeleNhtsa,
                    'ModelYear' => $annee,
                    'ErrorCode' => '0',
                    'FuelTypePrimary' => 'Gasoline',
                ]],
            ]);
        });

        $cas = [
            ['Mazda3', '2014', 'Mazda 3', 'mazda3-bm'],
            ['Mazda2', '2018', 'Mazda 2', 'mazda2-dj'],
            ['Mazda6', '2015', 'Mazda 6', 'mazda6-gj'],
            ['Mazda5', '2005', 'Mazda 5 / Premacy', 'mazda5-premacy-cr'],
        ];

        foreach ($cas as [$nhtsa, $millesime, $nom, $slug]) {
            $modeleNhtsa = $nhtsa;
            $annee = $millesime;
            $resultat = app(VinDecodeService::class)->decode('JM1BM1L78E1161371');
            $this->assertSame($nom, $resultat['vehicle_model']['name'], "NHTSA {$nhtsa} {$millesime}");
            $this->assertSame($slug, $resultat['vehicle_model']['slug'], "NHTSA {$nhtsa} {$millesime}");
            $this->assertTrue($resultat['vehicle_model']['covers_year'], "NHTSA {$nhtsa} {$millesime}");
        }
    }

    public function test_la_synchronisation_incrementale_retire_les_alias_colles(): void
    {
        $marqueId = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true])->id;
        $modele = VehicleModel::create([
            'brand_id' => $marqueId,
            'name' => 'Mazda 3',
            'slug' => 'mazda3-bp',
            'generation' => 'BP',
            'is_active' => true,
            'aliases' => ['Mazda3', '3', 'Axela'],
        ]);
        $cinq = VehicleModel::create([
            'brand_id' => $marqueId,
            'name' => 'Mazda5 / Premacy',
            'slug' => 'mazda5-premacy-cw',
            'generation' => 'CW',
            'is_active' => true,
        ]);

        $curseur = $this->getJson('/api/catalog/sync')->assertOk()->json('server_time');

        $this->migration()->up();

        $delta = $this->getJson('/api/catalog/sync?'.http_build_query(['since' => $curseur]))
            ->assertOk()
            ->json('vehicle_models');

        $ligne = collect($delta)->firstWhere('id', $modele->id);
        $this->assertNotNull($ligne);
        $this->assertSame('Mazda 3', $ligne['name']);
        $this->assertSame(['Axela'], $ligne['aliases']);
        $this->assertNotContains('Mazda3', $ligne['aliases']);
        $this->assertNotContains('3', $ligne['aliases']);

        $premacy = collect($delta)->firstWhere('id', $cinq->id);
        $this->assertNotNull($premacy);
        $this->assertSame('Mazda 5 / Premacy', $premacy['name']);
        $this->assertSame('mazda5-premacy-cw', $premacy['slug']);
    }
}
