<?php

namespace Tests\Feature;

use App\Http\Resources\MotorisationResource;
use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConsumptionReferenceRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::create(['name' => 'Toyota']);
        $this->model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
    }

    private function rating(array $attributes = []): Motorisation
    {
        return Motorisation::create(array_merge([
            'vehicle_model_id' => $this->model->id, 'source' => 'nrcan', 'cycle' => '5-cycle',
            'make_raw' => 'Toyota', 'model_raw' => 'Corolla',
            'model_year' => 2013, 'year_kind' => 'model_year', 'engine_l' => 1.8,
            'fuel_code' => 'X', 'consumption_combined' => 8.2,
            'method' => 'test', 'reference_status' => 'documented', 'verified_at' => now(),
            'cle_source' => bin2hex(random_bytes(16)),
        ], $attributes));
    }

    private function endpoint(array $criteria = []): string
    {
        return '/api/catalog/motorisations?'.http_build_query(['vehicle_model_id' => $this->model->id] + $criteria);
    }

    public function test_exact_engine_details_rank_first_without_hiding_conflicting_references_or_promoting_them(): void
    {
        $generic = $this->rating();
        $best = $this->rating(['displacement_cc' => 1798, 'cylinders' => 4]);
        $otherCc = $this->rating(['displacement_cc' => 1998, 'cylinders' => 4, 'consumption_combined' => 3.0]);
        $otherCylinders = $this->rating(['displacement_cc' => 1798, 'cylinders' => 6]);

        $response = $this->getJson($this->endpoint(['engine_displacement_cc' => 1798, 'cylinders' => 4]))
            ->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.complete', true)
            ->assertJsonPath('meta.ranked', true)->assertJsonPath('meta.automatic_selection', false)
            ->assertJsonPath('meta.recommended_ids', [$best->id])->assertJsonPath('data.0.id', $best->id)
            ->assertJsonPath('data.0.recommendation.rank', 1)
            ->assertJsonPath('data.0.recommendation.has_discriminating_match', true)
            ->assertJsonPath('data.0.match.level', 'incomplete')->assertJsonPath('data.0.consumption.combined_l_100km', null);
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($rows[$generic->id]['recommendation']['eligible']);
        $this->assertFalse($rows[$otherCc->id]['recommendation']['eligible']);
        $this->assertContains('engine_displacement_cc', $rows[$otherCc->id]['recommendation']['conflicting_criteria']);
        $this->assertFalse($rows[$otherCylinders->id]['recommendation']['eligible']);
        $this->assertContains('cylinders', $rows[$otherCylinders->id]['recommendation']['conflicting_criteria']);
        $this->assertEquals(3.0, $rows[$otherCc->id]['consumption']['reference_combined_l_100km']);
    }

    public function test_equally_matching_sources_and_cycles_remain_tied_and_the_lowest_number_does_not_win(): void
    {
        $canadian = $this->rating(['displacement_cc' => 1798, 'cylinders' => 4, 'consumption_combined' => 8.2]);
        $european = $this->rating(['displacement_cc' => 1798, 'cylinders' => 4,
            'source' => 'eea', 'cycle' => 'wltp', 'consumption_combined' => 6.4]);
        $response = $this->getJson($this->endpoint(['engine_displacement_cc' => 1798, 'cylinders' => 4]))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$canadian->id, $european->id], $response->json('meta.recommended_ids'));
        foreach ($response->json('data') as $row) {
            $this->assertSame(1, $row['recommendation']['rank']);
            $this->assertNull($row['consumption']['combined_l_100km']);
        }
        $this->assertEqualsCanonicalizing([8.2, 6.4], array_column(array_column($response->json('data'), 'consumption'), 'reference_combined_l_100km'));
    }

    public function test_manufacture_year_only_orders_ties_while_explicit_model_year_can_discriminate(): void
    {
        $older = $this->rating(['model_year' => 2012]);
        $sameManufactureYear = $this->rating(['model_year' => 2013]);
        $response = $this->getJson($this->endpoint(['year' => 2013]))->assertOk()
            ->assertJsonPath('data.0.id', $sameManufactureYear->id);
        $this->assertCount(2, $response->json('meta.recommended_ids'));
        foreach ($response->json('data') as $row) {
            $this->assertSame(1, $row['recommendation']['rank']);
            $this->assertFalse($row['recommendation']['has_discriminating_match']);
            $this->assertNotContains('model_year', $row['recommendation']['matched_criteria']);
            $this->assertContains('model_year', $row['recommendation']['missing_criteria']);
        }
        $this->getJson($this->endpoint(['year' => 2013, 'model_year' => 2012]))->assertOk()
            ->assertJsonPath('meta.recommended_ids', [$older->id])
            ->assertJsonPath('data.0.recommendation.has_discriminating_match', true)
            ->assertJsonPath('data.0.requested_year', 2013);
    }

    public function test_commercial_litres_never_claim_an_exact_cc_match(): void
    {
        $this->rating(['displacement_cc' => 1798]);
        $this->rating(['displacement_cc' => 1796]);
        $response = $this->getJson($this->endpoint(['engine_displacement_l' => 1.8]))->assertOk();
        $this->assertCount(2, $response->json('meta.recommended_ids'));
        foreach ($response->json('data') as $row) {
            $this->assertContains('engine_displacement_l', $row['recommendation']['matched_criteria']);
            $this->assertNotContains('engine_displacement_cc', $row['recommendation']['matched_criteria']);
            $this->assertContains('engine_displacement_cc', $row['recommendation']['missing_criteria']);
        }
    }

    public function test_metric_horsepower_is_converted_with_rounding_tolerance_for_ranking_only(): void
    {
        $first = $this->rating(['power_kw' => 100]);
        $second = $this->rating(['power_kw' => 100.2]);
        $wrong = $this->rating(['power_kw' => 90]);
        $response = $this->getJson($this->endpoint(['power_hp' => 136]))->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $response->json('meta.recommended_ids'));
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertContains('power_hp', $rows[$first->id]['recommendation']['matched_criteria']);
        $this->assertFalse($rows[$wrong->id]['recommendation']['eligible']);
        $this->assertContains('power_hp', $rows[$wrong->id]['recommendation']['conflicting_criteria']);
        $this->assertSame('incomplete', $rows[$first->id]['match']['level']);
    }

    public function test_a_registration_year_does_not_count_as_a_matching_model_year_and_market_conflicts_remain_visible(): void
    {
        $exact = $this->rating(['market' => 'CA']);
        $registration = $this->rating(['market' => 'CA', 'year_kind' => 'registration_year']);
        $otherMarket = $this->rating(['market' => 'US']);
        $response = $this->getJson($this->endpoint(['model_year' => 2013, 'specification_market' => 'ca']))
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.recommended_ids', [$exact->id]);
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertNotContains('model_year', $rows[$registration->id]['recommendation']['matched_criteria']);
        $this->assertFalse($rows[$otherMarket->id]['recommendation']['eligible']);
        $this->assertContains('specification_market', $rows[$otherMarket->id]['recommendation']['conflicting_criteria']);
    }

    public function test_all_conflicting_candidates_stay_inspectable_but_none_is_recommended(): void
    {
        $this->rating(['displacement_cc' => 1998]);
        $this->rating(['displacement_cc' => 2494]);
        $response = $this->getJson($this->endpoint(['engine_displacement_cc' => 1798]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.recommended_ids', []);
        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['recommendation']['eligible']);
            $this->assertFalse($row['recommendation']['has_discriminating_match']);
        }
    }

    public function test_missing_information_does_not_invent_a_winner_and_unranked_resources_are_explicit(): void
    {
        $first = $this->rating(['engine_l' => 2.5]);
        $second = $this->rating(['engine_l' => 3.5]);
        $response = $this->getJson($this->endpoint())->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $response->json('meta.recommended_ids'));
        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['recommendation']['has_discriminating_match']);
        }
        $standalone = (new MotorisationResource($first))->toArray(Request::create('/'));
        $this->assertNull($standalone['recommendation']);
    }

    public function test_clearly_different_commercial_displacements_are_not_recommended(): void
    {
        $this->rating(['engine_l' => 3.5, 'cylinders' => 4]);
        $this->rating(['engine_l' => 2.5, 'cylinders' => 4]);
        $response = $this->getJson($this->endpoint(['engine_displacement_l' => 1.8, 'cylinders' => 4]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.recommended_ids', []);
        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['recommendation']['eligible']);
            $this->assertContains('engine_displacement_l', $row['recommendation']['conflicting_criteria']);
            $this->assertNotContains('engine_displacement_cc', $row['recommendation']['conflicting_criteria']);
        }
    }

    public function test_matching_manufacturer_engine_code_precedes_a_generic_reference(): void
    {
        $generic = $this->rating();
        $exactCode = $this->rating(['engine_code' => '2ZR-FE']);
        $this->rating(['engine_code' => '1ZR-FE']);
        $response = $this->getJson($this->endpoint(['engine_code' => ' 2zr-fe ']))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.recommended_ids', [$exactCode->id]);
        $this->assertSame($exactCode->id, $response->json('data.0.id'));
        $this->assertContains('engine_code', $response->json('data.0.recommendation.matched_criteria'));
        $this->assertSame($generic->id, $response->json('data.1.id'));
    }

    public function test_supplemental_context_uses_the_garage_input_bounds(): void
    {
        foreach ([['engine_displacement_cc' => 30001], ['engine_displacement_l' => 1.2345],
            ['cylinders' => 25], ['power_hp' => 5001]] as $criteria) {
            $this->getJson($this->endpoint($criteria))->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($criteria));
        }
    }
}
