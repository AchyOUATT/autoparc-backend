<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use App\Support\Toyota90915Yzzd1Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Toyota90915Yzzd1ImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{brand: string, slug: string, name: string, generation: ?string}> */
    private const MODELES = [
        ['brand' => 'toyota', 'slug' => 'camry-xv10', 'name' => 'Camry', 'generation' => 'XV10'],
        ['brand' => 'toyota', 'slug' => 'camry-xv20', 'name' => 'Camry', 'generation' => 'XV20'],
        ['brand' => 'toyota', 'slug' => 'tacoma-i', 'name' => 'Tacoma', 'generation' => 'I'],
        ['brand' => 'toyota', 'slug' => 'highlander', 'name' => 'Highlander', 'generation' => null],
        ['brand' => 'lexus', 'slug' => 'rx-xu30', 'name' => 'RX', 'generation' => 'XU30'],
        // Modèles présents en production mais volontairement sans ligne.
        ['brand' => 'toyota', 'slug' => 'tacoma-ii', 'name' => 'Tacoma', 'generation' => 'II'],
        ['brand' => 'toyota', 'slug' => 'tacoma', 'name' => 'Tacoma', 'generation' => null],
        ['brand' => 'toyota', 'slug' => 'sienna', 'name' => 'Sienna', 'generation' => null],
        ['brand' => 'toyota', 'slug' => 'avalon', 'name' => 'Avalon', 'generation' => null],
    ];

    private function catalogue(): void
    {
        foreach (['toyota' => 'Toyota', 'lexus' => 'Lexus'] as $slug => $nom) {
            Brand::firstOrCreate(['slug' => $slug], ['name' => $nom, 'is_active' => true]);
        }
        foreach (self::MODELES as $modele) {
            VehicleModel::firstOrCreate(
                ['brand_id' => Brand::where('slug', $modele['brand'])->value('id'), 'slug' => $modele['slug']],
                ['name' => $modele['name'], 'generation' => $modele['generation'], 'is_active' => true],
            );
        }
    }

    private function filtre(string $sku = Toyota90915Yzzd1Import::SKU): Part
    {
        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);

        return Part::create([
            'sku' => $sku,
            'name' => 'Filtre a huile visse '.$sku,
            'part_category_id' => $categorie->id,
            'manufacturer_reference' => $sku,
            'condition' => 'new',
            'selling_price' => 3000,
            'currency' => 'XOF',
            'stock_quantity' => 15,
            'is_active' => true,
            'is_available' => true,
        ]);
    }

    public function test_sans_article_l_import_est_inerte(): void
    {
        $this->catalogue();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertNull($rapport['part_id']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(0, Part::where('sku', Toyota90915Yzzd1Import::SKU)->count());
    }

    public function test_il_pose_les_applications_sourcees_sans_toucher_au_commerce(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertSame(8, $rapport['fitments_inserted']);
        $this->assertSame([], $rapport['fitments_skipped_missing_model']);

        $piece->refresh();
        $this->assertEquals(3000, $piece->selling_price);
        $this->assertSame(15, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertSame('Filtre a huile visse 90915-YZZD1', $piece->name);

        $lignes = $piece->fitments()->with('vehicleModel')->get()
            ->map(fn ($f) => $f->vehicleModel->slug.'|'.$f->engine_code.'|'.$f->year_from.'|'.$f->year_to)
            ->sort()->values()->all();
        $this->assertSame([
            'camry-xv10|1MZ-FE|1992|1996',
            'camry-xv10|3VZ-FE|1992|1996',
            'camry-xv20|1MZ-FE|1997|2001',
            'highlander|3MZ-FE|2008|2010',
            'rx-xu30|3MZ-FE|2004|2008',
            'tacoma-i|2RZ-FE|1995|2004',
            'tacoma-i|3RZ-FE|1995|2004',
            'tacoma-i|5VZ-FE|1995|2004',
        ], $lignes);

        foreach ($piece->fitments as $ligne) {
            $this->assertSame('declared', $ligne->source->value ?? $ligne->source);
            $this->assertNotNull($ligne->engine_code);
            $this->assertNull($ligne->chassis_code);
            $this->assertStringContainsString('https://', (string) $ligne->notes);
            $this->assertStringNotContainsString('@', (string) $ligne->notes);
        }
    }

    public function test_les_modeles_ambigus_ne_recoivent_aucune_ligne(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        Toyota90915Yzzd1Import::run();

        $exclus = VehicleModel::whereIn('slug', ['tacoma-ii', 'tacoma', 'sienna', 'avalon'])->pluck('id');
        $this->assertSame(0, $piece->fitments()->whereIn('vehicle_model_id', $exclus)->count());
    }

    public function test_rejouer_est_idempotent_et_respecte_les_lignes_des_autres_articles(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        $autre = $this->filtre('90915-10001');
        $xv10 = VehicleModel::where('slug', 'camry-xv10')->firstOrFail();
        $autre->fitments()->create([
            'vehicle_model_id' => $xv10->id,
            'engine_code' => '5S-FE',
            'year_from' => 1992,
            'year_to' => 1996,
            'source' => 'declared',
        ]);

        Toyota90915Yzzd1Import::run();
        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(8, $rapport['fitments_already_present']);
        $this->assertSame(8, $piece->fitments()->count());
        $this->assertSame(1, $autre->fitments()->count());
        $this->assertSame('5S-FE', $autre->fitments()->first()->engine_code);
    }

    public function test_une_ligne_deja_saisie_a_la_main_n_est_pas_reecrite(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        $hl = VehicleModel::where('slug', 'highlander')->firstOrFail();
        $piece->fitments()->create([
            'vehicle_model_id' => $hl->id,
            'engine_code' => '3MZ-FE',
            'year_from' => 2008,
            'year_to' => 2010,
            'notes' => 'Saisie par le commerçant',
            'source' => 'declared',
        ]);

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(7, $rapport['fitments_inserted']);
        $this->assertSame(1, $rapport['fitments_already_present']);
        $this->assertSame(
            'Saisie par le commerçant',
            $piece->fitments()->where('vehicle_model_id', $hl->id)->firstOrFail()->notes,
        );
    }

    public function test_un_modele_absent_est_ignore_sans_erreur(): void
    {
        $this->catalogue();
        VehicleModel::where('slug', 'rx-xu30')->delete();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(7, $rapport['fitments_inserted']);
        $this->assertSame(['rx-xu30'], $rapport['fitments_skipped_missing_model']);
        $this->assertSame(7, $piece->fitments()->count());
    }

    public function test_la_migration_rejoue_l_import(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $migration = require database_path('migrations/2026_11_26_140000_poser_les_compatibilites_du_filtre_90915_yzzd1.php');
        $migration->up();
        $migration->up();

        $this->assertSame(8, $piece->fitments()->count());
    }
}
