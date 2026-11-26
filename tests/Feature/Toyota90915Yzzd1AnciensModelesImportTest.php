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

        // Années de FABRICATION tirées de l'EPC Toyota (revue du 26/11/2026),
        // châssis dans `chassis_code`.
        $lignes = $piece->fitments()->with('vehicleModel')->get()
            ->map(fn ($f) => $f->vehicleModel->slug.'|'.$f->engine_code.'|'.$f->year_from.'|'.$f->year_to.'|'.$f->chassis_code)
            ->sort()->values()->all();
        $this->assertSame([
            '4runner-n180|3RZ-FE|1995|2000|RZN180/RZN185',
            '4runner-n180|5VZ-FE|1995|2002|VZN180/VZN185',
            '4runner-n280|2TR-FE|2009|2011|TRN280/TRN285',
            'avalon-xx10|1MZ-FE|1994|1999|MCX10',
            'avalon-xx20|1MZ-FE|1999|2004|MCX20',
            'camry-xv30|1MZ-FE|2001|2006|MCV30',
            'camry-xv30|3MZ-FE|2003|2006|MCV31',
            'es-xv20|1MZ-FE|1996|2001|MCV20',
            'es-xv30|1MZ-FE|2001|2003|MCV30',
            'es-xv30|3MZ-FE|2003|2006|MCV31',
            'gs-s160|2JZ-GE|1997|2004|JZS160',
            'highlander-xu20|1MZ-FE|2000|2003|MCU20/MCU25',
            'highlander-xu20|3MZ-FE|2003|2007|MCU23/MCU28/MHU23/MHU28',
            'is-xe10|2JZ-GE|2000|2005|JCE10',
            'rx-xu10|1MZ-FE|1998|2003|MCU10/MCU15',
            'sienna-xl10|1MZ-FE|1997|2002|MCL10',
            'solara-xv20|1MZ-FE|1998|2002|MCV20',
            'solara-xv30|3MZ-FE|2003|2008|MCV31',
            'supra-a80|2JZ-GE|1993|1998|JZA80',
            'supra-a80|2JZ-GTE|1993|1998|JZA80',
            'tundra-i|5VZ-FE|1999|2004|VCK30/VCK40',
        ], $lignes);

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

    public function test_chaque_ligne_cite_l_epc_son_chassis_et_son_marche(): void
    {
        $this->marques();
        $piece = $this->filtre();
        Toyota90915Yzzd1AnciensModelesImport::run();

        foreach ($piece->fitments()->with('vehicleModel.brand')->get() as $ligne) {
            $notes = (string) $ligne->notes;
            $this->assertStringContainsString('Période EPC Toyota', $notes);
            $this->assertStringContainsString('schéma 15-02', $notes);
            $this->assertStringContainsString('marché', $notes);
            foreach (explode('/', (string) $ligne->chassis_code) as $chassis) {
                $this->assertStringContainsString($chassis, $notes);
            }
            if ($ligne->vehicleModel->brand->slug === 'toyota') {
                $this->assertStringContainsString('06_OilFilters2025_hi.pdf', $notes);
            } else {
                $this->assertStringContainsString('ne couvre pas Lexus', $notes);
                $this->assertStringNotContainsString('autohausaz.com', $notes);
            }
        }
    }

    public function test_aucune_ligne_ne_depasse_les_bornes_de_production_de_sa_generation(): void
    {
        $this->marques();
        $piece = $this->filtre();
        Toyota90915Yzzd1AnciensModelesImport::run();

        foreach ($piece->fitments()->with('vehicleModel')->get() as $ligne) {
            $modele = $ligne->vehicleModel;
            $this->assertGreaterThanOrEqual($modele->production_start, $ligne->year_from, $modele->slug.' '.$ligne->engine_code);
            $this->assertLessThanOrEqual($modele->production_end, $ligne->year_to, $modele->slug.' '.$ligne->engine_code);
        }
    }

    public function test_le_2tr_fe_du_4runner_n280_ne_couvre_pas_toute_la_generation(): void
    {
        $this->marques();
        $piece = $this->filtre();
        Toyota90915Yzzd1AnciensModelesImport::run();

        $n280 = VehicleModel::where('slug', '4runner-n280')->firstOrFail();
        $ligne = $piece->fitments()->where('vehicle_model_id', $n280->id)->sole();
        $this->assertSame([2009, 2011], [$ligne->year_from, $ligne->year_to]);
        $this->assertSame(2024, (int) $n280->production_end, 'La génération, elle, va jusqu\'en 2024.');
        $this->assertStringContainsString('TRN280L 11/2009-08/2011', (string) $ligne->notes);
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
