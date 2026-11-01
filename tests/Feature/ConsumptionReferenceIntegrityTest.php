<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsumptionReferenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Camry', 'generation' => 'XV50',
            'production_start' => 2011, 'production_end' => 2017,
        ]);
    }

    private function rating(array $attributes = []): Motorisation
    {
        return Motorisation::create(array_merge([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2013,
            'year_kind' => 'model_year', 'make_raw' => 'Toyota', 'model_raw' => 'Camry',
            'vehicle_model_id' => $this->model->id, 'brand_id' => $this->model->brand_id,
            'engine_l' => 2.5, 'cylinders' => 4, 'transmission_code' => 'AS6', 'fuel_code' => 'X',
            'consumption_city' => 9.5, 'consumption_highway' => 6.6, 'consumption_combined' => 8.2,
            'cle_source' => bin2hex(random_bytes(16)),
        ], $attributes));
    }

    private function vehicle(array $attributes = []): OwnedVehicle
    {
        $owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'reference-owner']);
        $this->actingAsClient($owner);

        return OwnedVehicle::create(array_merge([
            'user_id' => $owner->id, 'brand_id' => $this->model->brand_id,
            'vehicle_model_id' => $this->model->id, 'manufacturing_year' => 2013,
        ], $attributes));
    }

    public function test_sources_cycles_years_and_powers_remain_separate_candidates(): void
    {
        $this->rating(['power_kw' => 120]);
        $this->rating(['power_kw' => 150]);
        $this->rating(['source' => 'eea', 'cycle' => 'wltp', 'method' => 'aggregation']);
        $this->rating(['model_year' => 2012]);

        $response = $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id.'&year=2013')
            ->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.complete', true);

        foreach ($response->json('data') as $candidate) {
            $this->assertNull($candidate['consumption']['combined_l_100km']);
            $this->assertSame('incomplete', $candidate['match']['level']);
            $this->assertSame(8.2, $candidate['consumption']['reference_combined_l_100km']);
        }
    }

    public function test_candidate_search_does_not_truncate_before_matching(): void
    {
        $rows = [];
        for ($index = 0; $index < 405; $index++) {
            $rows[] = [
                'source' => 'nrcan', 'cycle' => 'unknown', 'model_year' => 2013,
                'make_raw' => 'Toyota', 'model_raw' => 'Camry '.$index,
                'vehicle_model_id' => $this->model->id, 'cle_source' => 'candidate-'.$index,
            ];
        }
        Motorisation::insert($rows);

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id)
            ->assertOk()->assertJsonCount(405, 'data')
            ->assertJsonPath('meta.complete', true)->assertJsonPath('meta.total', 405);
    }

    public function test_aggregation_is_a_comparison_and_registration_year_is_never_a_model_year(): void
    {
        $this->rating([
            'source' => 'eea', 'cycle' => 'wltp', 'year_kind' => 'registration_year',
            'market' => 'EU', 'method' => 'aggregation', 'reference_status' => 'documented',
            'provenance' => ['sample_size' => 50, 'population_complete' => false],
        ]);

        $response = $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id.'&year=2013')
            ->assertOk()->assertJsonPath('data.0.match.level', 'approximate')
            ->assertJsonPath('data.0.consumption.combined_l_100km', null)
            ->assertJsonPath('data.0.consumption.reference_combined_l_100km', 8.2)
            ->assertJsonPath('data.0.provenance.sample_size', 50);
        $this->assertContains('registration_year', $response->json('data.0.match.reasons'));
        $this->assertContains('market_unknown', $response->json('data.0.match.reasons'));
    }

    public function test_verified_source_and_equal_year_do_not_verify_the_vehicles_configuration(): void
    {
        $rating = $this->rating([
            'market' => 'CA', 'method' => 'test', 'reference_status' => 'documented', 'verified_at' => now(),
        ]);
        $vehicle = $this->vehicle([
            'model_year' => 2013, 'specification_market' => 'CA', 'motorisation_id' => $rating->id,
        ]);

        $this->getJson('/api/my/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.consumption.match.level', 'incomplete')
            ->assertJsonPath('data.consumption.combined_l_100km', null)
            ->assertJsonPath('data.consumption.reference_combined_l_100km', 8.2);
    }

    public function test_correcting_fuel_reassesses_retained_selection_without_substitution(): void
    {
        $petrol = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $diesel = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $rating = $this->rating();
        $vehicle = $this->vehicle(['engine_type_id' => $petrol->id, 'motorisation_id' => $rating->id]);

        $response = $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['engine_type_id' => $diesel->id])
            ->assertOk()->assertJsonPath('data.motorisation_id', $rating->id)
            ->assertJsonPath('data.consumption.combined_l_100km', null)
            ->assertJsonPath('data.consumption.match.level', 'incomplete');
        $this->assertContains('fuel_mismatch', $response->json('data.consumption.match.reasons'));

        $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['motorisation_id' => $rating->id])
            ->assertStatus(422)->assertJsonValidationErrors('motorisation_id');
    }

    public function test_explicit_model_year_is_distinct_from_manufacture_and_reassessed(): void
    {
        $rating = $this->rating();
        $vehicle = $this->vehicle(['motorisation_id' => $rating->id]);

        $response = $this->patchJson('/api/my/vehicles/'.$vehicle->id, [
            'model_year' => 2014, 'specification_market' => ' us ',
        ])->assertOk()->assertJsonPath('data.year', 2013)
            ->assertJsonPath('data.model_year', 2014)->assertJsonPath('data.specification_market', 'US')
            ->assertJsonPath('data.consumption.requested_year', 2013);
        $this->assertContains('model_year_mismatch', $response->json('data.consumption.match.reasons'));
    }

    public function test_superseded_selection_stays_inspectable_and_withdrawn_records_are_not_offered(): void
    {
        $rating = $this->rating(['is_current' => false]);
        $this->rating(['reference_status' => 'withdrawn']);
        $vehicle = $this->vehicle(['motorisation_id' => $rating->id]);

        $this->getJson('/api/my/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.motorisation_id', $rating->id)
            ->assertJsonPath('data.consumption.reference_combined_l_100km', 8.2)
            ->assertJsonPath('data.consumption.is_current', false);
        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_candidate_search_uses_the_same_trim_defaults_as_selection(): void
    {
        $petrol = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $awd = Drivetrain::create(['code' => 'AWD', 'label' => 'Integral']);
        Drivetrain::create(['code' => 'FWD', 'label' => 'Traction']);
        $trim = Trim::create([
            'vehicle_model_id' => $this->model->id, 'name' => 'LE',
            'default_engine_type_id' => $petrol->id, 'default_drivetrain_id' => $awd->id,
        ]);
        $compatible = $this->rating(['provenance' => ['drivetrain_code' => 'AWD']]);
        $wrongFuel = $this->rating(['fuel_code' => 'D', 'provenance' => ['drivetrain_code' => 'AWD']]);
        $wrongDrivetrain = $this->rating(['provenance' => ['drivetrain_code' => 'FWD']]);
        $vehicle = $this->vehicle(['trim_id' => $trim->id]);

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id.'&trim_id='.$trim->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $compatible->id);
        $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['motorisation_id' => $compatible->id])->assertOk();
        foreach ([$wrongFuel, $wrongDrivetrain] as $excluded) {
            $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['motorisation_id' => $excluded->id])
                ->assertStatus(422)->assertJsonValidationErrors('motorisation_id');
        }
    }

    public function test_explicit_engine_and_drivetrain_override_trim_defaults_in_search_and_selection(): void
    {
        $petrol = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $diesel = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $awd = Drivetrain::create(['code' => 'AWD', 'label' => 'Integral']);
        $fwd = Drivetrain::create(['code' => 'FWD', 'label' => 'Traction']);
        $trim = Trim::create([
            'vehicle_model_id' => $this->model->id, 'name' => 'LE',
            'default_engine_type_id' => $petrol->id, 'default_drivetrain_id' => $awd->id,
        ]);
        $this->rating(['provenance' => ['drivetrain_code' => 'AWD']]);
        $explicit = $this->rating(['fuel_code' => 'D', 'provenance' => ['drivetrain_code' => 'FWD']]);
        $vehicle = $this->vehicle([
            'trim_id' => $trim->id, 'engine_type_id' => $diesel->id, 'drivetrain_id' => $fwd->id,
        ]);

        $query = http_build_query([
            'vehicle_model_id' => $this->model->id, 'trim_id' => $trim->id,
            'engine_type_id' => $diesel->id, 'drivetrain_id' => $fwd->id,
        ]);
        $this->getJson('/api/catalog/motorisations?'.$query)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $explicit->id);
        $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['motorisation_id' => $explicit->id])->assertOk();
    }

    public function test_candidate_search_rejects_a_trim_belonging_to_another_model(): void
    {
        $other = VehicleModel::create(['brand_id' => $this->model->brand_id, 'name' => 'Corolla']);
        $trim = Trim::create(['vehicle_model_id' => $other->id, 'name' => 'LE']);

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id.'&trim_id='.$trim->id)
            ->assertStatus(422)->assertJsonValidationErrors('trim_id');
    }

    public static function incompatibleFuels(): array
    {
        return [['petrol', 'B'], ['diesel', 'B'], ['electric', 'H'], ['hybrid', 'B'], ['petrol', 'D'], ['diesel', 'X']];
    }

    #[DataProvider('incompatibleFuels')]
    public function test_known_fuel_contradictions_are_excluded_and_rejected(string $engineCode, string $fuelCode): void
    {
        $engine = EngineType::create(['code' => $engineCode, 'label' => $engineCode]);
        $rating = $this->rating(['fuel_code' => $fuelCode]);
        $vehicle = $this->vehicle(['engine_type_id' => $engine->id]);

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->model->id.'&engine_type_id='.$engine->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['motorisation_id' => $rating->id])
            ->assertStatus(422)->assertJsonValidationErrors('motorisation_id');
    }
}
