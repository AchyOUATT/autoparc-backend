<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use App\Support\Toyota90915Yzzd1AnciensModelesImport;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Toyota90915Yzzd1AnciensModelesImportTest extends TestCase
{
    use RefreshDatabase;

    private const NOUVEAUX = [
        'camry-xv30', 'avalon-xx10', 'avalon-xx20', 'highlander-xu20', 'sienna-xl10',
        '4runner-n180', '4runner-n280', 'tundra-i', 'solara-xv20', 'solara-xv30', 'supra-a80',
        'es-xv20', 'es-xv30', 'gs-s160', 'is-xe10', 'rx-xu10',
    ];

    private function marques(): void
    {
        Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota', 'is_active' => true]);
        Brand::firstOrCreate(['slug' => 'lexus'], ['name' => 'Lexus', 'is_active' => true]);
    }

    private function filtre(): Part
    {
        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);

        return Part::create([
            'sku' => '90915-YZZD1',
            'name' => 'Filtre a huile visse 90915-YZZD1',
            'part_category_id' => $categorie->id,
            'manufacturer_reference' => '90915-YZZD1',
            'condition' => 'new',
            'selling_price' => 3000,
            'currency' => 'XOF',
            'stock_quantity' => 15,
            'is_active' => true,
            'is_available' => true,
        ]);
    }

    public function test_les_slugs_attendus_sont_ceux_de_la_constante(): void
    {
        $slugs = array_map(
            fn ($g) => Str::slug($g[1].'-'.$g[2]),
            VehicleModelsSeeder::GENERATIONS_FILTRE_YZZD1,
        );
        $this->assertSame(self::NOUVEAUX, $slugs);
        $this->assertNotContains('sienna-xl20', $slugs);
    }

    public function test_sans_marques_l_import_est_inerte(): void
    {
        $rapport = Toyota90915Yzzd1AnciensModelesImport::run();

        $this->assertSame([], $rapport['models_created']);
        $this->assertNull($rapport['part_id']);
    }

    public function test_il_cree_les_seize_generations_et_les_applications(): void
    {
        $this->marques();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1AnciensModelesImport::run();

        $this->assertSame(self::NOUVEAUX, $rapport['models_created']);
        $this->assertSame(21, $rapport['fitments_inserted']);
        $this->assertSame([], $rapport['fitments_skipped_missing_model']);

        $xu20 = VehicleModel::where('slug', 'highlander-xu20')->firstOrFail();
        $this->assertSame('Highlander', $xu20->name);
        $this->assertSame('XU20', $xu20->generation);
        $this->assertSame(2000, (int) $xu20->production_start);
        $this->assertSame(2007, (int) $xu20->production_end);
        $this->assertSame('lexus', VehicleModel::where('slug', 'rx-xu10')->firstOrFail()->brand->slug);

        $lignes = $piece->fitments()->with('vehicleModel')->get()
            ->map(fn ($f) => $f->vehicleModel->slug.'|'.$f->engine_code.'|'.$f->year_from.'|'.$f->year_to)
            ->sort()->values()->all();
        $this->assertContains('highlander-xu20|1MZ-FE|2001|2003', $lignes);
        $this->assertContains('highlander-xu20|3MZ-FE|2004|2007', $lignes);
        $this->assertContains('camry-xv30|3MZ-FE|2004|2006', $lignes);
        $this->assertContains('es-xv30|3MZ-FE|2004|2006', $lignes);
        $this->assertContains('tundra-i|5VZ-FE|2000|2004', $lignes);

        foreach ($piece->fitments as $ligne) {
            $this->assertNotNull($ligne->engine_code);
            $this->assertSame('declared', $ligne->source->value ?? $ligne->source);
            $this->assertStringContainsString('https://', (string) $ligne->notes);
            $this->assertStringNotContainsString('@', (string) $ligne->notes);
        }
        $piece->refresh();
        $this->assertEquals(3000, $piece->selling_price);
        $this->assertSame(15, $piece->stock_quantity);
    }

    public function test_les_lignes_toyota_citent_le_catalogue_officiel_et_lexus_les_revendeurs(): void
    {
        $this->marques();
        $piece = $this->filtre();
        Toyota90915Yzzd1AnciensModelesImport::run();

        foreach ($piece->fitments()->with('vehicleModel.brand')->get() as $ligne) {
            if ($ligne->vehicleModel->brand->slug === 'toyota') {
                $this->assertStringContainsString('06_OilFilters2025_hi.pdf', (string) $ligne->notes);
            } else {
                $this->assertStringContainsString('autohausaz.com', (string) $ligne->notes);
            }
        }
    }

    public function test_une_ligne_existante_n_est_jamais_modifiee(): void
    {
        $this->marques();
        $toyota = Brand::where('slug', 'toyota')->value('id');
        $existant = VehicleModel::create([
            'brand_id' => $toyota, 'slug' => 'camry-xv30', 'name' => 'Camry',
            'generation' => 'XV30', 'production_start' => 2002, 'production_end' => 2006, 'is_active' => true,
        ]);

        $rapport = Toyota90915Yzzd1AnciensModelesImport::run();

        $this->assertNotContains('camry-xv30', $rapport['models_created']);
        $this->assertSame(2002, (int) $existant->fresh()->production_start);
    }

    public function test_rejouer_est_idempotent(): void
    {
        $this->marques();
        $piece = $this->filtre();

        Toyota90915Yzzd1AnciensModelesImport::run();
        $modeles = VehicleModel::count();
        $rapport = Toyota90915Yzzd1AnciensModelesImport::run();

        $this->assertSame([], $rapport['models_created']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(21, $rapport['fitments_already_present']);
        $this->assertSame($modeles, VehicleModel::count());
        $this->assertSame(21, $piece->fitments()->count());
    }

    public function test_le_seeder_pose_les_memes_generations(): void
    {
        $this->marques();
        $this->seed(VehicleModelsSeeder::class);

        foreach (self::NOUVEAUX as $slug) {
            $this->assertTrue(VehicleModel::where('slug', $slug)->exists(), $slug);
        }
    }

    public function test_la_migration_rejoue_l_import(): void
    {
        $this->marques();
        $piece = $this->filtre();

        $migration = require database_path('migrations/2026_11_26_160000_ajouter_les_generations_anciennes_du_filtre_90915_yzzd1.php');
        $migration->up();
        $migration->up();

        $this->assertSame(21, $piece->fitments()->count());
    }
}
