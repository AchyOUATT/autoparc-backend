<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Database\Seeders\TwoWheelerCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoWheelerConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::create(['name' => 'Yamaha']);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'YBR125E',
            'default_vehicle_type' => 'two_wheeler',
        ]);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->identity = [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2024,
            'vehicle_type' => 'two_wheeler',
            'availability' => 'sale', 'sale_price' => 1_200_000,
        ];
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_declared_configuration_round_trips_on_public_detail_without_extending_body_style(): void
    {
        $configuration = [
            'body_type' => 'motorcycle',
            'stroke' => 'four_stroke',
            'cooling' => 'air',
            'starter' => 'both',
            'drive' => 'chain',
            'transmission' => 'manual',
        ];

        $response = $this->postJson('/api/vehicles', $this->identity + [
            'two_wheeler_configuration' => $configuration,
            'engine_displacement_cc' => 124,
            'vin' => 'YAMAHAFRAME01',
        ])->assertCreated()
            ->assertJsonPath('data.vehicle_type', 'two_wheeler')
            ->assertJsonPath('data.body_style', null)
            ->assertJsonPath('data.vin', 'YAMAHAFRAME01')
            ->assertJsonPath('data.technical.engine_displacement_cc', 124)
            ->assertJsonPath('data.technical.two_wheeler_configuration', $configuration)
            ->assertJsonPath('data.technical.two_wheeler_configuration_status', 'declared');

        $vehicle = Vehicle::findOrFail($response->json('data.id'));
        $this->assertSame($configuration, $vehicle->two_wheeler_configuration);
        $this->assertNull($vehicle->body_style);
        $vehicle->update(['published_at' => now()]);

        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.vehicle_type', 'two_wheeler')
            ->assertJsonPath('data.body_style', null)
            ->assertJsonPath('data.technical.two_wheeler_configuration.body_type', 'motorcycle')
            ->assertJsonPath('data.identity.brand', 'Yamaha')
            ->assertJsonPath('data.identity.model', 'YBR125E')
            ->assertJsonPath('data.commercial.sale_price', 1200000);
    }

    public function test_body_style_is_not_extended_with_motorcycle(): void
    {
        $this->postJson('/api/vehicles', $this->identity + ['body_style' => 'motorcycle'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body_style');
    }

    public function test_tricycle_body_type_is_rejected_in_this_wave(): void
    {
        $this->postJson('/api/vehicles', $this->identity + [
            'two_wheeler_configuration' => ['body_type' => 'tricycle'],
        ])->assertUnprocessable()->assertJsonValidationErrors('two_wheeler_configuration.body_type');
    }

    public function test_short_frame_number_is_accepted_only_for_two_wheelers(): void
    {
        $this->postJson('/api/vehicles', $this->identity + ['vin' => 'CADRE125'])
            ->assertCreated()->assertJsonPath('data.vin', 'CADRE125');

        $car = $this->identity;
        $car['vehicle_type'] = 'passenger';
        $car['body_style'] = 'sedan';
        $this->postJson('/api/vehicles', $car + ['vin' => 'CADRE125B', 'reference' => 'CAR-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vin');

        $this->postJson('/api/vehicles', $car + ['vin' => 'WVWZZZ1KZ8W000001', 'reference' => 'CAR-2'])
            ->assertCreated();
    }

    public function test_unknown_configuration_key_is_rejected(): void
    {
        $this->postJson('/api/vehicles', $this->identity + [
            'two_wheeler_configuration' => ['sidecar' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('two_wheeler_configuration');
    }

    public function test_customer_need_accepts_two_wheeler_type(): void
    {
        $this->postJson('/api/needs', [
            'description' => 'Je cherche une moto routiere 125 cm3.',
            'vehicle_type' => 'two_wheeler',
        ])->assertCreated();
        $this->assertSame('two_wheeler', \App\Models\CustomerNeed::firstOrFail()->vehicle_type);
    }

    public function test_catalog_seeder_is_idempotent_and_only_adds_verified_road_motorcycles(): void
    {
        Brand::create(['name' => 'Honda', 'slug' => 'honda']);
        $this->seed(TwoWheelerCatalogSeeder::class);
        $this->seed(TwoWheelerCatalogSeeder::class);

        $this->assertTrue(Brand::where('slug', 'yamaha')->exists());
        $this->assertTrue(Brand::where('slug', 'apsonic')->exists());
        $this->assertTrue(Brand::where('slug', 'tvs')->exists());

        $expected = [
            'yamaha' => ['YBR125E'],
            'honda' => ['CG110', 'Ace CB125', 'Ace CB125-D', 'Ace110'],
            'apsonic' => ['ALOBA', 'Z-ONE'],
            'tvs' => ['HLX 125', 'HLX Plus', 'Rockz'],
        ];
        foreach ($expected as $slug => $names) {
            $brandId = Brand::where('slug', $slug)->value('id');
            $this->assertEqualsCanonicalizing(
                $names,
                VehicleModel::where('brand_id', $brandId)->where('default_vehicle_type', 'two_wheeler')->pluck('name')->all(),
                $slug
            );
        }

        $this->assertFalse(VehicleModel::where('name', 'YBR125G')->exists());
        $this->assertFalse(VehicleModel::where('name', 'AG125')->exists());
        $this->assertFalse(VehicleModel::where('name', 'XTZ125E')->exists());
        $this->assertFalse(VehicleModel::where('name', 'NEO NX')->exists());
        $this->assertFalse(VehicleModel::where('name', 'King Deluxe')->exists());
        $this->assertFalse(VehicleModel::where('name', 'X-1')->exists());
        $this->assertFalse(VehicleModel::where('default_vehicle_type', 'two_wheeler')->where('name', 'Civic')->exists());
    }
}
