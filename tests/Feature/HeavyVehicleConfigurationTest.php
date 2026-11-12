<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\StaffVehicleInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\StaffVehicleInspections;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeavyVehicleConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::create(['name' => 'MAN']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'TGX']);
        $engine = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $this->identity = [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2024,
            'vehicle_type' => 'heavy', 'body_style' => 'truck',
            'availability' => 'sale', 'sale_price' => 20_000_000,
        ];
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function vehicle(array $extra = []): Vehicle
    {
        return Vehicle::create(array_replace($this->identity, [
            'reference' => 'HEAVY-'.(Vehicle::count() + 1),
            'condition' => 'used', 'status' => 'in_stock', 'published_at' => now(),
        ], $extra));
    }

    private function update(Vehicle $vehicle, array $changes)
    {
        // The stock endpoint still requires its core identity on PATCH.
        return $this->patchJson('/api/vehicles/'.$vehicle->id, array_replace($this->identity, $changes));
    }

    private function inspect(Vehicle $vehicle): StaffVehicleInspection
    {
        return app(StaffVehicleInspections::class)->create('vehicle', $vehicle->id, [
            'inspected_at' => now()->subHour()->toIso8601String(), 'mileage_km' => 1000,
            'summary' => 'Contrôle des points indiqués.', 'items' => [['label' => 'Freins', 'status' => 'ok']],
        ], User::factory()->create(['role' => 'mechanic']));
    }

    public function test_stock_configuration_round_trips_as_declared_and_is_visible_on_public_detail(): void
    {
        $configuration = [
            'version_label' => ' 18.520 ', 'cab_label' => ' GX ',
            'axle_configuration' => '6×2/4', 'chassis_type' => 'tractor',
            'body_use' => 'Transport longue distance', 'emission_standard' => 'Euro VI-E',
            'ptac_kg' => '18000', 'ptra_kg' => 44000,
        ];
        $expected = array_replace($configuration, ['version_label' => '18.520', 'cab_label' => 'GX', 'ptac_kg' => 18000]);
        $response = $this->postJson('/api/vehicles', $this->identity + ['heavy_configuration' => $configuration])
            ->assertCreated()->assertJsonPath('data.technical.heavy_configuration', $expected)
            ->assertJsonPath('data.technical.heavy_configuration_status', 'declared');
        $vehicle = Vehicle::findOrFail($response->json('data.id'));
        $this->assertSame($expected, $vehicle->heavy_configuration);
        $vehicle->update(['published_at' => now()]);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration', $expected)
            ->assertJsonPath('data.technical.heavy_configuration_status', 'declared')
            ->assertJsonMissingPath('data.technical.heavy_configuration.verified')
            ->assertJsonMissingPath('data.technical.heavy_configuration.source');
    }

    public function test_partial_updates_omission_category_changes_and_explicit_clears_are_distinct(): void
    {
        $vehicle = $this->vehicle(['heavy_configuration' => [
            'cab_label' => 'GX', 'axle_configuration' => '6x2*4', 'ptac_kg' => 26000, 'ptra_kg' => 44000,
        ]]);
        $this->update($vehicle, ['description' => 'Nouvelle description', 'vehicle_type' => 'passenger', 'body_style' => 'sedan'])
            ->assertOk()->assertJsonPath('data.technical.heavy_configuration.axle_configuration', '6x2*4');
        $this->update($vehicle, ['heavy_configuration' => ['cab_label' => null, 'axle_configuration' => '6x2/4']])
            ->assertOk()->assertJsonMissingPath('data.technical.heavy_configuration.cab_label')
            ->assertJsonPath('data.technical.heavy_configuration.axle_configuration', '6x2/4')
            ->assertJsonPath('data.technical.heavy_configuration.ptac_kg', 26000);
        $this->update($vehicle, ['heavy_configuration' => []])->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration.ptra_kg', 44000);
        $this->update($vehicle, ['heavy_configuration' => null])->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration', null)
            ->assertJsonPath('data.technical.heavy_configuration_status', null);
        $this->assertNull($vehicle->fresh()->heavy_configuration);
    }

    public function test_empty_configuration_normalizes_to_null_on_create_and_after_clearing_the_last_field(): void
    {
        $this->postJson('/api/vehicles', $this->identity + ['heavy_configuration' => ['cab_label' => ' ', 'ptra_kg' => null]])
            ->assertCreated()->assertJsonPath('data.technical.heavy_configuration', null)
            ->assertJsonPath('data.technical.heavy_configuration_status', null);
        $vehicle = $this->vehicle(['heavy_configuration' => ['cab_label' => 'GX']]);
        $this->update($vehicle, ['heavy_configuration' => ['cab_label' => null]])->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration', null);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'heavy_configuration' => null]);
    }

    public function test_declared_body_measures_round_trip_on_public_detail(): void
    {
        $configuration = [
            'body_type' => 'tanker',
            'body_use' => 'Citerne carburant',
            'useful_volume_m3' => '19.32',
            'tank_capacity_liters' => '45000',
            'payload_kg' => 60000,
        ];
        $expected = [
            'body_type' => 'tanker',
            'body_use' => 'Citerne carburant',
            'useful_volume_m3' => 19.32,
            'tank_capacity_liters' => 45000,
            'payload_kg' => 60000,
        ];
        $response = $this->postJson('/api/vehicles', $this->identity + ['heavy_configuration' => $configuration])
            ->assertCreated()->assertJsonPath('data.technical.heavy_configuration', $expected)
            ->assertJsonPath('data.body_style', 'truck');
        $vehicle = Vehicle::findOrFail($response->json('data.id'));
        $this->assertSame($expected, $vehicle->heavy_configuration);
        $vehicle->update(['published_at' => now()]);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration', $expected)
            ->assertJsonPath('data.body_style', 'truck');
    }

    public function test_body_style_stays_the_historical_truck_value(): void
    {
        $this->postJson('/api/vehicles', array_replace($this->identity, [
            'body_style' => 'tanker',
            'heavy_configuration' => ['body_type' => 'tanker'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('body_style');
    }

    public static function invalidConfigurations(): array
    {
        return [
            'raw JSON string' => ['{"cab_label":"GX"}', 'heavy_configuration'],
            'list' => [['GX'], 'heavy_configuration'],
            'unknown verified key' => [['verified' => true], 'heavy_configuration'],
            'unknown source key even null' => [['source' => null], 'heavy_configuration'],
            'forged nested status' => [['status' => 'verified'], 'heavy_configuration'],
            'bad chassis' => [['chassis_type' => 'SUV'], 'heavy_configuration.chassis_type'],
            'overlong version' => [['version_label' => str_repeat('a', 101)], 'heavy_configuration.version_label'],
            'overlong cabin' => [['cab_label' => str_repeat('a', 101)], 'heavy_configuration.cab_label'],
            'overlong axles' => [['axle_configuration' => str_repeat('a', 41)], 'heavy_configuration.axle_configuration'],
            'overlong use' => [['body_use' => str_repeat('a', 101)], 'heavy_configuration.body_use'],
            'overlong emission' => [['emission_standard' => str_repeat('a', 41)], 'heavy_configuration.emission_standard'],
            'array cabin' => [['cab_label' => ['GX']], 'heavy_configuration.cab_label'],
            'zero PTAC' => [['ptac_kg' => 0], 'heavy_configuration.ptac_kg'],
            'negative PTRA' => [['ptra_kg' => -1], 'heavy_configuration.ptra_kg'],
            'fraction PTAC' => [['ptac_kg' => 18000.5], 'heavy_configuration.ptac_kg'],
            'oversized PTRA' => [['ptra_kg' => 1000001], 'heavy_configuration.ptra_kg'],
            'inconsistent masses' => [['ptac_kg' => 26000, 'ptra_kg' => 18000], 'heavy_configuration.ptra_kg'],
            'unknown body type' => [['body_type' => 'tanker_truck'], 'heavy_configuration.body_type'],
            'historical body_style as body type' => [['body_type' => 'truck'], 'heavy_configuration.body_type'],
            'zero volume' => [['useful_volume_m3' => 0], 'heavy_configuration.useful_volume_m3'],
            'negative volume' => [['useful_volume_m3' => -1], 'heavy_configuration.useful_volume_m3'],
            'oversized volume' => [['useful_volume_m3' => 500.01], 'heavy_configuration.useful_volume_m3'],
            'zero tank' => [['tank_capacity_liters' => 0], 'heavy_configuration.tank_capacity_liters'],
            'fraction tank' => [['tank_capacity_liters' => 25000.5], 'heavy_configuration.tank_capacity_liters'],
            'oversized tank' => [['tank_capacity_liters' => 200001], 'heavy_configuration.tank_capacity_liters'],
            'zero payload' => [['payload_kg' => 0], 'heavy_configuration.payload_kg'],
            'oversized payload' => [['payload_kg' => 1000001], 'heavy_configuration.payload_kg'],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_data_is_rejected_without_saving(mixed $configuration, string $error): void
    {
        $this->postJson('/api/vehicles', $this->identity + ['heavy_configuration' => $configuration])
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_mass_validation_uses_merged_configuration_and_null_removes_a_constraint(): void
    {
        $vehicle = $this->vehicle(['heavy_configuration' => ['ptac_kg' => 26000, 'ptra_kg' => 44000]]);
        foreach ([['ptac_kg' => 45000], ['ptra_kg' => 20000]] as $changes) {
            $this->update($vehicle, ['heavy_configuration' => $changes])->assertUnprocessable()
                ->assertJsonValidationErrors('heavy_configuration.ptra_kg');
        }
        $this->assertSame(['ptac_kg' => 26000, 'ptra_kg' => 44000], $vehicle->fresh()->heavy_configuration);
        $this->update($vehicle, ['heavy_configuration' => ['ptac_kg' => 45000, 'ptra_kg' => null]])->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration', ['ptac_kg' => 45000]);
    }

    public function test_verification_status_is_response_only_even_when_null(): void
    {
        foreach (['verified', 'declared', null] as $status) {
            $this->postJson('/api/vehicles', $this->identity + [
                'heavy_configuration' => ['cab_label' => 'GX'], 'heavy_configuration_status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('heavy_configuration_status');
        }
    }

    public function test_stock_writes_keep_existing_role_restrictions(): void
    {
        $vehicle = $this->vehicle();
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $this->postJson('/api/vehicles', $this->identity + ['heavy_configuration' => ['cab_label' => 'GX']])->assertForbidden();
        $this->update($vehicle, ['heavy_configuration' => ['cab_label' => 'GX']])->assertForbidden();
        $this->assertNull($vehicle->fresh()->heavy_configuration);
    }

    public function test_partners_use_the_same_contract_only_on_their_own_vehicles(): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Transport', 'contact_name' => 'Responsable',
            'phone' => '+22670000001', 'status' => 'approved',
        ]);
        $foreign = $this->vehicle(['heavy_configuration' => ['cab_label' => 'Origine']]);
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/partner/vehicles', $this->identity + [
            'heavy_configuration' => ['cab_label' => 'GX', 'axle_configuration' => '6x2*4'],
        ])->assertCreated()->assertJsonPath('data.technical.heavy_configuration_status', 'declared');
        $path = '/api/partner/vehicles/'.$response->json('data.id');
        $this->patchJson($path, $this->identity + ['heavy_configuration' => ['cab_label' => 'GM']])->assertOk()
            ->assertJsonPath('data.technical.heavy_configuration.axle_configuration', '6x2*4');
        $this->getJson($path)->assertOk()->assertJsonPath('data.technical.heavy_configuration.cab_label', 'GM');
        $this->patchJson($path, $this->identity + ['heavy_configuration' => ['source' => 'manufacturer']])->assertUnprocessable();
        $this->patchJson('/api/partner/vehicles/'.$foreign->id, $this->identity + ['heavy_configuration' => null])->assertNotFound();
        $this->assertSame(['cab_label' => 'Origine'], $foreign->fresh()->heavy_configuration);
    }

    public function test_changing_or_clearing_configuration_withdraws_an_active_inspection(): void
    {
        $vehicle = $this->vehicle(['heavy_configuration' => ['cab_label' => 'GX']]);
        $inspection = $this->inspect($vehicle);
        $this->update($vehicle, ['heavy_configuration' => ['cab_label' => 'GM']])->assertOk();
        $this->assertSame('withdrawn', $inspection->fresh()->status);
        $this->assertNull($inspection->fresh()->active_key);
        $next = $this->inspect($vehicle);
        $this->update($vehicle, ['heavy_configuration' => null])->assertOk();
        $this->assertSame('withdrawn', $next->fresh()->status);
    }

    public function test_fingerprint_is_canonical_for_database_json_key_order_and_detects_raw_changes(): void
    {
        $vehicle = $this->vehicle(['heavy_configuration' => ['cab_label' => 'GX', 'ptac_kg' => 18000, 'ptra_kg' => 44000]]);
        $inspection = $this->inspect($vehicle);
        DB::table('vehicles')->where('id', $vehicle->id)->update([
            'heavy_configuration' => '{"ptra_kg":44000,"ptac_kg":18000,"cab_label":"GX"}',
        ]);
        $this->assertTrue(StaffVehicleInspections::isCurrent($inspection, $vehicle->fresh()));
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection.id', $inspection->id);
        DB::table('vehicles')->where('id', $vehicle->id)->update(['heavy_configuration' => '{"cab_label":"GM"}']);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection', null);
    }

    public function test_saving_an_identical_configuration_after_database_key_reordering_keeps_the_inspection_active(): void
    {
        $configuration = ['cab_label' => 'GX', 'ptac_kg' => 18000, 'ptra_kg' => 44000];
        $vehicle = $this->vehicle(['heavy_configuration' => $configuration]);
        $inspection = $this->inspect($vehicle);
        DB::table('vehicles')->where('id', $vehicle->id)->update([
            'heavy_configuration' => '{"ptra_kg":44000,"ptac_kg":18000,"cab_label":"GX"}',
        ]);

        $this->update($vehicle, ['heavy_configuration' => $configuration, 'description' => 'Description modifiée'])
            ->assertOk()->assertJsonPath('data.inspection.id', $inspection->id);

        $this->assertSame('active', $inspection->fresh()->status);
        $this->assertTrue(StaffVehicleInspections::isCurrent($inspection->fresh(), $vehicle->fresh()));
        $this->assertSame('Description modifiée', $vehicle->fresh()->description);
    }

    public function test_normalizing_equivalent_empty_configurations_keeps_the_inspection_active(): void
    {
        $vehicle = $this->vehicle();
        $inspection = $this->inspect($vehicle);
        foreach ([null, '{}', '{"ptra_kg":null,"cab_label":null}'] as $empty) {
            DB::table('vehicles')->where('id', $vehicle->id)->update(['heavy_configuration' => $empty]);

            $this->update($vehicle, ['heavy_configuration' => null])->assertOk()
                ->assertJsonPath('data.technical.heavy_configuration', null)
                ->assertJsonPath('data.inspection.id', $inspection->id);

            $this->assertSame('active', $inspection->fresh()->status);
            $this->assertTrue(StaffVehicleInspections::isCurrent($inspection->fresh(), $vehicle->fresh()));
        }
    }

    public function test_configuration_absence_preserves_legacy_inspection_hashes(): void
    {
        $vehicle = $this->vehicle()->fresh();
        // Frozen pre-feature snapshot contract; adding a nullable column must
        // not invalidate all previously published inspection reports.
        $fields = [
            'brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id', 'transmission',
            'vin', 'registration_status', 'vehicle_type', 'body_style', 'manufacturing_year',
            'gear_count', 'engine_displacement_cc', 'power_hp', 'power_kw', 'torque_nm',
            'cylinders', 'seats', 'doors', 'engine_code', 'condition', 'battery_capacity_kwh', 'fuel_tank_liters',
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            $value = $vehicle->getAttribute($field);
            $snapshot[$field] = $value instanceof BackedEnum ? $value->value : ($value === null ? null : (string) $value);
        }
        $snapshot['registrationDetail'] = array_fill_keys(['mileage_km', 'has_accident_history', 'plate_number', 'first_registration_date'], null);
        $snapshot['importDetail'] = array_fill_keys(['odometer_at_import_km', 'steering_side', 'foreign_plate'], null);
        $legacyHash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $this->assertSame($legacyHash, StaffVehicleInspections::fingerprint($vehicle));
        $inspection = $this->inspect($vehicle);
        $inspection->update(['technical_fingerprint' => $legacyHash]);
        foreach ([null, '{}', '{"cab_label":null}'] as $empty) {
            DB::table('vehicles')->where('id', $vehicle->id)->update(['heavy_configuration' => $empty]);
            $this->assertTrue(StaffVehicleInspections::isCurrent($inspection, $vehicle->fresh()));
        }
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection.id', $inspection->id);
        $this->update($vehicle, ['heavy_configuration' => ['cab_label' => 'GX']])->assertOk();
        $this->assertSame('withdrawn', $inspection->fresh()->status);
    }
}
