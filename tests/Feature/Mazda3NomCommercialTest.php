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
 * Mazda 2, 3 et 6 figuraient au catalogue sous le nom colle.
 * Ils doivent s'afficher avec l'espace, garder leurs slugs historiques,
 * porter les noms de marche japonais en alias, et rester trouvables
 * par le decodage VIN (NHTSA renvoie « Mazda3 », « Mazda2 », « Mazda6 »).
 */
class Mazda3NomCommercialTest extends TestCase
{
    use RefreshDatabase;

    private const MAZDA_3 = [
        'mazda3-bk' => ['BK', 2003, 2008],
        'mazda3-bl' => ['BL', 2008, 2013],
        'mazda3-bm' => ['BM', 2013, 2018],
        'mazda3-bp' => ['BP', 2018, null],
    ];

    private const MAZDA_6 = [
        'mazda6-gg' => ['GG', 2002, 2008],
        'mazda6-gh' => ['GH', 2008, 2012],
        'mazda6-gj' => ['GJ', 2012, 2024],
    ];

    private const ALIAS_MAZDA_3 = ['Axela'];

    private const ALIAS_MAZDA_2 = ['Demio'];

    private const ALIAS_MAZDA_6 = ['Atenza'];

    /** Alias poses par la migration 2026_11_17_171500, avant le retrait des formes collees. */
    private const ALIAS_INITIAUX_MAZDA_3 = ['Mazda3', '3', 'Axela'];

    private const ALIAS_INITIAUX_MAZDA_2 = ['Mazda2', 'Demio'];

    private const ALIAS_INITIAUX_MAZDA_6 = ['Mazda6', 'Atenza'];

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

    public function test_le_seeder_nomme_les_mazda_avec_espace_sans_changer_les_slugs(): void
    {
        $this->semer();
        $marque = $this->mazda();

        foreach (self::MAZDA_3 as $slug => [$generation, $debut, $fin]) {
            $modele = VehicleModel::where('brand_id', $marque->id)->where('slug', $slug)->first();
            $this->assertNotNull($modele, "{$slug} absent");
            $this->assertSame('Mazda 3', $modele->name);
            $this->assertSame($generation, $modele->generation);
            $this->assertSame('compacte', $modele->body_type);
            $this->assertSame('C', $modele->segment);
            $this->assertSame($debut, (int) $modele->production_start);
            $this->assertSame($fin, $modele->production_end === null ? null : (int) $modele->production_end);
            $this->assertTrue((bool) $modele->is_active);
            $this->assertSame(self::ALIAS_MAZDA_3, $modele->aliases);
        }

        $deux = VehicleModel::where('brand_id', $marque->id)->where('slug', 'mazda2-dj')->first();
        $this->assertNotNull($deux);
        $this->assertSame('Mazda 2', $deux->name);
        $this->assertSame('DJ', $deux->generation);
        $this->assertSame('citadine', $deux->body_type);
        $this->assertSame('B', $deux->segment);
        $this->assertSame(2014, (int) $deux->production_start);
        $this->assertNull($deux->production_end);
        $this->assertSame(self::ALIAS_MAZDA_2, $deux->aliases);

        foreach (self::MAZDA_6 as $slug => [$generation, $debut, $fin]) {
            $modele = VehicleModel::where('brand_id', $marque->id)->where('slug', $slug)->first();
            $this->assertNotNull($modele, "{$slug} absent");
            $this->assertSame('Mazda 6', $modele->name);
            $this->assertSame($generation, $modele->generation);
            $this->assertSame('berline', $modele->body_type);
            $this->assertSame('D', $modele->segment);
            $this->assertSame($debut, (int) $modele->production_start);
            $this->assertSame($fin, (int) $modele->production_end);
            $this->assertSame(self::ALIAS_MAZDA_6, $modele->aliases);
        }

        $this->assertSame(0, VehicleModel::where('slug', 'like', 'mazda-2%')->orWhere('slug', 'like', 'mazda-3%')->orWhere('slug', 'like', 'mazda-6%')->count());
        $this->assertSame(0, VehicleModel::where('brand_id', $marque->id)->whereIn('name', ['Mazda2', 'Mazda3', 'Mazda6'])->count());
        $this->assertSame(0, VehicleModel::where('brand_id', $marque->id)->whereIn('name', ['Demio', 'Atenza', 'Axela'])->count());
        $this->assertSame(4, VehicleModel::where('brand_id', $marque->id)->where('name', 'Mazda 3')->count());
    }

    public function test_rejouer_le_seeder_est_idempotent_et_preserve_les_identifiants(): void
    {
        $this->semer();

        $slugs = array_merge(array_keys(self::MAZDA_3), ['mazda2-dj'], array_keys(self::MAZDA_6));
        $avant = VehicleModel::where('brand_id', $this->mazda()->id)
            ->whereIn('slug', $slugs)
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'generation', 'aliases', 'production_start', 'production_end'])
            ->toArray();
        $this->assertCount(8, $avant);

        $total = VehicleModel::count();
        $this->semer();
        $this->semer();

        $this->assertSame($total, VehicleModel::count());
        $this->assertSame($avant, VehicleModel::where('brand_id', $this->mazda()->id)
            ->whereIn('slug', $slugs)
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'generation', 'aliases', 'production_start', 'production_end'])
            ->toArray());
    }

    public function test_la_migration_renomme_les_fiches_et_ajoute_la_generation_bk(): void
    {
        $marqueId = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true])->id;
        $ids = [];
        $ids['mazda2-dj'] = VehicleModel::create([
            'brand_id' => $marqueId, 'name' => 'Mazda2', 'slug' => 'mazda2-dj',
            'generation' => 'DJ', 'body_type' => 'citadine', 'segment' => 'B',
            'production_start' => 2014, 'is_active' => true,
        ])->id;
        foreach ([
            'mazda3-bl' => ['BL', 2008, 2013],
            'mazda3-bm' => ['BM', 2013, 2018],
            'mazda3-bp' => ['BP', 2018, null],
        ] as $slug => [$generation, $debut, $fin]) {
            $ids[$slug] = VehicleModel::create([
                'brand_id' => $marqueId, 'name' => 'Mazda3', 'slug' => $slug,
                'generation' => $generation, 'body_type' => 'compacte', 'segment' => 'C',
                'production_start' => $debut, 'production_end' => $fin, 'is_active' => true,
            ])->id;
        }
        foreach (self::MAZDA_6 as $slug => [$generation, $debut, $fin]) {
            $ids[$slug] = VehicleModel::create([
                'brand_id' => $marqueId, 'name' => 'Mazda6', 'slug' => $slug,
                'generation' => $generation, 'body_type' => 'berline', 'segment' => 'D',
                'production_start' => $debut, 'production_end' => $fin, 'is_active' => true,
            ])->id;
        }

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('Mazda 2', VehicleModel::findOrFail($ids['mazda2-dj'])->name);
        $this->assertSame(self::ALIAS_INITIAUX_MAZDA_2, VehicleModel::findOrFail($ids['mazda2-dj'])->aliases);

        foreach (['mazda3-bl', 'mazda3-bm', 'mazda3-bp'] as $slug) {
            $modele = VehicleModel::findOrFail($ids[$slug]);
            $this->assertSame($slug, $modele->slug);
            $this->assertSame('Mazda 3', $modele->name);
            $this->assertSame(self::ALIAS_INITIAUX_MAZDA_3, $modele->aliases);
        }

        $bk = VehicleModel::where('brand_id', $marqueId)->where('slug', 'mazda3-bk')->first();
        $this->assertNotNull($bk);
        $this->assertSame('Mazda 3', $bk->name);
        $this->assertSame('BK', $bk->generation);
        $this->assertSame('compacte', $bk->body_type);
        $this->assertSame('C', $bk->segment);
        $this->assertSame(2003, (int) $bk->production_start);
        $this->assertSame(2008, (int) $bk->production_end);
        $this->assertTrue((bool) $bk->is_active);
        $this->assertSame(self::ALIAS_INITIAUX_MAZDA_3, $bk->aliases);

        foreach ($ids as $slug => $id) {
            if (str_starts_with($slug, 'mazda6-')) {
                $modele = VehicleModel::findOrFail($id);
                $this->assertSame('Mazda 6', $modele->name);
                $this->assertSame($slug, $modele->slug);
                $this->assertSame(self::ALIAS_INITIAUX_MAZDA_6, $modele->aliases);
            }
        }

        $this->assertSame(8, VehicleModel::where('brand_id', $marqueId)->count());
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

    public function test_un_mazda_3_de_2004_ressort_sur_la_generation_bk(): void
    {
        $this->semer();

        Http::fake([
            'vpic.nhtsa.dot.gov/*' => Http::response([
                'Results' => [[
                    'Make' => 'MAZDA',
                    'Model' => 'Mazda3',
                    'ModelYear' => '2004',
                    'ErrorCode' => '0',
                    'FuelTypePrimary' => 'Gasoline',
                ]],
            ]),
        ]);

        $resultat = app(VinDecodeService::class)->decode('JM1BM1L78E1161371');

        $this->assertNotNull($resultat['vehicle_model']);
        $this->assertSame('mazda3-bk', $resultat['vehicle_model']['slug']);
        $this->assertSame('BK', $resultat['vehicle_model']['generation']);
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
        $this->assertSame(self::ALIAS_INITIAUX_MAZDA_3, $ligne['aliases']);

        $bk = collect($delta)->firstWhere('slug', 'mazda3-bk');
        $this->assertNotNull($bk, 'Le delta doit aussi exposer la generation BK ajoutee.');
        $this->assertSame('Mazda 3', $bk['name']);
        $this->assertSame(self::ALIAS_INITIAUX_MAZDA_3, $bk['aliases']);
        $this->assertSame(2003, $bk['production_start']);
        $this->assertSame(2008, $bk['production_end']);
    }
}
