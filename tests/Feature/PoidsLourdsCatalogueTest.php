<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\User;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoidsLourdsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        'mercedes-benz' => ['Actros', 'Axor', 'Atego'],
        'man' => ['TGX', 'TGS', 'TGL'],
        'scania' => ['R-Series', 'S-Series'],
        'volvo' => ['FH', 'FM'],
        'iveco' => ['Stralis', 'S-Way'],
        'daf' => ['XF', 'CF'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CountriesSeeder::class, BrandsSeeder::class, VehicleModelsSeeder::class]);
    }

    public function test_families_are_selectable_and_classified_without_invented_details(): void
    {
        $sync = $this->getJson('/api/catalog/sync')->assertOk();
        $models = collect($sync->json('vehicle_models'))->keyBy('id');
        foreach (self::MODELS as $slug => $names) {
            $brand = Brand::where('slug', $slug)->firstOrFail();
            $response = $this->getJson('/api/catalog/brands/'.$brand->id.'/models')->assertOk();
            foreach ($names as $name) {
                $model = $brand->vehicleModels()->where('name', $name)->firstOrFail();
                $this->assertContains($model->id, array_column($response->json('data'), 'id'));
                $this->assertSame('truck', $models[$model->id]['body_type']);
                $this->assertSame('heavy', $models[$model->id]['default_vehicle_type']);
                $this->assertNull($model->generation);
                $this->assertNull($model->production_start);
                $this->assertNull($model->production_end);
                $this->assertNull($model->segment);
                $this->assertSame(0, $model->trims()->count());
            }
        }
        $this->assertSame(14, VehicleModel::where('body_type', 'truck')->count());
        foreach (['man' => 'DE', 'scania' => 'SE', 'volvo' => 'SE', 'iveco' => 'IT', 'daf' => 'NL'] as $slug => $country) {
            $brand = Brand::where('slug', $slug)->firstOrFail();
            $this->assertSame($country, $brand->country->iso2);
            $this->assertNull($brand->oem_prefix);
        }
    }

    public function test_seeders_preserve_ids_and_an_existing_oem_prefix(): void
    {
        $brand = Brand::where('slug', 'volvo')->firstOrFail();
        $brand->update(['oem_prefix' => 'EXISTING-OEM']);
        $ids = VehicleModel::where('body_type', 'truck')->pluck('id')->all();
        $this->seed([BrandsSeeder::class, VehicleModelsSeeder::class]);
        $this->assertSame($ids, VehicleModel::where('body_type', 'truck')->pluck('id')->all());
        $this->assertSame('EXISTING-OEM', $brand->fresh()->oem_prefix);
        $this->assertSame(1, Brand::where('slug', 'mercedes-benz')->count());
        $this->assertSame(0, Brand::where('slug', 'mercedes')->count());
    }

    public function test_existing_garage_endpoint_accepts_all_heavy_families_without_a_trim(): void
    {
        $this->actingAsClient(User::factory()->create(['role' => 'client', 'firebase_uid' => 'heavy-owner']));
        foreach (VehicleModel::where('body_type', 'truck')->get() as $model) {
            $this->postJson('/api/my/vehicles', [
                'brand_id' => $model->brand_id, 'vehicle_model_id' => $model->id,
                'manufacturing_year' => 2012,
            ])->assertCreated()->assertJsonPath('data.vehicle_model_id', $model->id)
                ->assertJsonPath('data.trim_id', null)->assertJsonPath('data.compatibility_ready', false);
        }
    }

    public function test_existing_stock_endpoint_accepts_a_heavy_vehicle(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $model = VehicleModel::where('name', 'Actros')->firstOrFail();
        $engine = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $response = $this->postJson('/api/vehicles', [
            'brand_id' => $model->brand_id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2012,
            'vehicle_type' => 'heavy', 'body_style' => 'truck',
        ])->assertCreated();
        $this->assertDatabaseHas('vehicles', [
            'id' => $response->json('data.id'), 'vehicle_type' => 'heavy', 'body_style' => 'truck',
        ]);
    }
}
