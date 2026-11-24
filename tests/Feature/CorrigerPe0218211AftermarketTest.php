<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use App\Support\CorrigerPe0218211Aftermarket;
use App\Support\MazdaPe0218211Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrigerPe0218211AftermarketTest extends TestCase
{
    use RefreshDatabase;

    private function catalogue(): void
    {
        $parent = PartCategory::firstOrCreate(
            ['slug' => 'capteurs-sondes'],
            ['name' => 'Capteurs & Sondes'],
        );
        PartCategory::firstOrCreate(
            ['slug' => 'capteur-pression'],
            ['name' => 'Capteur de pression', 'parent_id' => $parent->id],
        );
        Manufacturer::firstOrCreate(['name' => 'Chine']);
        $mazda = Brand::firstOrCreate(['slug' => 'mazda'], ['name' => 'Mazda', 'is_active' => true]);

        foreach ([
            ['slug' => 'mazda2-dj', 'name' => 'Mazda 2', 'generation' => 'DJ'],
            ['slug' => 'mazda3-bl', 'name' => 'Mazda 3', 'generation' => 'BL'],
            ['slug' => 'mazda3-bm', 'name' => 'Mazda 3', 'generation' => 'BM'],
            ['slug' => 'mazda3-bp', 'name' => 'Mazda 3', 'generation' => 'BP'],
            ['slug' => 'mazda6-gj', 'name' => 'Mazda 6', 'generation' => 'GJ'],
            ['slug' => 'cx-3-dk', 'name' => 'CX-3', 'generation' => 'DK'],
            ['slug' => 'cx-5-ke', 'name' => 'CX-5', 'generation' => 'KE'],
            ['slug' => 'cx-5-kf', 'name' => 'CX-5', 'generation' => 'KF'],
        ] as $modele) {
            VehicleModel::firstOrCreate(
                ['brand_id' => $mazda->id, 'slug' => $modele['slug']],
                [
                    'name' => $modele['name'],
                    'generation' => $modele['generation'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function importerCommeOem(): Part
    {
        $this->catalogue();
        MazdaPe0218211Import::run();

        return Part::where('sku', CorrigerPe0218211Aftermarket::SKU)->firstOrFail();
    }

    public function test_elle_est_inerte_sans_la_piece(): void
    {
        Manufacturer::firstOrCreate(['name' => 'Chine']);

        $rapport = CorrigerPe0218211Aftermarket::run();

        $this->assertTrue($rapport['skipped_missing_part']);
        $this->assertFalse($rapport['updated']);
        $this->assertNull($rapport['part_id']);
        $this->assertSame(0, Part::where('sku', CorrigerPe0218211Aftermarket::SKU)->count());
    }

    public function test_elle_passe_la_fiche_en_aftermarket_chine(): void
    {
        $piece = $this->importerCommeOem();
        $this->assertSame('oem', $piece->type->value ?? $piece->type);
        $this->assertSame('Mazda', $piece->manufacturer?->name);
        $this->assertStringContainsString('d\'origine Mazda', (string) $piece->description);

        $rapport = CorrigerPe0218211Aftermarket::run();
        $piece->refresh();

        $this->assertTrue($rapport['updated']);
        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertSame(CorrigerPe0218211Aftermarket::NOM, $piece->name);
        $this->assertSame(CorrigerPe0218211Aftermarket::DESCRIPTION, $piece->description);
        $this->assertSame('aftermarket', $piece->type->value ?? $piece->type);
        $this->assertSame('Chine', $piece->manufacturer?->name);
        $this->assertSame('chine', $piece->manufacturer?->slug);
        $this->assertSame('PE02-18-211', $piece->manufacturer_reference);
        $this->assertStringContainsString('adaptable', (string) $piece->description);
        $this->assertStringContainsString('compatible avec la référence d\'origine Mazda PE02-18-211', (string) $piece->description);
        $this->assertStringNotContainsString('d\'origine Mazda, référence', (string) $piece->description);
        $this->assertStringContainsString('PY8W-18-211', (string) $piece->description);
        $this->assertStringContainsString('SH01-18-211', (string) $piece->description);
        $this->assertStringContainsString('PEDD-18-211', (string) $piece->description);
        $this->assertStringContainsString('vérifier le VIN', (string) $piece->description);
    }

    public function test_elle_preserve_le_commerce_le_numero_oem_et_les_compatibilites(): void
    {
        $piece = $this->importerCommeOem();
        $piece->update([
            'selling_price' => 20000,
            'stock_quantity' => 2,
            'cost_price' => 10000,
            'storage_location' => 'Ouagadougou',
            'stock_alert_threshold' => 1,
            'is_active' => true,
            'is_available' => true,
        ]);

        $oemAvant = $piece->oemNumbers()->get(['oem_numbers.id', 'number', 'normalized_number', 'brand_id'])->toArray();
        $fitmentsAvant = $piece->fitments()
            ->orderBy('id')
            ->get(['id', 'vehicle_model_id', 'year_from', 'year_to', 'engine_code', 'chassis_code', 'notes', 'source'])
            ->toArray();
        $this->assertCount(8, $fitmentsAvant);
        $this->assertCount(1, $oemAvant);

        CorrigerPe0218211Aftermarket::run();
        $piece->refresh();

        $this->assertEquals(20000, $piece->selling_price);
        $this->assertSame(2, $piece->stock_quantity);
        $this->assertEquals(10000, $piece->cost_price);
        $this->assertSame('Ouagadougou', $piece->storage_location);
        $this->assertSame(1, $piece->stock_alert_threshold);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertTrue((bool) $piece->is_available);
        $this->assertSame('XOF', $piece->currency);
        $this->assertSame($oemAvant, $piece->oemNumbers()->get(['oem_numbers.id', 'number', 'normalized_number', 'brand_id'])->toArray());
        $this->assertSame('PE02-18-211', $piece->oemNumbers()->first()?->number);
        $this->assertSame('PE0218211', $piece->oemNumbers()->first()?->normalized_number);
        $this->assertSame('mazda', $piece->oemNumbers()->first()?->brand?->slug);
        $this->assertSame(
            $fitmentsAvant,
            $piece->fitments()
                ->orderBy('id')
                ->get(['id', 'vehicle_model_id', 'year_from', 'year_to', 'engine_code', 'chassis_code', 'notes', 'source'])
                ->toArray()
        );
    }

    public function test_rejouer_est_idempotent(): void
    {
        $piece = $this->importerCommeOem();
        CorrigerPe0218211Aftermarket::run();
        $id = $piece->id;
        $maj = $piece->fresh()->updated_at?->getTimestamp();
        $fitments = $piece->fitments()->count();
        $oem = $piece->oemNumbers()->count();

        $rapport = CorrigerPe0218211Aftermarket::run();
        $piece->refresh();

        $this->assertFalse($rapport['updated']);
        $this->assertSame($id, $rapport['part_id']);
        $this->assertSame(1, Part::where('sku', CorrigerPe0218211Aftermarket::SKU)->count());
        $this->assertSame($fitments, $piece->fitments()->count());
        $this->assertSame($oem, $piece->oemNumbers()->count());
        $this->assertSame('aftermarket', $piece->type->value ?? $piece->type);
        $this->assertSame('Chine', $piece->manufacturer?->name);
        $this->assertEquals($maj, $piece->updated_at?->getTimestamp());
    }

    public function test_elle_ne_supprime_pas_le_fabricant_mazda(): void
    {
        $this->importerCommeOem();
        $this->assertTrue(Manufacturer::where('slug', 'mazda')->orWhere('name', 'Mazda')->exists());

        CorrigerPe0218211Aftermarket::run();

        $this->assertTrue(Manufacturer::where('slug', 'mazda')->orWhere('name', 'Mazda')->exists());
        $this->assertSame(0, Part::where('sku', CorrigerPe0218211Aftermarket::SKU)->whereHas('manufacturer', fn ($q) => $q->where('name', 'Mazda'))->count());
    }

    public function test_sans_fabricant_chine_elle_ne_touche_a_rien(): void
    {
        $this->catalogue();
        Manufacturer::where('slug', 'chine')->orWhere('name', 'Chine')->delete();
        MazdaPe0218211Import::run();
        $piece = Part::where('sku', CorrigerPe0218211Aftermarket::SKU)->firstOrFail();

        $rapport = CorrigerPe0218211Aftermarket::run();
        $piece->refresh();

        $this->assertTrue($rapport['skipped_missing_manufacturer']);
        $this->assertFalse($rapport['updated']);
        $this->assertSame('oem', $piece->type->value ?? $piece->type);
        $this->assertSame('Mazda', $piece->manufacturer?->name);
    }
}
