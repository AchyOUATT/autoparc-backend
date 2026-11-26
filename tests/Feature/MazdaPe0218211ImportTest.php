<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use App\Support\CompatibilitesPourApercu;
use App\Support\MazdaPe0218211Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MazdaPe0218211ImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{slug: string, name: string, generation: string}> */
    private const MODELES = [
        ['slug' => 'mazda2-dj', 'name' => 'Mazda 2', 'generation' => 'DJ'],
        ['slug' => 'mazda3-bl', 'name' => 'Mazda 3', 'generation' => 'BL'],
        ['slug' => 'mazda3-bm', 'name' => 'Mazda 3', 'generation' => 'BM'],
        ['slug' => 'mazda3-bp', 'name' => 'Mazda 3', 'generation' => 'BP'],
        ['slug' => 'mazda6-gh', 'name' => 'Mazda 6', 'generation' => 'GH'],
        ['slug' => 'mazda6-gj', 'name' => 'Mazda 6', 'generation' => 'GJ'],
        ['slug' => 'cx-3-dk', 'name' => 'CX-3', 'generation' => 'DK'],
        ['slug' => 'cx-5-ke', 'name' => 'CX-5', 'generation' => 'KE'],
        ['slug' => 'cx-5-kf', 'name' => 'CX-5', 'generation' => 'KF'],
        ['slug' => 'cx-30-dm', 'name' => 'CX-30', 'generation' => 'DM'],
        ['slug' => 'cx-50-va', 'name' => 'CX-50', 'generation' => 'VA'],
    ];

    private function mazda(): Brand
    {
        return Brand::firstOrCreate(['slug' => 'mazda'], ['name' => 'Mazda', 'is_active' => true]);
    }

    private function categorie(): PartCategory
    {
        $parent = PartCategory::firstOrCreate(
            ['slug' => 'capteurs-sondes'],
            ['name' => 'Capteurs & Sondes'],
        );

        return PartCategory::firstOrCreate(
            ['slug' => 'capteur-pression'],
            ['name' => 'Capteur de pression', 'parent_id' => $parent->id],
        );
    }

    private function catalogue(): Brand
    {
        $this->categorie();
        $mazda = $this->mazda();
        foreach (self::MODELES as $modele) {
            VehicleModel::firstOrCreate(
                ['brand_id' => $mazda->id, 'slug' => $modele['slug']],
                [
                    'name' => $modele['name'],
                    'generation' => $modele['generation'],
                    'is_active' => true,
                ],
            );
        }

        return $mazda;
    }

    public function test_la_migration_est_inerte_sans_categorie(): void
    {
        $rapport = MazdaPe0218211Import::run();

        $this->assertNull($rapport['part_id']);
        $this->assertFalse($rapport['part_created']);
        $this->assertSame(0, Part::where('sku', MazdaPe0218211Import::SKU)->count());
    }

    public function test_elle_cree_la_piece_a_20000_f_stock_2_active(): void
    {
        $this->catalogue();

        $rapport = MazdaPe0218211Import::run();

        $piece = Part::where('sku', MazdaPe0218211Import::SKU)->firstOrFail();
        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertTrue($rapport['part_created']);
        $this->assertSame(MazdaPe0218211Import::NOM, $piece->name);
        $this->assertSame('PE02-18-211', $piece->manufacturer_reference);
        $this->assertSame('oem', $piece->type->value ?? $piece->type);
        $this->assertSame('new', $piece->condition->value ?? $piece->condition);
        $this->assertSame('XOF', $piece->currency);
        $this->assertEquals(20000, $piece->selling_price);
        $this->assertSame(2, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertTrue((bool) $piece->is_available);
        $this->assertSame('Mazda', $piece->manufacturer?->name);
        $this->assertSame('capteur-pression', $piece->category?->slug);
        $this->assertStringContainsString('SKYACTIV-G', (string) $piece->description);
        $this->assertStringContainsString('PY8W-18-211', (string) $piece->description);
        $this->assertStringContainsString('PEDD-18-211', (string) $piece->description);
        $this->assertStringContainsString('SH01-18-211', (string) $piece->description);
    }

    public function test_elle_rattache_le_numero_oem_mazda(): void
    {
        $this->catalogue();
        MazdaPe0218211Import::run();

        $piece = Part::where('sku', MazdaPe0218211Import::SKU)->firstOrFail();
        $numero = $piece->oemNumbers()->firstOrFail();

        $this->assertSame('PE02-18-211', $numero->number);
        $this->assertSame('PE0218211', $numero->normalized_number);
        $this->assertSame('mazda', $numero->brand?->slug);
        $this->assertTrue((bool) $piece->oemNumbers()->first()?->pivot?->is_primary);
        $this->assertSame(1, $piece->oemNumbers()->count());
    }

    public function test_elle_pose_les_huit_compatibilites_sourcees(): void
    {
        $this->catalogue();
        MazdaPe0218211Import::run();

        $piece = Part::where('sku', MazdaPe0218211Import::SKU)->firstOrFail();
        $lignes = $piece->fitments()->with('vehicleModel')->get()->keyBy(fn ($f) => $f->vehicleModel->slug);

        $this->assertCount(8, $lignes);
        $this->assertEqualsCanonicalizing(MazdaPe0218211Import::MODELES_RETENUS, $lignes->keys()->all());

        $attendu = [];
        foreach (MazdaPe0218211Import::applications() as $app) {
            $attendu[$app['model_slug']] = $app;
        }

        foreach ($attendu as $slug => $app) {
            $this->assertArrayHasKey($slug, $lignes->all());
            $ligne = $lignes[$slug];
            $this->assertSame($app['year_from'], (int) $ligne->year_from, $slug);
            $this->assertSame($app['year_to'], $ligne->year_to !== null ? (int) $ligne->year_to : null, $slug);
            $this->assertNull($ligne->engine_code);
            $this->assertNull($ligne->chassis_code);
            $this->assertSame($app['notes'], $ligne->notes);
            $this->assertSame('declared', $ligne->source->value ?? $ligne->source);
        }

        $this->assertArrayNotHasKey('mazda6-gh', $lignes->all());
        $this->assertArrayNotHasKey('cx-30-dm', $lignes->all());
        $this->assertArrayNotHasKey('cx-50-va', $lignes->all());
    }

    public function test_elle_corrige_une_fiche_existante_sans_ecraser_le_commerce(): void
    {
        $mazda = $this->catalogue();
        $parent = PartCategory::where('slug', 'capteurs-sondes')->firstOrFail();
        $piece = Part::create([
            'sku' => MazdaPe0218211Import::SKU,
            'name' => 'Capteur d\'arbre à cames Mazda',
            'part_category_id' => $parent->id,
            'condition' => 'new',
            'selling_price' => 20000,
            'cost_price' => 10000,
            'currency' => 'XOF',
            'stock_quantity' => 2,
            'stock_alert_threshold' => 1,
            'storage_location' => 'Ouagadougou',
            'is_active' => true,
            'is_available' => true,
        ]);

        $bl = VehicleModel::where('slug', 'mazda3-bl')->firstOrFail();
        $bm = VehicleModel::where('slug', 'mazda3-bm')->firstOrFail();
        $gh = VehicleModel::where('slug', 'mazda6-gh')->firstOrFail();
        $gj = VehicleModel::where('slug', 'mazda6-gj')->firstOrFail();
        $kf = VehicleModel::where('slug', 'cx-5-kf')->firstOrFail();
        foreach ([$bl, $bm, $gh, $gj] as $modele) {
            $piece->fitments()->create([
                'vehicle_model_id' => $modele->id,
                'year_from' => 2011,
                'year_to' => 2017,
                'source' => 'declared',
            ]);
        }
        $piece->fitments()->create([
            'vehicle_model_id' => $kf->id,
            'year_from' => 2017,
            'year_to' => null,
            'source' => 'declared',
        ]);

        $rapport = MazdaPe0218211Import::run();

        $piece->refresh();
        $this->assertFalse($rapport['part_created']);
        $this->assertTrue($rapport['part_updated']);
        $this->assertTrue($rapport['commerce_preserved']);
        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertSame(1, Part::where('sku', MazdaPe0218211Import::SKU)->count());
        $this->assertSame(MazdaPe0218211Import::NOM, $piece->name);
        $this->assertSame('capteur-pression', $piece->category?->slug);
        $this->assertSame('Mazda', $piece->manufacturer?->name);
        $this->assertSame('PE02-18-211', $piece->manufacturer_reference);
        $this->assertEquals(20000, $piece->selling_price);
        $this->assertEquals(10000, $piece->cost_price);
        $this->assertSame(2, $piece->stock_quantity);
        $this->assertSame(1, $piece->stock_alert_threshold);
        $this->assertSame('Ouagadougou', $piece->storage_location);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertTrue((bool) $piece->is_available);

        $lignes = $piece->fitments()->with('vehicleModel')->get()->keyBy(fn ($f) => $f->vehicleModel->slug);
        $this->assertCount(8, $lignes);
        $this->assertArrayNotHasKey('mazda6-gh', $lignes->all());
        $this->assertSame(2012, (int) $lignes['mazda3-bl']->year_from);
        $this->assertSame(2013, (int) $lignes['mazda3-bl']->year_to);
        $this->assertSame(2013, (int) $lignes['mazda3-bm']->year_from);
        $this->assertSame(2018, (int) $lignes['mazda3-bm']->year_to);
        $this->assertSame(2013, (int) $lignes['mazda6-gj']->year_from);
        $this->assertSame(2021, (int) $lignes['mazda6-gj']->year_to);
        $this->assertSame(2017, (int) $lignes['cx-5-kf']->year_from);
        $this->assertSame(2024, (int) $lignes['cx-5-kf']->year_to);
        $this->assertTrue($lignes->has('mazda2-dj'));
        $this->assertTrue($lignes->has('mazda3-bp'));
        $this->assertTrue($lignes->has('cx-3-dk'));
        $this->assertTrue($lignes->has('cx-5-ke'));
        $this->assertSame($mazda->id, $lignes['mazda2-dj']->vehicleModel->brand_id);
    }

    public function test_rejouer_l_import_est_idempotent(): void
    {
        $this->catalogue();
        MazdaPe0218211Import::run();
        $id = Part::where('sku', MazdaPe0218211Import::SKU)->value('id');
        $fitments = Part::findOrFail($id)->fitments()->count();
        $oem = Part::findOrFail($id)->oemNumbers()->count();

        $rapport = MazdaPe0218211Import::run();

        $this->assertSame($id, $rapport['part_id']);
        $this->assertFalse($rapport['part_created']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(0, $rapport['fitments_removed']);
        $this->assertSame(1, Part::where('sku', MazdaPe0218211Import::SKU)->count());
        $this->assertSame($fitments, Part::findOrFail($id)->fitments()->count());
        $this->assertSame($oem, Part::findOrFail($id)->oemNumbers()->count());
        $this->assertEquals(20000, Part::findOrFail($id)->selling_price);
        $this->assertSame(2, Part::findOrFail($id)->stock_quantity);
    }

    public function test_une_relance_ne_rend_pas_un_prix_ou_un_stock_poses_a_la_main(): void
    {
        $this->catalogue();
        MazdaPe0218211Import::run();

        $piece = Part::where('sku', MazdaPe0218211Import::SKU)->firstOrFail();
        $piece->update([
            'selling_price' => 25000,
            'stock_quantity' => 7,
            'is_active' => false,
            'is_available' => false,
        ]);

        MazdaPe0218211Import::run();
        $piece->refresh();

        $this->assertEquals(25000, $piece->selling_price);
        $this->assertSame(7, $piece->stock_quantity);
        $this->assertFalse((bool) $piece->is_active);
        $this->assertFalse((bool) $piece->is_available);
    }

    public function test_l_apercu_de_partage_reste_lisible(): void
    {
        $this->catalogue();
        MazdaPe0218211Import::run();

        $piece = Part::where('sku', MazdaPe0218211Import::SKU)->firstOrFail();
        $texte = CompatibilitesPourApercu::texte($piece->fitments()->with('vehicleModel.brand')->orderBy('id')->get());

        $this->assertNotNull($texte);
        $this->assertStringStartsWith('Compatible : ', $texte);
        $this->assertStringContainsString('et 3 autres', $texte);
        $this->assertStringContainsString('2012-2013', $texte);
        $this->assertStringNotContainsString('PY8W', $texte);
        $this->assertStringNotContainsString('PEDD', $texte);
        $this->assertStringNotContainsString('Bosch', $texte);
        $this->assertStringNotContainsString('VIN', $texte);
    }

    public function test_elle_ne_cree_pas_de_modele_absent_du_catalogue(): void
    {
        $this->catalogue();
        MazdaPe0218211Import::run();

        $this->assertFalse(VehicleModel::where('slug', 'like', 'mx-5%')->exists());
        $this->assertFalse(VehicleModel::where('slug', 'like', 'cx-4%')->exists());
        $this->assertFalse(Manufacturer::where('name', 'Chine')->exists());
    }
}
