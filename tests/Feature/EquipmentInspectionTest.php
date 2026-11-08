<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\StaffVehicleInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\StaffVehicleInspections;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquipmentInspectionTest extends TestCase
{
    use RefreshDatabase;

    private function equipment(): Vehicle
    {
        return Vehicle::create([
            'reference' => 'EQUIPMENT-'.(Vehicle::withTrashed()->count() + 1),
            'asset_kind' => 'equipment', 'equipment_type' => 'aerial_platform',
            'brand_id' => null, 'vehicle_model_id' => null, 'engine_type_id' => null,
            'manufacturing_year' => null, 'vehicle_type' => null,
            'equipment_details' => ['brand_label' => 'Haulotte', 'model_label' => 'HA16',
                'operating_hours' => 1250.5, 'working_height_m' => 16],
            'registration_status' => 'unregistered', 'condition' => 'used', 'status' => 'in_stock',
            'availability' => 'sale', 'sale_price' => 5_000_000,
        ]);
    }

    private function inspect(Vehicle $vehicle): StaffVehicleInspection
    {
        return app(StaffVehicleInspections::class)->create('vehicle', $vehicle->id, [
            'inspected_at' => now()->subHour()->toIso8601String(), 'mileage_km' => 0,
            'summary' => 'Contrôle limité aux points observés.',
            'items' => [['label' => 'Levage', 'status' => 'ok']],
        ], User::factory()->create(['role' => 'mechanic']));
    }

    public function test_equipment_json_order_and_equivalent_numeric_values_preserve_the_report(): void
    {
        $vehicle = $this->equipment();
        $inspection = $this->inspect($vehicle);
        DB::table('vehicles')->where('id', $vehicle->id)->update(['equipment_details' => json_encode([
            'working_height_m' => '16.000', 'operating_hours' => '1250.50',
            'model_label' => 'HA16', 'brand_label' => 'Haulotte',
        ], JSON_THROW_ON_ERROR)]);

        $this->assertTrue(StaffVehicleInspections::isCurrent($inspection, $vehicle->fresh()));
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspection->id);

        $vehicle = $vehicle->fresh();
        $vehicle->update(['equipment_details' => [
            'brand_label' => 'Haulotte', 'model_label' => 'HA16',
            'operating_hours' => 1250.5, 'working_height_m' => 16,
        ], 'description' => 'Photos et description actualisées.']);

        $this->assertSame('active', $inspection->fresh()->status);
        $this->assertTrue(StaffVehicleInspections::isCurrent($inspection->fresh(), $vehicle->fresh()));
    }

    public function test_equipment_type_measurement_and_identity_changes_withdraw_active_reports(): void
    {
        $vehicle = $this->equipment();
        foreach ([
            ['equipment_type' => 'telehandler'],
            ['equipment_details' => array_replace($vehicle->equipment_details, ['operating_hours' => 1300])],
            ['equipment_details' => array_replace($vehicle->equipment_details, ['model_label' => 'HA18'])],
            ['equipment_details' => null],
        ] as $changes) {
            $inspection = $this->inspect($vehicle);
            $vehicle->refresh()->update($changes);
            $this->assertSame('withdrawn', $inspection->fresh()->status);
            $this->assertNull($inspection->fresh()->active_key);
            $this->getJson('/api/catalog/vehicle-inspections/'.$inspection->id)->assertNotFound();
        }
    }

    public function test_commercial_changes_keep_equipment_inspections_and_raw_measurement_changes_hide_them(): void
    {
        $vehicle = $this->equipment();
        $inspection = $this->inspect($vehicle);
        $vehicle->update(['sale_price' => 6_000_000, 'rental_weekly_rate' => 150_000,
            'description' => 'Disponible à la location.']);
        $this->assertSame('active', $inspection->fresh()->status);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspection->id);

        DB::table('vehicles')->where('id', $vehicle->id)->update(['equipment_details' => json_encode(
            array_replace($vehicle->equipment_details, ['working_height_m' => 18]), JSON_THROW_ON_ERROR,
        )]);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection', null);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspection->id)->assertNotFound();
    }

    public function test_road_default_preserves_the_frozen_pre_equipment_fingerprint(): void
    {
        $brand = Brand::create(['name' => 'MAN']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'TGX']);
        $engine = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $vehicle = Vehicle::create([
            'reference' => 'ROAD-LEGACY', 'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2022,
            'vehicle_type' => 'heavy', 'registration_status' => 'registered',
            'condition' => 'used', 'status' => 'in_stock', 'availability' => 'sale',
            'heavy_configuration' => ['cab_label' => 'GX', 'ptac_kg' => 18000],
        ])->fresh();
        $snapshot = [];
        // Frozen field list from before equipment registration existed.
        foreach ([
            'brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id', 'transmission',
            'vin', 'registration_status', 'vehicle_type', 'body_style', 'manufacturing_year',
            'gear_count', 'engine_displacement_cc', 'power_hp', 'power_kw', 'torque_nm',
            'cylinders', 'seats', 'doors', 'engine_code', 'condition', 'battery_capacity_kwh', 'fuel_tank_liters',
        ] as $field) {
            $value = $vehicle->getAttribute($field);
            $snapshot[$field] = $value instanceof BackedEnum ? $value->value : ($value === null ? null : (string) $value);
        }
        $snapshot['heavy_configuration'] = ['cab_label' => 'GX', 'ptac_kg' => 18000];
        $snapshot['registrationDetail'] = array_fill_keys(['mileage_km', 'has_accident_history', 'plate_number', 'first_registration_date'], null);
        $snapshot['importDetail'] = array_fill_keys(['odometer_at_import_km', 'steering_side', 'foreign_plate'], null);
        $legacy = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        $this->assertSame('road_vehicle', $vehicle->asset_kind);
        $this->assertSame($legacy, StaffVehicleInspections::fingerprint($vehicle));
        $inspection = $this->inspect($vehicle);
        $inspection->update(['technical_fingerprint' => $legacy]);
        $this->assertTrue(StaffVehicleInspections::isCurrent($inspection, $vehicle->fresh()));
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspection->id);
    }
}
