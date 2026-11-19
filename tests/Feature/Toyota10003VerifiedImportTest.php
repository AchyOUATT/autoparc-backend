<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use App\Support\Toyota10003VerifiedImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Toyota10003VerifiedImportTest extends TestCase
{
    use RefreshDatabase;

    private function toyota(): Brand
    {
        return Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota', 'is_active' => true]);
    }

    private function categorie(): PartCategory
    {
        return PartCategory::firstOrCreate(
            ['slug' => 'filtre-huile'],
            ['name' => 'Filtre à huile'],
        );
    }

    private function modele(Brand $marque, string $slug, string $nom, string $generation): VehicleModel
    {
        return VehicleModel::firstOrCreate(
            ['brand_id' => $marque->id, 'slug' => $slug],
            [
                'name' => $nom,
                'generation' => $generation,
                'is_active' => true,
            ],
        );
    }

    private function catalogue(): void
    {
        $this->categorie();
        Manufacturer::firstOrCreate(['name' => 'Chine']);
        $toyota = $this->toyota();
        $this->modele($toyota, 'corolla-e120', 'Corolla', 'E120');
        $this->modele($toyota, 'corolla-e140', 'Corolla', 'E140');
        $this->modele($toyota, 'yaris-xp90', 'Yaris', 'XP90');
        $this->modele($toyota, 'yaris-xp130', 'Yaris', 'XP130');
    }

    private function part10001(): Part
    {
        $this->categorie();

        return Part::create([
            'sku' => '90915-10001',
            'name' => 'Filtre a huile visse 90915-10001',
            'part_category_id' => $this->categorie()->id,
            'manufacturer_id' => Manufacturer::firstOrCreate(['name' => 'Chine'])->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 3000,
            'stock_quantity' => 15,
            'is_active' => true,
        ]);
    }

    private function applications10001(Part $piece): void
    {
        $data = json_decode(
            (string) file_get_contents(database_path('catalogue/toyota-10001-verified-applications.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $toyota = $this->toyota();
        foreach ($data['fitments'] as $fitment) {
            $modele = $this->modele(
                $toyota,
                $fitment['model_slug'],
                ucfirst(str_replace('-', ' ', $fitment['model_slug'])),
                strtoupper(preg_replace('/.*-/', '', $fitment['model_slug'])),
            );
            $piece->fitments()->create([
                'vehicle_model_id' => $modele->id,
                'engine_code' => $fitment['engine_code'],
                'year_from' => $fitment['year_from'],
                'year_to' => $fitment['year_to'],
                'source' => FitmentSource::Declared,
                'notes' => 'Note atelier à conserver.',
            ]);
        }
    }

    private function yzzn1(): Part
    {
        $toyota = $this->toyota();
        $e140 = $this->modele($toyota, 'corolla-e140', 'Corolla', 'E140');
        $xp130 = $this->modele($toyota, 'yaris-xp130', 'Yaris', 'XP130');
        $piece = Part::create([
            'sku' => '90915-YZZN1',
            'name' => 'Filtre a huile visse 90915-YZZN1',
            'part_category_id' => $this->categorie()->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 3000,
            'stock_quantity' => 15,
            'is_active' => true,
        ]);
        $piece->fitments()->create([
            'vehicle_model_id' => $e140->id,
            'engine_code' => '1ZZ-FE',
            'year_from' => 2006,
            'year_to' => 2008,
            'source' => FitmentSource::Declared,
            'notes' => 'Ligne YZZN1 Corolla E140, ne pas toucher.',
        ]);
        $piece->fitments()->create([
            'vehicle_model_id' => $xp130->id,
            'engine_code' => '1NZ-FE',
            'year_from' => 2011,
            'year_to' => 2019,
            'source' => FitmentSource::Declared,
            'notes' => 'Ligne YZZN1 Yaris XP130, ne pas toucher.',
        ]);

        return $piece->refresh();
    }

    public function test_sans_categorie_rien_n_est_cree(): void
    {
        $this->toyota();

        $rapport = Toyota10003VerifiedImport::run();

        $this->assertNull($rapport['part_id']);
        $this->assertFalse($rapport['part_created']);
        $this->assertSame(0, Part::where('sku', '90915-10003')->count());
    }

    public function test_la_piece_est_inactive_sans_prix_ni_stock_inventes(): void
    {
        $this->catalogue();

        $rapport = Toyota10003VerifiedImport::run();

        $piece = Part::where('sku', '90915-10003')->firstOrFail();
        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertTrue($rapport['part_created']);
        $this->assertFalse((bool) $piece->is_active);
        $this->assertSame(0.0, (float) $piece->selling_price);
        $this->assertSame(0, $piece->stock_quantity);
        $this->assertSame('filtre-huile', $piece->category->slug);
        $this->assertSame('aftermarket', $piece->type->value ?? $piece->type);
        $this->assertSame('90915-10003', $piece->manufacturer_reference);
        $this->assertNotNull($piece->description);
        $this->assertStringContainsString('châssis', $piece->description);

        $oem = $piece->oemNumbers()->where('number', '90915-10003')->first();
        $this->assertNotNull($oem);
        $this->assertTrue((bool) $piece->oemNumbers()->where('oem_numbers.id', $oem->id)->first()->pivot->is_primary);
    }

    public function test_seulement_les_modeles_deja_au_catalogue_sont_rattaches(): void
    {
        $this->catalogue();

        Toyota10003VerifiedImport::run();

        $piece = Part::where('sku', '90915-10003')->with('fitments.vehicleModel')->firstOrFail();
        $this->assertCount(7, $piece->fitments);

        $attendu = [
            ['corolla-e120', '1ZZ-FE', 2000, 2006, 'ZZE122/ZZE124'],
            ['corolla-e120', '1NZ-FE', 2000, 2006, 'NZE121/NZE124'],
            ['corolla-e120', '2NZ-FE', 2000, 2006, 'NZE120'],
            ['corolla-e140', '1NZ-FE', 2006, 2012, 'NZE141/NZE144'],
            ['yaris-xp90', '1NZ-FE', 2005, 2010, 'NCP91'],
            ['yaris-xp90', '2NZ-FE', 2005, 2010, 'NCP95'],
            ['yaris-xp90', '2SZ-FE', 2005, 2010, 'SCP90'],
        ];
        $actuel = $piece->fitments->map(fn ($f) => [
            $f->vehicleModel->slug, $f->engine_code, $f->year_from, $f->year_to, $f->chassis_code,
        ])->all();
        $this->assertEqualsCanonicalizing($attendu, $actuel);

        foreach ($piece->fitments as $ligne) {
            $this->assertSame(FitmentSource::Declared, $ligne->source);
            $this->assertStringContainsString('https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf', $ligne->notes);
            $this->assertStringContainsString('2026-11-19', $ligne->notes);
            $this->assertStringContainsString('hks-power.co.jp', $ligne->notes);
        }

        $this->assertSame(0, VehicleModel::whereIn('slug', [
            'bb', 'celica', 'vista', 'mr-s', 'platz', 'funcargo', 'vitz-xp10',
        ])->count());
        $this->assertFalse(
            $piece->fitments()->whereHas('vehicleModel', fn ($q) => $q->where('slug', 'yaris-xp130'))->exists(),
            'La Yaris XP130 ne doit pas recevoir de ligne 10003.',
        );
        $this->assertFalse($piece->fitments()->where('engine_code', '1ZZ-FE')
            ->whereHas('vehicleModel', fn ($q) => $q->where('slug', 'corolla-e140'))
            ->exists());
    }

    public function test_un_modele_absent_est_signale_et_saute(): void
    {
        $this->categorie();
        $toyota = $this->toyota();
        $this->modele($toyota, 'corolla-e120', 'Corolla', 'E120');
        $this->modele($toyota, 'yaris-xp90', 'Yaris', 'XP90');

        $rapport = Toyota10003VerifiedImport::run();

        $this->assertSame(6, $rapport['fitments_inserted']);
        $this->assertSame(['corolla-e140'], $rapport['fitments_skipped_missing_model']);
        $this->assertSame(6, Part::where('sku', '90915-10003')->firstOrFail()->fitments()->count());
        $this->assertNull(VehicleModel::where('slug', 'corolla-e140')->first());
    }

    public function test_rejouer_n_empile_pas_et_laisse_le_commerce(): void
    {
        $this->catalogue();
        Toyota10003VerifiedImport::run();
        $piece = Part::where('sku', '90915-10003')->firstOrFail();
        $piece->update(['selling_price' => 4500, 'stock_quantity' => 3, 'is_active' => true]);
        $avant = $piece->fitments()->orderBy('id')->get(['id', 'chassis_code', 'notes', 'created_at'])->toArray();

        $rapport = Toyota10003VerifiedImport::run();
        $migration = require database_path('migrations/2026_11_19_141100_importer_le_filtre_90915_10003.php');
        $migration->up();

        $this->assertFalse($rapport['part_created']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $piece = $piece->fresh();
        $this->assertSame(4500.0, (float) $piece->selling_price);
        $this->assertSame(3, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertSame($avant, $piece->fitments()->orderBy('id')->get(['id', 'chassis_code', 'notes', 'created_at'])->toArray());
        $this->assertSame(1, Part::where('sku', '90915-10003')->count());
    }

    public function test_les_cinq_lignes_du_10001_recoivent_leur_code_chassis_source(): void
    {
        $this->catalogue();
        $piece = $this->part10001();
        $this->applications10001($piece);
        $commerce = $piece->fresh()->only(['selling_price', 'stock_quantity', 'is_active', 'name']);

        $rapport = Toyota10003VerifiedImport::run();

        $this->assertSame(5, $rapport['chassis_10001_filled']);
        $actuel = $piece->fitments()->with('vehicleModel')->get()->map(fn ($f) => [
            $f->vehicleModel->slug, $f->chassis_code, $f->notes,
        ])->all();
        $this->assertEqualsCanonicalizing([
            ['corolla-e100', 'AE101/AE104', 'Note atelier à conserver.'],
            ['corolla-e110', 'AE111/AE114', 'Note atelier à conserver.'],
            ['camry-xv10', 'SXV10/SXV15', 'Note atelier à conserver.'],
            ['camry-xv20', 'SXV20/SXV20W', 'Note atelier à conserver.'],
            ['rav4-xa10', 'SXA10/SXA11', 'Note atelier à conserver.'],
        ], $actuel);
        $this->assertSame($commerce, $piece->fresh()->only(['selling_price', 'stock_quantity', 'is_active', 'name']));
    }

    public function test_un_code_chassis_deja_renseigne_sur_le_10001_n_est_pas_ecrase(): void
    {
        $this->catalogue();
        $piece = $this->part10001();
        $this->applications10001($piece);
        $ligne = $piece->fitments()->where('engine_code', '4A-FE')->where('year_from', 1991)->firstOrFail();
        $ligne->update(['chassis_code' => 'SAISI-MAIN']);

        Toyota10003VerifiedImport::run();

        $this->assertSame('SAISI-MAIN', $ligne->fresh()->chassis_code);
        $this->assertSame(4, $piece->fitments()->whereNotNull('chassis_code')->where('chassis_code', '!=', 'SAISI-MAIN')->count());
    }

    public function test_les_lignes_du_yzzn1_restent_intouchables(): void
    {
        $this->catalogue();
        $yzzn1 = $this->yzzn1();
        $avant = $yzzn1->fitments()->orderBy('id')->get()->map->getAttributes()->all();

        $rapport = Toyota10003VerifiedImport::run();

        $this->assertTrue($rapport['yzzn1_untouched']);
        $this->assertSame(
            $avant,
            $yzzn1->fresh()->fitments()->orderBy('id')->get()->map->getAttributes()->all(),
        );
        $this->assertSame(2, $yzzn1->fitments()->count());
        $this->assertFalse(
            $yzzn1->fitments()->where('chassis_code', 'NZE141/NZE144')->exists(),
            'Le 10003 ne doit pas écrire sur les lignes du YZZN1.',
        );
    }
}
