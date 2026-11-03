<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\OwnedVehicle;
use App\Models\PrivateVehicleListing;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\RentalService;
use App\Services\StaffVehicleInspections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnknownVehicleMileageTest extends TestCase
{
    use RefreshDatabase;

    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->identity = [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2020,
            'registration_status' => 'registered', 'availability' => 'sale',
            'sale_price' => 3000000,
        ];
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_a_registration_created_from_a_plate_has_unknown_mileage_by_default(): void
    {
        $id = $this->postJson('/api/vehicles', $this->identity + ['plate_number' => 'BF-1234'])
            ->assertCreated()->assertJsonPath('data.technical.mileage_km', null)->json('data.id');

        $this->assertNull(Vehicle::findOrFail($id)->registrationDetail->mileage_km);
    }

    public function test_absent_empty_and_explicit_zero_readings_remain_distinct_on_create_and_update(): void
    {
        foreach ([[], ['mileage_km' => null], ['mileage_km' => ''], ['mileage_km' => 0]] as $reading) {
            $expected = array_key_exists('mileage_km', $reading) && $reading['mileage_km'] === 0 ? 0 : null;
            $id = $this->postJson('/api/vehicles', $this->identity + [
                'registration' => $reading + ['previous_owners_count' => 1],
            ])->assertCreated()->assertJsonPath('data.technical.mileage_km', $expected)->json('data.id');
            $vehicle = Vehicle::findOrFail($id);
            $this->assertSame($expected, $vehicle->registrationDetail->mileage_km);
            $vehicle->update(['published_at' => now()]);
            $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()
                ->assertJsonPath('data.technical.mileage_km', $expected);

            $this->putJson('/api/vehicles/'.$id, $this->identity + ['registration' => ['mileage_km' => 0]])
                ->assertOk()->assertJsonPath('data.technical.mileage_km', 0);
            $this->putJson('/api/vehicles/'.$id, $this->identity + ['registration' => ['mileage_km' => null]])
                ->assertOk()->assertJsonPath('data.technical.mileage_km', null);
            $this->assertNull($vehicle->fresh()->registrationDetail->mileage_km);
        }
    }

    public function test_unknown_registered_mileage_never_satisfies_a_maximum_mileage_filter(): void
    {
        foreach ([null, 0, 100] as $mileage) {
            $id = $this->postJson('/api/vehicles', $this->identity + [
                'registration' => ['mileage_km' => $mileage],
            ])->assertCreated()->json('data.id');
            Vehicle::findOrFail($id)->update(['published_at' => now()]);
        }
        $this->getJson('/api/catalog/vehicles?mileage_max=0')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.technical.mileage_km', 0);
        $this->getJson('/api/catalog/vehicle-listings?mileage_max=0')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.vehicle.technical.mileage_km', 0);
    }

    public function test_import_readings_have_a_nullable_integer_contract(): void
    {
        $vehicle = Vehicle::create($this->identity + ['reference' => 'KM-IMPORT']);
        $vehicle->update(['registration_status' => 'unregistered']);
        $detail = $vehicle->importDetail()->create(['odometer_at_import_km' => '0']);
        $this->assertSame(0, $detail->fresh()->odometer_at_import_km);
        $this->assertSame(0, $vehicle->fresh()->mileage_km);
        $detail->update(['odometer_at_import_km' => null]);
        $this->assertNull($vehicle->fresh()->mileage_km);
    }

    public function test_rental_excess_distance_is_not_calculated_from_an_unknown_starting_reading(): void
    {
        $vehicle = Vehicle::create($this->identity + ['reference' => 'KM-RENTAL']);
        $rental = Rental::factory()->create([
            'vehicle_id' => $vehicle->id, 'status' => 'ongoing',
            'start_at' => now()->subDay(), 'expected_return_at' => now(),
            'daily_rate' => 10000, 'mileage_start_km' => null,
            'mileage_limit_km' => 100, 'extra_km_rate' => 200,
        ]);
        app(RentalService::class)->checkin($rental, ['mileage_end_km' => 50000, 'actual_return_at' => now()]);
        $this->assertNull($rental->mileage_done_km);
        $this->assertEquals(0, $rental->extra_charges);

        $rental->update(['mileage_start_km' => 0]);
        app(RentalService::class)->checkin($rental, ['mileage_end_km' => 150, 'actual_return_at' => now()]);
        $this->assertSame(150, $rental->mileage_done_km);
        $this->assertEquals(10000, $rental->extra_charges);
    }

    public function test_requested_cleanup_removes_all_old_zero_vehicle_readings_and_preserves_other_values(): void
    {
        config()->set('private_sales.enabled', true);
        $staff = User::factory()->create(['role' => 'mechanic']);
        $sources = [];
        foreach ([0, 85000, null] as $index => $reading) {
            $vehicle = Vehicle::create($this->identity + ['reference' => 'CLEAN-REG-'.$index]);
            $vehicle->registrationDetail()->create(['mileage_km' => $reading]);
            $import = Vehicle::create(array_replace($this->identity, [
                'reference' => 'CLEAN-IMPORT-'.$index, 'registration_status' => 'unregistered',
            ]));
            $import->importDetail()->create(['odometer_at_import_km' => $reading]);
            $owned = OwnedVehicle::create([
                'user_id' => User::factory()->create(['role' => 'client'])->id,
                'brand_id' => $this->identity['brand_id'],
                'vehicle_model_id' => $this->identity['vehicle_model_id'],
                'manufacturing_year' => 2020, 'mileage_km' => $reading,
            ]);
            $private = PrivateVehicleListing::create([
                'user_id' => $owned->user_id, 'title' => 'Toyota Corolla',
                'year' => 2020, 'mileage_km' => $reading, 'price' => 3000000,
                'city' => 'Ouagadougou', 'description' => 'Véhicule de particulier.',
                'seller_name' => 'Propriétaire',
            ]);
            $sources[] = [$vehicle, $import, $owned, $private];
        }
        $reports = [];
        foreach ([$sources[0][0], $sources[0][3], $sources[1][0]] as $target) {
            $reports[] = app(StaffVehicleInspections::class)->create(
                $target instanceof Vehicle ? 'vehicle' : 'private_listing', $target->id, [
                    'inspected_at' => now(), 'mileage_km' => $target->mileage_km,
                    'summary' => 'Contrôle avant correction du compteur.',
                    'items' => [['label' => 'Freinage', 'status' => 'ok']],
                ], $staff,
            );
        }

        $migration = require database_path('migrations/2026_11_03_000004_clear_legacy_zero_vehicle_mileage.php');
        $migration->up();

        foreach ($sources as $index => [$vehicle, $import, $owned, $private]) {
            $expected = $index === 1 ? 85000 : null;
            $this->assertSame($expected, $vehicle->fresh()->mileage_km);
            $this->assertSame($expected, $import->fresh()->mileage_km);
            $this->assertSame($expected, $owned->fresh()->mileage_km);
            $this->assertSame($expected, $private->fresh()->mileage_km);
            $this->assertSame($index === 0 ? 2 : 1, $private->fresh()->revision);
        }
        foreach (array_slice($reports, 0, 2) as $report) {
            $this->assertSame('withdrawn', $report->fresh()->status);
            $this->assertNull($report->fresh()->active_key);
            $this->assertSame(0, $report->fresh()->mileage_km);
        }
        $this->assertSame('active', $reports[2]->fresh()->status);
        $sources[0][0]->registrationDetail()->update(['mileage_km' => 0]);
        $this->assertSame(0, $sources[0][0]->fresh()->mileage_km);
        $this->assertSame('withdrawn', $reports[0]->fresh()->status);
    }
}
