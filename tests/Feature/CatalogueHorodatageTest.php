<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogueHorodatageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->seed([CountriesSeeder::class, BrandsSeeder::class, VehicleModelsSeeder::class]);
    }

    private function rejouerReferentiel(): void
    {
        $this->seed([BrandsSeeder::class, VehicleModelsSeeder::class]);
    }

    private function delta(string $cursor): array
    {
        return $this->getJson('/api/catalog/sync?'.http_build_query(['since' => $cursor]))
            ->assertOk()
            ->json();
    }

    public function test_les_references_creees_par_les_seeders_sont_horodatees(): void
    {
        $this->assertSame(0, DB::table('brands')->whereNull('updated_at')->count());
        $this->assertSame(0, DB::table('vehicle_models')->whereNull('updated_at')->count());
        $this->assertSame(0, DB::table('brands')->whereNull('created_at')->count());
        $this->assertSame(0, DB::table('vehicle_models')->whereNull('created_at')->count());

        // A client synchronized before the seed must discover the new rows.
        $delta = $this->delta(now()->subSecond()->toIso8601String());

        $this->assertCount(Brand::count(), $delta['brands']);
        $this->assertCount(VehicleModel::count(), $delta['vehicle_models']);
    }

    public function test_un_rejeu_identique_ne_renvoie_pas_tout_le_catalogue(): void
    {
        // Sync may replay its boundary second; put these rows before it.
        $this->travel(1)->seconds();
        $cursor = $this->getJson('/api/catalog/sync')->assertOk()->json('server_time');
        $brand = Brand::where('slug', 'toyota')->firstOrFail();
        $model = VehicleModel::where('brand_id', $brand->id)->where('slug', 'corolla-e180')->firstOrFail();
        $brandDate = $brand->updated_at->toDateTimeString();
        $modelDate = $model->updated_at->toDateTimeString();

        $this->travel(2)->seconds();
        $this->rejouerReferentiel();

        $delta = $this->delta($cursor);

        $this->assertSame([], $delta['brands']);
        $this->assertSame([], $delta['vehicle_models']);
        $this->assertSame($brandDate, $brand->fresh()->updated_at->toDateTimeString());
        $this->assertSame($modelDate, $model->fresh()->updated_at->toDateTimeString());
    }

    public function test_une_correction_est_synchronisee_sans_remplacer_les_identifiants(): void
    {
        $brand = Brand::where('slug', 'toyota')->firstOrFail();
        $model = VehicleModel::where('brand_id', $brand->id)->where('slug', 'corolla-e180')->firstOrFail();
        $brandCreatedAt = $brand->created_at->toDateTimeString();
        $modelCreatedAt = $model->created_at->toDateTimeString();

        // Simulate old reference values while retaining their historical date.
        DB::table('brands')->where('id', $brand->id)->update(['oem_prefix' => 'ancien']);
        DB::table('vehicle_models')->where('id', $model->id)->update(['body_type' => 'ancien']);
        $this->travel(1)->seconds();
        $cursor = $this->getJson('/api/catalog/sync')->assertOk()->json('server_time');

        $this->travel(2)->seconds();
        $this->rejouerReferentiel();

        $delta = $this->delta($cursor);

        $this->assertSame([$brand->id], array_column($delta['brands'], 'id'));
        $this->assertSame([$model->id], array_column($delta['vehicle_models'], 'id'));
        $this->assertSame('90', $brand->fresh()->oem_prefix);
        $this->assertSame('berline', $model->fresh()->body_type);
        $this->assertSame($brandCreatedAt, $brand->fresh()->created_at->toDateTimeString());
        $this->assertSame($modelCreatedAt, $model->fresh()->created_at->toDateTimeString());
    }

    public function test_les_dates_nulles_historiques_sont_reparees_et_synchronisees(): void
    {
        $brand = Brand::where('slug', 'toyota')->firstOrFail();
        $model = VehicleModel::where('brand_id', $brand->id)->where('slug', 'corolla-e180')->firstOrFail();
        $brandCreatedAt = $brand->created_at->toDateTimeString();
        $modelCreatedAt = $model->created_at->toDateTimeString();

        DB::table('brands')->where('id', $brand->id)->update(['updated_at' => null]);
        DB::table('vehicle_models')->where('id', $model->id)->update(['updated_at' => null]);
        $this->travel(1)->seconds();
        $cursor = $this->getJson('/api/catalog/sync')->assertOk()->json('server_time');

        $this->travel(2)->seconds();
        $this->rejouerReferentiel();

        $delta = $this->delta($cursor);

        $this->assertSame([$brand->id], array_column($delta['brands'], 'id'));
        $this->assertSame([$model->id], array_column($delta['vehicle_models'], 'id'));
        $this->assertSame($brandCreatedAt, $brand->fresh()->created_at->toDateTimeString());
        $this->assertSame($modelCreatedAt, $model->fresh()->created_at->toDateTimeString());
    }
}
