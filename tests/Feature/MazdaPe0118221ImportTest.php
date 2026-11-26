<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use App\Support\CompatibilitesPourApercu;
use App\Support\MazdaPe0118221Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MazdaPe0118221ImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{slug: string, name: string, generation: string}> */
    private const MODELES = [
        ['slug' => 'mazda2-dj', 'name' => 'Mazda 2', 'generation' => 'DJ'],
        ['slug' => 'mazda3-bl', 'name' => 'Mazda 3', 'generation' => 'BL'],
        ['slug' => 'mazda3-bm', 'name' => 'Mazda 3', 'generation' => 'BM'],
        ['slug' => 'mazda3-bp', 'name' => 'Mazda 3', 'generation' => 'BP'],
        ['slug' => 'mazda6-gj', 'name' => 'Mazda 6', 'generation' => 'GJ'],
        ['slug' => 'cx-3-dk', 'name' => 'CX-3', 'generation' => 'DK'],
        ['slug' => 'cx-30-dm', 'name' => 'CX-30', 'generation' => 'DM'],
        ['slug' => 'cx-5-ke', 'name' => 'CX-5', 'generation' => 'KE'],
        ['slug' => 'cx-5-kf', 'name' => 'CX-5', 'generation' => 'KF'],
        ['slug' => 'cx-50-va', 'name' => 'CX-50', 'generation' => 'VA'],
        ['slug' => 'cx-9-tb', 'name' => 'CX-9', 'generation' => 'TB'],
        ['slug' => 'cx-9-tc', 'name' => 'CX-9', 'generation' => 'TC'],
    ];

    private function mazda(): Brand
    {
        return Brand::firstOrCreate(['slug' => 'mazda'], ['name' => 'Mazda', 'is_active' => true]);
    }

    private function categorie(): PartCategory
    {
        return PartCategory::firstOrCreate(
            ['slug' => 'capteurs-sondes'],
            ['name' => 'Capteurs & Sondes'],
        );
    }

    private function catalogue(): Brand
    {
        $this->categorie();
        Manufacturer::firstOrCreate(
            ['slug' => 'chine'],
            ['name' => 'Chine', 'is_oem_supplier' => false],
        );
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
        $rapport = MazdaPe0118221Import::run();

        $this->assertNull($rapport['part_id']);
        $this->assertFalse($rapport['part_created']);
        $this->assertSame(0, Part::where('sku', MazdaPe0118221Import::SKU)->count());
        $this->assertFalse(Manufacturer::where('name', 'Chine')->orWhere('slug', 'chine')->exists());
    }

    public function test_elle_cree_la_piece_aftermarket_a_20000_f_stock_2_active(): void
    {
        $this->catalogue();

        $rapport = MazdaPe0118221Import::run();

        $piece = Part::where('sku', MazdaPe0118221Import::SKU)->firstOrFail();
        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertTrue($rapport['part_created']);
        $this->assertSame(MazdaPe0118221Import::NOM, $piece->name);
        $this->assertSame('PE01-18-221', $piece->manufacturer_reference);
        $this->assertSame('aftermarket', $piece->type->value ?? $piece->type);
        $this->assertSame('new', $piece->condition->value ?? $piece->condition);
        $this->assertSame('XOF', $piece->currency);
        $this->assertEquals(20000, $piece->selling_price);
        $this->assertNull($piece->cost_price);
        $this->assertSame(2, $piece->stock_quantity);
        $this->assertSame('Ouagadougou', $piece->storage_location);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertTrue((bool) $piece->is_available);
        $this->assertSame('Chine', $piece->manufacturer?->name);
        $this->assertSame('chine', $piece->manufacturer?->slug);
        $this->assertSame('capteurs-sondes', $piece->category?->slug);
        $this->assertStringContainsString('CKP / PMH', (string) $piece->description);
        $this->assertStringContainsString('3 broches', (string) $piece->description);
        $this->assertStringContainsString('SKYACTIV-G', (string) $piece->description);
        $this->assertStringContainsString('SKYACTIV-D', (string) $piece->description);
        $this->assertStringContainsString('2,5 l Turbo', (string) $piece->description);
        $this->assertStringContainsString('MZR', (string) $piece->description);
        $this->assertStringContainsString('CX-9 TB', (string) $piece->description);
        $this->assertStringContainsString('CX-50 Hybride', (string) $piece->description);
        $this->assertStringNotContainsString('@', (string) $piece->description);
    }

    public function test_elle_rattache_le_numero_oem_mazda(): void
    {
        $this->catalogue();
        MazdaPe0118221Import::run();

        $piece = Part::where('sku', MazdaPe0118221Import::SKU)->firstOrFail();
        $numero = $piece->oemNumbers()->firstOrFail();

        $this->assertSame('PE01-18-221', $numero->number);
        $this->assertSame('PE0118221', $numero->normalized_number);
        $this->assertSame('mazda', $numero->brand?->slug);
        $this->assertTrue((bool) $piece->oemNumbers()->first()?->pivot?->is_primary);
        $this->assertSame(1, $piece->oemNumbers()->count());
    }

    public function test_elle_pose_les_onze_compatibilites_sourcees(): void
    {
        $this->catalogue();
        MazdaPe0118221Import::run();

        $piece = Part::where('sku', MazdaPe0118221Import::SKU)->firstOrFail();
        $lignes = $piece->fitments()->with('vehicleModel')->get()->keyBy(fn ($f) => $f->vehicleModel->slug);

        $this->assertCount(11, $lignes);
        $this->assertEqualsCanonicalizing(MazdaPe0118221Import::MODELES_RETENUS, $lignes->keys()->all());

        $attendu = [];
        foreach (MazdaPe0118221Import::applications() as $app) {
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

        $this->assertArrayNotHasKey('cx-9-tb', $lignes->all());
        $this->assertSame(2014, (int) $lignes['mazda2-dj']->year_from);
        $this->assertNull($lignes['mazda2-dj']->year_to);
        $this->assertSame(2012, (int) $lignes['mazda3-bl']->year_from);
        $this->assertSame(2013, (int) $lignes['mazda3-bl']->year_to);
        $this->assertSame(2019, (int) $lignes['mazda3-bp']->year_from);
        $this->assertSame(2025, (int) $lignes['mazda3-bp']->year_to);
        $this->assertSame(2012, (int) $lignes['mazda6-gj']->year_from);
        $this->assertSame(2021, (int) $lignes['mazda6-gj']->year_to);
        $this->assertSame(2020, (int) $lignes['cx-30-dm']->year_from);
        $this->assertSame(2025, (int) $lignes['cx-30-dm']->year_to);
        $this->assertSame(2023, (int) $lignes['cx-50-va']->year_from);
        $this->assertSame(2025, (int) $lignes['cx-50-va']->year_to);
        $this->assertSame(2016, (int) $lignes['cx-9-tc']->year_from);
        $this->assertSame(2023, (int) $lignes['cx-9-tc']->year_to);
        $this->assertStringContainsString('Hybride', (string) $lignes['cx-50-va']->notes);
        $this->assertStringContainsString('TB V6', (string) $lignes['cx-9-tc']->notes);
        $this->assertStringContainsString('MZR', (string) $lignes['mazda3-bl']->notes);
    }

    public function test_elle_corrige_une_fiche_existante_sans_ecraser_le_commerce(): void
    {
        $mazda = $this->catalogue();
        $parent = PartCategory::where('slug', 'capteurs-sondes')->firstOrFail();
        $piece = Part::create([
            'sku' => MazdaPe0118221Import::SKU,
            'name' => 'Capteur PMH Mazda',
            'part_category_id' => $parent->id,
            'condition' => 'new',
            'selling_price' => 20000,
            'cost_price' => 8000,
            'currency' => 'XOF',
            'stock_quantity' => 2,
            'stock_alert_threshold' => 1,
            'storage_location' => 'Ouagadougou',
            'is_active' => true,
            'is_available' => true,
        ]);

        $bl = VehicleModel::where('slug', 'mazda3-bl')->firstOrFail();
        $tb = VehicleModel::where('slug', 'cx-9-tb')->firstOrFail();
        $tc = VehicleModel::where('slug', 'cx-9-tc')->firstOrFail();
        foreach ([$bl, $tb] as $modele) {
            $piece->fitments()->create([
                'vehicle_model_id' => $modele->id,
                'year_from' => 2010,
                'year_to' => 2017,
                'source' => 'declared',
            ]);
        }
        $piece->fitments()->create([
            'vehicle_model_id' => $tc->id,
            'year_from' => 2016,
            'year_to' => null,
            'source' => 'declared',
        ]);

        $rapport = MazdaPe0118221Import::run();

        $piece->refresh();
        $this->assertFalse($rapport['part_created']);
        $this->assertTrue($rapport['part_updated']);
        $this->assertTrue($rapport['commerce_preserved']);
        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertSame(1, Part::where('sku', MazdaPe0118221Import::SKU)->count());
        $this->assertSame(MazdaPe0118221Import::NOM, $piece->name);
        $this->assertSame('capteurs-sondes', $piece->category?->slug);
        $this->assertSame('Chine', $piece->manufacturer?->name);
        $this->assertSame('aftermarket', $piece->type->value ?? $piece->type);
        $this->assertSame('PE01-18-221', $piece->manufacturer_reference);
        $this->assertEquals(20000, $piece->selling_price);
        $this->assertEquals(8000, $piece->cost_price);
        $this->assertSame(2, $piece->stock_quantity);
        $this->assertSame(1, $piece->stock_alert_threshold);
        $this->assertSame('Ouagadougou', $piece->storage_location);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertTrue((bool) $piece->is_available);

        $lignes = $piece->fitments()->with('vehicleModel')->get()->keyBy(fn ($f) => $f->vehicleModel->slug);
        $this->assertCount(11, $lignes);
        $this->assertArrayNotHasKey('cx-9-tb', $lignes->all());
        $this->assertSame(2012, (int) $lignes['mazda3-bl']->year_from);
        $this->assertSame(2013, (int) $lignes['mazda3-bl']->year_to);
        $this->assertSame(2016, (int) $lignes['cx-9-tc']->year_from);
        $this->assertSame(2023, (int) $lignes['cx-9-tc']->year_to);
        $this->assertSame($mazda->id, $lignes['mazda2-dj']->vehicleModel->brand_id);
    }

    public function test_rejouer_l_import_est_idempotent(): void
    {
        $this->catalogue();
        MazdaPe0118221Import::run();
        $id = Part::where('sku', MazdaPe0118221Import::SKU)->value('id');
        $fitments = Part::findOrFail($id)->fitments()->count();
        $oem = Part::findOrFail($id)->oemNumbers()->count();

        $rapport = MazdaPe0118221Import::run();

        $this->assertSame($id, $rapport['part_id']);
        $this->assertFalse($rapport['part_created']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(0, $rapport['fitments_removed']);
        $this->assertSame(1, Part::where('sku', MazdaPe0118221Import::SKU)->count());
        $this->assertSame($fitments, Part::findOrFail($id)->fitments()->count());
        $this->assertSame($oem, Part::findOrFail($id)->oemNumbers()->count());
        $this->assertEquals(20000, Part::findOrFail($id)->selling_price);
        $this->assertSame(2, Part::findOrFail($id)->stock_quantity);
        $this->assertNull(Part::findOrFail($id)->cost_price);
    }

    public function test_une_relance_ne_rend_pas_un_prix_ou_un_stock_poses_a_la_main(): void
    {
        $this->catalogue();
        MazdaPe0118221Import::run();

        $piece = Part::where('sku', MazdaPe0118221Import::SKU)->firstOrFail();
        $piece->update([
            'selling_price' => 25000,
            'stock_quantity' => 7,
            'cost_price' => 12000,
            'is_active' => false,
            'is_available' => false,
        ]);

        MazdaPe0118221Import::run();
        $piece->refresh();

        $this->assertEquals(25000, $piece->selling_price);
        $this->assertSame(7, $piece->stock_quantity);
        $this->assertEquals(12000, $piece->cost_price);
        $this->assertFalse((bool) $piece->is_active);
        $this->assertFalse((bool) $piece->is_available);
    }

    public function test_elle_signale_un_modele_absent_sans_le_creer(): void
    {
        $this->categorie();
        Manufacturer::firstOrCreate(
            ['slug' => 'chine'],
            ['name' => 'Chine', 'is_oem_supplier' => false],
        );
        $this->mazda();

        $rapport = MazdaPe0118221Import::run();

        $this->assertTrue($rapport['part_created']);
        $this->assertEqualsCanonicalizing(
            MazdaPe0118221Import::MODELES_RETENUS,
            $rapport['fitments_skipped_missing_model'],
        );
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertFalse(VehicleModel::whereIn('slug', MazdaPe0118221Import::MODELES_RETENUS)->exists());
    }

    public function test_l_apercu_de_partage_reste_lisible(): void
    {
        $this->catalogue();
        MazdaPe0118221Import::run();

        $piece = Part::where('sku', MazdaPe0118221Import::SKU)->firstOrFail();
        $texte = CompatibilitesPourApercu::texte($piece->fitments()->with('vehicleModel.brand')->orderBy('id')->get());

        $this->assertNotNull($texte);
        $this->assertStringStartsWith('Compatible : ', $texte);
        $this->assertStringContainsString('et ', $texte);
        $this->assertStringContainsString('autres', $texte);
        $this->assertStringContainsString('2012-2013', $texte);
    }

    public function test_elle_ne_cree_pas_de_modele_absent_du_catalogue(): void
    {
        $this->catalogue();
        MazdaPe0118221Import::run();

        $this->assertFalse(VehicleModel::where('slug', 'like', 'mx-5%')->exists());
        $this->assertFalse(VehicleModel::where('slug', 'like', 'cx-4%')->exists());
        $this->assertFalse(VehicleModel::where('slug', 'like', 'cx-70%')->exists());
        $this->assertFalse(VehicleModel::where('slug', 'like', 'cx-90%')->exists());
    }

    public function test_la_migration_delegue_a_l_import(): void
    {
        $this->catalogue();

        $migration = require base_path('database/migrations/2026_11_24_003500_ajouter_le_capteur_vilebrequin_pe01_18_221.php');
        $migration->up();

        $this->assertSame(1, Part::where('sku', MazdaPe0118221Import::SKU)->count());
        $this->assertSame(11, Part::where('sku', MazdaPe0118221Import::SKU)->firstOrFail()->fitments()->count());
    }
}
