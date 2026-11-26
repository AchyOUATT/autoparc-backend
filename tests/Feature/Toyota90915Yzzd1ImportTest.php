<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
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
        ['brand' => 'toyota', 'slug' => 'tacoma-ii', 'name' => 'Tacoma', 'generation' => 'II'],
        ['brand' => 'toyota', 'slug' => 'tacoma-iii', 'name' => 'Tacoma', 'generation' => 'III'],
        ['brand' => 'toyota', 'slug' => 'highlander', 'name' => 'Highlander', 'generation' => null],
        ['brand' => 'lexus', 'slug' => 'rx-xu30', 'name' => 'RX', 'generation' => 'XU30'],
        // Modèles présents en production mais volontairement sans ligne.
        ['brand' => 'toyota', 'slug' => 'tacoma-iv', 'name' => 'Tacoma', 'generation' => 'IV'],
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

        $rx = VehicleModel::where('slug', 'rx-xu30')->firstOrFail();
        foreach ([['330', 'RX 330', 1], ['400h', 'RX 400h', 2], ['350', 'RX 350', 3]] as [$code, $nom, $rang]) {
            Trim::firstOrCreate(
                ['vehicle_model_id' => $rx->id, 'code' => $code],
                ['name' => $nom, 'rank' => $rang],
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
        $this->assertSame(11, $rapport['fitments_inserted']);
        $this->assertSame([], $rapport['fitments_skipped_missing_model']);
        $this->assertSame([], $rapport['fitments_skipped_missing_trim']);

        $piece->refresh();
        $this->assertEquals(3000, $piece->selling_price);
        $this->assertSame(15, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertSame('Filtre a huile visse 90915-YZZD1', $piece->name);

        $lignes = $piece->fitments()->with(['vehicleModel', 'trim'])->get()
            ->map(fn ($f) => $f->vehicleModel->slug.'|'.($f->trim?->code ?? '-').'|'.$f->engine_code.'|'.$f->year_from.'|'.$f->year_to)
            ->sort()->values()->all();
        $this->assertSame([
            'camry-xv10|-|1MZ-FE|1994|1996',
            'camry-xv10|-|3VZ-FE|1992|1993',
            'camry-xv20|-|1MZ-FE|1997|2001',
            'highlander|-|3MZ-FE|2008|2010',
            'rx-xu30|330|3MZ-FE|2004|2006',
            'rx-xu30|400h|3MZ-FE|2006|2008',
            'tacoma-iii|-|2TR-FE|2016|2023',
            'tacoma-ii|-|2TR-FE|2005|2015',
            'tacoma-i|-|2RZ-FE|1995|2004',
            'tacoma-i|-|3RZ-FE|1995|2004',
            'tacoma-i|-|5VZ-FE|1995|2004',
        ], $lignes);

        foreach ($piece->fitments as $ligne) {
            $this->assertSame('declared', $ligne->source->value ?? $ligne->source);
            $this->assertNotNull($ligne->engine_code);
            $this->assertNull($ligne->chassis_code);
            $this->assertStringContainsString('https://', (string) $ligne->notes);
            $this->assertStringNotContainsString('@', (string) $ligne->notes);
        }
    }

    public function test_les_lignes_officielles_citent_le_catalogue_toyota(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        Toyota90915Yzzd1Import::run();

        foreach (['camry-xv20', 'tacoma-i', 'tacoma-ii', 'tacoma-iii', 'highlander'] as $slug) {
            $modele = VehicleModel::where('slug', $slug)->firstOrFail();
            foreach ($piece->fitments()->where('vehicle_model_id', $modele->id)->get() as $ligne) {
                $this->assertStringContainsString('06_OilFilters2025_hi.pdf', (string) $ligne->notes, $slug);
            }
        }
    }

    public function test_les_modeles_ambigus_ne_recoivent_aucune_ligne(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        Toyota90915Yzzd1Import::run();

        $exclus = VehicleModel::whereIn('slug', ['tacoma-iv', 'tacoma', 'sienna', 'avalon'])->pluck('id');
        $this->assertSame(0, $piece->fitments()->whereIn('vehicle_model_id', $exclus)->count());
    }

    public function test_les_anciens_numeros_officiels_sont_rattaches_comme_perimes(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(3, $rapport['oem_superseded_linked']);
        $actuel = OemNumber::where('normalized_number', '90915YZZD1')->firstOrFail();
        $this->assertFalse((bool) $actuel->is_superseded);
        foreach (Toyota90915Yzzd1Import::NUMEROS_REMPLACES as $ancien) {
            $numero = OemNumber::where('normalized_number', OemNumber::normalize($ancien))->firstOrFail();
            $this->assertTrue((bool) $numero->is_superseded, $ancien);
            $this->assertSame($actuel->id, (int) $numero->superseded_by_id, $ancien);
            $this->assertTrue($piece->oemNumbers()->where('oem_numbers.id', $numero->id)->exists(), $ancien);
            $this->assertFalse((bool) $piece->oemNumbers()->where('oem_numbers.id', $numero->id)->first()->pivot->is_primary);
        }
        $this->assertSame(['90915-YZZB1', '90915-YZZB9', '90915-YZZG2'], Toyota90915Yzzd1Import::NUMEROS_REMPLACES);
    }

    public function test_un_numero_deja_remplace_par_une_autre_reference_n_est_pas_modifie(): void
    {
        $this->catalogue();
        $this->filtre();
        $autre = OemNumber::create(['number' => '90915-YZZG9', 'brand_id' => null, 'label' => 'autre', 'is_superseded' => false]);
        $ancien = OemNumber::create([
            'number' => '90915-YZZB1', 'brand_id' => null, 'label' => 'x',
            'is_superseded' => true, 'superseded_by_id' => $autre->id,
        ]);

        Toyota90915Yzzd1Import::run();

        $this->assertSame($autre->id, (int) $ancien->fresh()->superseded_by_id);
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
        $oem = OemNumber::count();
        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(11, $rapport['fitments_already_present']);
        $this->assertSame(0, $rapport['oem_superseded_linked']);
        $this->assertSame(11, $piece->fitments()->count());
        $this->assertSame($oem, OemNumber::count());
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

        $this->assertSame(10, $rapport['fitments_inserted']);
        $this->assertSame(1, $rapport['fitments_already_present']);
        $this->assertSame(
            'Saisie par le commerçant',
            $piece->fitments()->where('vehicle_model_id', $hl->id)->firstOrFail()->notes,
        );
    }

    public function test_un_modele_ou_une_finition_absents_sont_ignores_sans_erreur(): void
    {
        $this->catalogue();
        VehicleModel::where('slug', 'tacoma-iii')->delete();
        Trim::where('code', '400h')->delete();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(9, $rapport['fitments_inserted']);
        $this->assertSame(['tacoma-iii'], $rapport['fitments_skipped_missing_model']);
        $this->assertSame(['rx-xu30:400h'], $rapport['fitments_skipped_missing_trim']);
        $this->assertSame(9, $piece->fitments()->count());
    }

    public function test_la_migration_rejoue_l_import(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $migration = require database_path('migrations/2026_11_26_140000_poser_les_compatibilites_du_filtre_90915_yzzd1.php');
        $migration->up();
        $migration->up();

        $this->assertSame(11, $piece->fitments()->count());
    }
}
