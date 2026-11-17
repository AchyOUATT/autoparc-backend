<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use App\Services\VinDecodeService;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le modele figurait au catalogue sous le nom colle « Mazda3 ».
 * Il doit s'afficher comme « Mazda 3 », garder ses slugs historiques, et
 * rester trouvable par le decodage VIN, qui recoit « Mazda3 » de NHTSA.
 */
class Mazda3NomCommercialTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'mazda3-bl' => ['BL', 2008, 2013],
        'mazda3-bm' => ['BM', 2013, 2018],
        'mazda3-bp' => ['BP', 2018, null],
    ];

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
        return require database_path('migrations/2026_11_17_171500_nommer_le_modele_mazda_3.php');
    }

    public function test_le_seeder_nomme_mazda_3_sans_changer_les_slugs(): void
    {
        $this->semer();

        foreach (self::SLUGS as $slug => [$generation, $debut, $fin]) {
            $modele = VehicleModel::where('brand_id', $this->mazda()->id)->where('slug', $slug)->first();
            $this->assertNotNull($modele, "{$slug} absent");
            $this->assertSame('Mazda 3', $modele->name);
            $this->assertSame($generation, $modele->generation);
            $this->assertSame($debut, (int) $modele->production_start);
            $this->assertSame($fin, $modele->production_end === null ? null : (int) $modele->production_end);
            $this->assertTrue((bool) $modele->is_active);
            $this->assertSame(['Mazda3', '3'], $modele->aliases);
        }

        $this->assertSame(0, VehicleModel::where('slug', 'like', 'mazda-3%')->count());
        $this->assertSame(3, VehicleModel::where('brand_id', $this->mazda()->id)->where('name', 'Mazda 3')->count());
        $this->assertSame(0, VehicleModel::where('brand_id', $this->mazda()->id)->where('name', 'Mazda3')->count());
    }

    public function test_rejouer_le_seeder_est_idempotent_et_preserve_les_identifiants(): void
    {
        $this->semer();

        $avant = VehicleModel::where('brand_id', $this->mazda()->id)
            ->whereIn('slug', array_keys(self::SLUGS))
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'generation', 'aliases', 'production_start', 'production_end'])
            ->toArray();
        $this->assertCount(3, $avant);

        $total = VehicleModel::count();
        $this->semer();
        $this->semer();

        $this->assertSame($total, VehicleModel::count());
        $this->assertSame($avant, VehicleModel::where('brand_id', $this->mazda()->id)
            ->whereIn('slug', array_keys(self::SLUGS))
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'generation', 'aliases', 'production_start', 'production_end'])
            ->toArray());
    }

    public function test_la_migration_renomme_les_fiches_existantes_sans_en_creer(): void
    {
        $marqueId = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true])->id;
        $ids = [];
        foreach (self::SLUGS as $slug => [$generation, $debut, $fin]) {
            $ids[$slug] = VehicleModel::create([
                'brand_id' => $marqueId,
                'name' => 'Mazda3',
                'slug' => $slug,
                'generation' => $generation,
                'body_type' => 'compacte',
                'segment' => 'C',
                'production_start' => $debut,
                'production_end' => $fin,
                'is_active' => true,
            ])->id;
        }

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(3, VehicleModel::where('brand_id', $marqueId)->count());
        foreach ($ids as $slug => $id) {
            $modele = VehicleModel::findOrFail($id);
            $this->assertSame($slug, $modele->slug);
            $this->assertSame('Mazda 3', $modele->name);
            $this->assertSame(['Mazda3', '3'], $modele->aliases);
        }
    }

    public function test_la_migration_conserve_un_alias_deja_present(): void
    {
        $marqueId = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true])->id;
        $modele = VehicleModel::create([
            'brand_id' => $marqueId,
            'name' => 'Mazda3',
            'slug' => 'mazda3-bp',
            'generation' => 'BP',
            'is_active' => true,
            'aliases' => ['Axela'],
        ]);

        $this->migration()->up();

        $modele->refresh();
        $this->assertSame('Mazda 3', $modele->name);
        $this->assertSame(['Axela', 'Mazda3', '3'], $modele->aliases);
    }

    public function test_le_decodage_vin_trouve_mazda_3_quand_nhtsa_renvoie_mazda3(): void
    {
        $this->semer();

        Http::fake([
            'vpic.nhtsa.dot.gov/*' => Http::response([
                'Results' => [[
                    'Make' => 'MAZDA',
                    'Model' => 'Mazda3',
                    'ModelYear' => '2014',
                    'ErrorCode' => '0',
                    'FuelTypePrimary' => 'Gasoline',
                ]],
            ]),
        ]);

        $resultat = app(VinDecodeService::class)->decode('JM1BM1L78E1161371');

        $this->assertNotNull($resultat['vehicle_model']);
        $this->assertSame('Mazda 3', $resultat['vehicle_model']['name']);
        $this->assertSame('mazda3-bm', $resultat['vehicle_model']['slug']);
        $this->assertSame('BM', $resultat['vehicle_model']['generation']);
        $this->assertTrue($resultat['vehicle_model']['covers_year']);
    }

    public function test_la_synchronisation_incrementale_expose_le_nouveau_nom(): void
    {
        $marqueId = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true])->id;
        $modele = VehicleModel::create([
            'brand_id' => $marqueId,
            'name' => 'Mazda3',
            'slug' => 'mazda3-bp',
            'generation' => 'BP',
            'is_active' => true,
        ]);

        $curseur = $this->getJson('/api/catalog/sync')->assertOk()->json('server_time');
        $this->assertSame('Mazda3', collect($this->getJson('/api/catalog/sync')->assertOk()->json('vehicle_models'))
            ->firstWhere('id', $modele->id)['name']);

        $this->migration()->up();

        $delta = $this->getJson('/api/catalog/sync?'.http_build_query(['since' => $curseur]))
            ->assertOk()
            ->json('vehicle_models');

        $ligne = collect($delta)->firstWhere('id', $modele->id);
        $this->assertNotNull($ligne, 'Le delta doit renvoyer la fiche dont le nom a change.');
        $this->assertSame($modele->id, $ligne['id']);
        $this->assertSame('Mazda 3', $ligne['name']);
        $this->assertSame(['Mazda3', '3'], $ligne['aliases']);
    }
}
