<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\VehicleModel;
use App\Support\Toyota10001VerifiedImport;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Toyota10001VerifiedImportTest extends TestCase
{
    use RefreshDatabase;

    private function toyota(): Brand
    {
        return Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);
    }

    private function part(string $sku = '90915-10001'): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'verified-oil-filter'], ['name' => 'Filtres de test']);

        return Part::create([
            'sku' => $sku, 'name' => 'Filtre de test', 'part_category_id' => $category->id,
            'type' => 'oem', 'condition' => 'new', 'selling_price' => 4750,
            'stock_quantity' => 8, 'is_active' => true,
        ])->refresh();
    }

    private function migrateImport(): void
    {
        $migration = require database_path('migrations/2026_11_09_000001_import_verified_toyota_10001_applications.php');
        $migration->up();
    }

    public function test_existing_database_gets_eight_references_and_only_five_documented_fitments(): void
    {
        $brand = $this->toyota();
        $part = $this->part();
        $other = $this->part('90915-10003');
        $commerceBefore = $part->getAttributes();

        $this->migrateImport();

        $this->assertSame(8, VehicleModel::where('brand_id', $brand->id)->count());
        $this->assertSame(5, $part->fitments()->count());
        $this->assertSame(0, $other->fitments()->count());
        $this->assertSame(0, DB::table('oem_number_fitments')->count());
        foreach ($part->fresh()->getAttributes() as $key => $value) {
            if ($key !== 'updated_at') {
                $this->assertSame($commerceBefore[$key], $value, $key.' must remain unchanged');
            }
        }

        $actual = $part->fitments()->with('vehicleModel')->get()->map(fn ($fitment) => [
            $fitment->vehicleModel->slug, $fitment->engine_code, $fitment->year_from, $fitment->year_to,
        ])->all();
        $this->assertEqualsCanonicalizing([
            ['corolla-e100', '4A-FE', 1991, 1995],
            ['corolla-e110', '4A-FE', 1995, 2000],
            ['camry-xv10', '5S-FE', 1992, 1996],
            ['camry-xv20', '5S-FE', 1996, 2001],
            ['rav4-xa10', '3S-FE', 1994, 2000],
        ], $actual);
        foreach ($part->fitments as $fitment) {
            $this->assertSame(FitmentSource::Declared, $fitment->source);
            $this->assertStringContainsString('marché Japon (JP)', $fitment->notes);
            $this->assertStringContainsString('https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf', $fitment->notes);
            $this->assertStringContainsString('2026-11-09', $fitment->notes);
            $this->assertStringContainsString('contrôler le VIN', $fitment->notes);
        }
        $this->assertFalse($part->fitments()->whereIn('engine_code', ['1ZZ-FE', '1NZ-FE', '7A-FE'])->exists());
        $this->assertNull(VehicleModel::where('slug', 'corolla-e130')->firstOrFail()->production_end);
        $this->assertSame(1994, VehicleModel::where('slug', 'rav4-xa10')->firstOrFail()->production_start);
    }

    public function test_replay_keeps_reference_ids_fitment_ids_notes_and_dates(): void
    {
        $this->freezeTime();
        $this->toyota();
        $this->part();
        Toyota10001VerifiedImport::run();
        $before = $this->snapshot();
        $this->travel(2)->days();

        $report = Toyota10001VerifiedImport::run();
        $this->migrateImport();

        $this->assertSame(0, $report['models_inserted']);
        $this->assertSame(0, $report['fitments_inserted']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_shop_reference_and_identical_declaration_are_not_rewritten(): void
    {
        $brand = $this->toyota();
        $model = VehicleModel::create([
            'brand_id' => $brand->id, 'slug' => 'corolla-e100', 'name' => 'Corolla atelier',
            'generation' => 'E100', 'production_start' => 1992, 'production_end' => 1997,
            'is_active' => false,
        ]);
        $part = $this->part();
        $manual = $part->fitments()->create([
            'vehicle_model_id' => $model->id, 'engine_code' => '4A-FE',
            'year_from' => 1991, 'year_to' => 1995, 'source' => FitmentSource::Declared,
            'notes' => 'Déclaration atelier indépendante ; garder cette note.',
        ]);
        $beforeModel = $model->refresh()->getAttributes();
        $beforeManual = $manual->refresh()->getAttributes();

        $report = Toyota10001VerifiedImport::run();
        $this->seed(VehicleModelsSeeder::class);

        $this->assertSame(4, $report['fitments_inserted']);
        $this->assertSame($beforeModel, $model->fresh()->getAttributes());
        $this->assertSame($beforeManual, $manual->fresh()->getAttributes());
        $this->assertSame(5, $part->fitments()->count());
    }

    public function test_generations_belong_to_toyota_even_when_another_brand_uses_the_same_slug(): void
    {
        $toyota = $this->toyota();
        $otherBrand = Brand::create(['name' => 'Autre marque', 'slug' => 'other-brand']);
        $foreignModel = VehicleModel::create([
            'brand_id' => $otherBrand->id, 'slug' => 'corolla-e100', 'name' => 'Autre modèle',
        ]);
        $before = $foreignModel->refresh()->getAttributes();
        $part = $this->part();

        Toyota10001VerifiedImport::run();

        $this->assertSame($before, $foreignModel->fresh()->getAttributes());
        foreach ($part->fitments()->with('vehicleModel')->get() as $fitment) {
            $this->assertSame($toyota->id, $fitment->vehicleModel->brand_id);
        }
    }

    public function test_a_mislabelled_reference_aborts_and_rolls_back_all_earlier_additions(): void
    {
        $brand = $this->toyota();
        VehicleModel::create([
            'brand_id' => $brand->id, 'slug' => 'camry-xv10', 'name' => 'Référence corrigée à examiner',
            'generation' => 'XV20',
        ]);
        $this->part();
        $before = $this->snapshot();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('camry-xv10');

        try {
            Toyota10001VerifiedImport::run();
        } finally {
            $this->assertSame($before, $this->snapshot(), 'A conflicting canonical reference must roll back the complete import.');
        }
    }

    public function test_reference_seeding_adds_generations_but_never_imports_article_fitments(): void
    {
        $this->toyota();
        $part = $this->part();

        $this->seed(VehicleModelsSeeder::class);
        $this->seed(VehicleModelsSeeder::class);

        $this->assertSame(1, VehicleModel::where('slug', 'corolla-e130')->count());
        $this->assertSame(0, $part->fitments()->count());
    }

    public function test_missing_or_archived_article_never_creates_or_restores_stock(): void
    {
        $this->toyota();
        $report = Toyota10001VerifiedImport::run();
        $this->assertNull($report['part_id']);
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('vehicle_models', 8);

        $part = $this->part();
        $part->delete();
        $report = Toyota10001VerifiedImport::run();
        $this->assertNull($report['part_id']);
        $this->assertTrue($part->fresh()->trashed());
        $this->assertDatabaseCount('part_fitments', 0);
    }

    public function test_partner_article_with_same_sku_is_not_changed(): void
    {
        $this->toyota();
        $owner = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create([
            'user_id' => $owner->id, 'company_name' => 'Atelier test', 'contact_name' => 'Partenaire de test',
            'phone' => '+22670000001', 'status' => 'approved',
        ]);
        $part = $this->part();
        $part->update(['owner_partner_account_id' => $account->id]);
        $before = $part->getAttributes();

        $report = Toyota10001VerifiedImport::run();

        $this->assertNull($report['part_id']);
        $this->assertSame($before, $part->fresh()->getAttributes());
        $this->assertDatabaseCount('part_fitments', 0);
    }

    public function test_missing_brand_does_not_invent_a_brand_or_an_article(): void
    {
        $report = Toyota10001VerifiedImport::run();

        $this->assertSame(['models_inserted' => 0, 'fitments_inserted' => 0, 'part_id' => null], $report);
        $this->assertDatabaseCount('brands', 0);
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('vehicle_models', 0);
    }

    public function test_compatibility_api_requires_the_documented_year_and_engine(): void
    {
        $this->toyota();
        $part = $this->part();
        Toyota10001VerifiedImport::run();
        $model = VehicleModel::where('slug', 'corolla-e100')->firstOrFail();
        $base = ['vehicle_model_id' => $model->id, 'year' => 1993, 'engine_code' => '4A-FE'];
        $path = static fn ($criteria) => '/api/catalog/compatible-parts?'.http_build_query($criteria);

        $this->getJson($path($base))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');
        foreach ([['year' => 1990], ['engine_code' => '7A-FE'], ['engine_code' => null]] as $changes) {
            $this->getJson($path(array_replace($base, $changes)))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson($path(array_replace($base, ['engine_code' => null, 'include_candidates' => 1])))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate');
    }

    public function test_rollback_preserves_reference_data_and_customer_links(): void
    {
        $this->toyota();
        $this->part();
        $this->migrateImport();
        $before = $this->snapshot();
        $migration = require database_path('migrations/2026_11_09_000001_import_verified_toyota_10001_applications.php');

        $migration->down();

        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        return collect(['vehicle_models', 'parts', 'part_fitments'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }
}
