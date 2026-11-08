<?php

namespace Tests\Feature;

use App\Http\Resources\VehicleResource;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\CotesOfficielles;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsumptionListingReferencesTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $brand = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);
        $this->model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Camry', 'generation' => 'XV50',
            'production_start' => 2011, 'production_end' => 2017,
        ]);
    }

    private function reference(array $attributes = []): Motorisation
    {
        $attributes = array_merge([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2013,
            'make_raw' => 'Toyota', 'model_raw' => 'Camry',
            'engine_l' => 2.5, 'cylinders' => 4, 'transmission_code' => 'AS6',
            'fuel_code' => 'X', 'hybrid_type' => 'none', 'consumption_combined' => 8.2,
            'vehicle_model_id' => $this->model->id, 'market' => 'CA',
            'year_kind' => 'model_year', 'method' => 'test',
            'reference_status' => 'documented',
        ], $attributes);
        $attributes['cle_source'] ??= hash('sha256', json_encode($attributes));

        return Motorisation::create($attributes);
    }

    private function listing(array $attributes = []): Vehicle
    {
        return Vehicle::factory()->create(array_merge([
            'brand_id' => $this->model->brand_id,
            'vehicle_model_id' => $this->model->id,
            'trim_id' => null,
            'engine_type_id' => EngineType::where('code', 'petrol')->value('id'),
            'manufacturing_year' => 2013,
            'transmission' => null,
            'gear_count' => null,
            'engine_displacement_cc' => null,
            'cylinders' => null,
            'power_hp' => null,
            'power_kw' => null,
        ], $attributes));
    }

    public function test_sources_and_protocols_remain_separate_comparisons_on_the_listing(): void
    {
        $canadian = $this->reference();
        $european = $this->reference([
            'source' => 'eea', 'cycle' => 'wltp', 'method' => 'aggregation',
            'year_kind' => 'registration_year', 'market' => 'EU',
            'consumption_combined' => 7.4,
        ]);
        $historical = $this->reference([
            'cycle' => '2-cycle', 'method' => 'adjustment', 'consumption_combined' => 6.5,
        ]);

        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing()->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.official', null)
            ->assertJsonCount(3, 'data.consumption.references');

        $references = collect($response->json('data.consumption.references'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$canadian->id, $european->id, $historical->id], $references->keys()->all());
        $this->assertSame('nrcan', $references[$canadian->id]['source']['code']);
        $this->assertSame('eea', $references[$european->id]['source']['code']);
        $this->assertSame('norme WLTP', $references[$european->id]['source']['cycle']);
        $this->assertSame('essai canadien, deux cycles', $references[$historical->id]['source']['cycle']);
        $this->assertSame(8.2, $references[$canadian->id]['consumption']['reference_combined_l_100km']);
        $this->assertSame(7.4, $references[$european->id]['consumption']['reference_combined_l_100km']);
        $this->assertSame(6.5, $references[$historical->id]['consumption']['reference_combined_l_100km']);
        foreach ($references as $reference) {
            $this->assertNotSame('corresponding', $reference['match']['level']);
            $this->assertNull($reference['consumption']['combined_l_100km']);
            $this->assertContains('market_unknown', $reference['match']['reasons']);
        }
    }

    public function test_an_unknown_engine_keeps_thermal_hybrid_and_diesel_references_distinct(): void
    {
        $petrol = $this->reference();
        $hybrid = $this->reference(['hybrid_type' => 'hybrid', 'consumption_combined' => 4.5]);
        $diesel = $this->reference(['fuel_code' => 'D', 'consumption_combined' => 5.7]);

        // The search may start with only a model; missing engine information
        // is not permission to attribute a combined range to the vehicle.
        $vehicle = new Vehicle([
            'vehicle_model_id' => $this->model->id, 'manufacturing_year' => 2013,
        ]);
        $vehicle->cotesOfficielles = CotesOfficielles::pourVehicule($vehicle);
        $consumption = (new VehicleResource($vehicle))->resolve(request())['consumption'];

        $this->assertNull($consumption['official']);
        $references = collect($consumption['references'])->keyBy('id');
        $this->assertEqualsCanonicalizing([$petrol->id, $hybrid->id, $diesel->id], $references->keys()->all());
        $this->assertSame('hybrid', $references[$hybrid->id]['hybrid_type']);
        $this->assertSame('Diesel', $references[$diesel->id]['fuel']);
        $this->assertNull($references[$hybrid->id]['consumption']['combined_l_100km']);
    }

    public function test_a_known_engine_excludes_documented_incompatible_candidates(): void
    {
        $petrol = $this->reference();
        $this->reference(['fuel_code' => 'D']);
        $this->reference(['hybrid_type' => 'hybrid']);

        $this->getJson('/api/catalog/vehicles/'.$this->listing()->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.official', null)
            ->assertJsonCount(1, 'data.consumption.references')
            ->assertJsonPath('data.consumption.references.0.id', $petrol->id);
    }

    public function test_a_manual_listing_excludes_automatic_references_without_guessing_unknown_codes(): void
    {
        $this->reference(['transmission_code' => 'AS6']);
        $manual = $this->reference(['transmission_code' => 'M6']);
        $unknown = $this->reference(['transmission_code' => null]);
        $unrecognized = $this->reference(['transmission_code' => 'UNSPEC']);

        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing(['transmission' => 'manual'])->id)
            ->assertOk()->assertJsonCount(3, 'data.consumption.references');
        $this->assertEqualsCanonicalizing(
            [$manual->id, $unknown->id, $unrecognized->id],
            array_column($response->json('data.consumption.references'), 'id'),
        );

        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing(['transmission' => 'automatic'])->id)
            ->assertOk()->assertJsonCount(3, 'data.consumption.references');
        $this->assertNotContains($manual->id, array_column($response->json('data.consumption.references'), 'id'));
    }

    public function test_only_documented_fixed_gear_counts_exclude_a_reference(): void
    {
        $matching = $this->reference(['transmission_code' => 'M6']);
        $this->reference(['transmission_code' => 'M5']);
        $unknownCount = $this->reference(['transmission_code' => 'M']);
        $variable = $this->reference(['transmission_code' => 'AV1']);

        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing(['gear_count' => 6])->id)
            ->assertOk()->assertJsonCount(3, 'data.consumption.references');
        $this->assertEqualsCanonicalizing(
            [$matching->id, $unknownCount->id, $variable->id],
            array_column($response->json('data.consumption.references'), 'id'),
        );
    }

    public function test_seller_entered_consumption_is_preserved_separately(): void
    {
        $this->reference();

        $this->getJson('/api/catalog/vehicles/'.$this->listing([
            'consumption_urban' => 9.1, 'consumption_extra_urban' => 6.7,
            'consumption_combined' => 7.8, 'electric_consumption_kwh' => 16.2,
        ])->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.urban_l_100km', '9.10')
            ->assertJsonPath('data.consumption.extra_urban_l_100km', '6.70')
            ->assertJsonPath('data.consumption.combined_l_100km', '7.80')
            ->assertJsonPath('data.consumption.electric_kwh_100km', '16.20')
            ->assertJsonPath('data.consumption.official', null)
            ->assertJsonPath('data.consumption.references.0.consumption.reference_combined_l_100km', 8.2);
    }

    public function test_listing_recommendations_use_engine_details_instead_of_the_lowest_consumption(): void
    {
        $wrong = $this->reference([
            'engine_l' => 3.5, 'displacement_cc' => 3456, 'cylinders' => 6, 'consumption_combined' => 4.2,
        ]);
        $closest = $this->reference([
            'engine_l' => 2.5, 'displacement_cc' => 2494, 'cylinders' => 4, 'consumption_combined' => 8.2,
        ]);
        $unknown = $this->reference([
            'engine_l' => null, 'displacement_cc' => null, 'cylinders' => null, 'consumption_combined' => 3.5,
        ]);

        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing([
            'engine_displacement_cc' => 2494, 'cylinders' => 4,
        ])->id)->assertOk()->assertJsonCount(3, 'data.consumption.references')
            ->assertJsonPath('data.consumption.official', null)
            ->assertJsonPath('data.consumption.references.0.id', $closest->id);
        $references = collect($response->json('data.consumption.references'))->keyBy('id');
        $this->assertSame(1, $references[$closest->id]['recommendation']['rank']);
        $this->assertTrue($references[$closest->id]['recommendation']['eligible']);
        $this->assertTrue($references[$closest->id]['recommendation']['has_discriminating_match']);
        $this->assertContains('engine_displacement_cc', $references[$closest->id]['recommendation']['matched_criteria']);
        $this->assertFalse($references[$wrong->id]['recommendation']['eligible']);
        $this->assertContains('cylinders', $references[$wrong->id]['recommendation']['conflicting_criteria']);
        $this->assertGreaterThan(1, $references[$unknown->id]['recommendation']['rank']);
        foreach ($references as $reference) {
            $this->assertNotSame('corresponding', $reference['match']['level']);
            $this->assertNull($reference['consumption']['combined_l_100km']);
        }
    }

    public function test_equally_matching_sources_keep_the_same_rank_and_distinct_values(): void
    {
        $first = $this->reference(['displacement_cc' => 2494, 'consumption_combined' => 8.2]);
        $second = $this->reference([
            'source' => 'eea', 'cycle' => 'wltp', 'market' => 'EU', 'displacement_cc' => 2494,
            'consumption_combined' => 6.4,
        ]);
        $third = $this->reference([
            'cycle' => '2-cycle', 'model_year' => 2012, 'displacement_cc' => 2494,
            'consumption_combined' => 6.5,
        ]);
        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing([
            'engine_displacement_cc' => 2494, 'consumption_combined' => 7.8,
        ])->id)->assertOk()->assertJsonCount(3, 'data.consumption.references')
            ->assertJsonPath('data.consumption.combined_l_100km', '7.80')
            ->assertJsonPath('data.consumption.official', null);
        $references = collect($response->json('data.consumption.references'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], $references->keys()->all());
        $this->assertSame([1, 1, 1], $references->pluck('recommendation.rank')->all());
        $this->assertSame(8.2, $references[$first->id]['consumption']['reference_combined_l_100km']);
        $this->assertSame(6.4, $references[$second->id]['consumption']['reference_combined_l_100km']);
        $this->assertSame(6.5, $references[$third->id]['consumption']['reference_combined_l_100km']);
        foreach ($references as $reference) {
            $this->assertContains('model_year', $reference['recommendation']['missing_criteria']);
            $this->assertContains('specification_market', $reference['recommendation']['missing_criteria']);
            $this->assertNotContains('model_year', $reference['recommendation']['matched_criteria']);
            $this->assertNull($reference['consumption']['combined_l_100km']);
        }
    }

    public function test_listing_kw_and_metric_horsepower_are_compared_with_correct_units(): void
    {
        $matching = $this->reference(['power_kw' => 100]);
        $wrongUnits = $this->reference(['power_kw' => 136]);
        foreach ([['power_kw' => 100], ['power_hp' => 136]] as $power) {
            $vehicle = $this->listing($power);
            $response = $this->getJson('/api/catalog/vehicles/'.$vehicle->id)
                ->assertOk()->assertJsonCount(2, 'data.consumption.references')
                ->assertJsonPath('data.consumption.references.0.id', $matching->id);
            $references = collect($response->json('data.consumption.references'))->keyBy('id');
            $this->assertContains('power_hp', $references[$matching->id]['recommendation']['matched_criteria']);
            $this->assertTrue($references[$matching->id]['recommendation']['eligible']);
            $this->assertFalse($references[$wrongUnits->id]['recommendation']['eligible']);
            $this->assertSame($power['power_hp'] ?? null, $vehicle->fresh()->power_hp);
            $this->assertSame($power['power_kw'] ?? null, $vehicle->fresh()->power_kw);
        }
    }

    public function test_legacy_zero_engine_values_are_unknown_instead_of_documented_conflicts(): void
    {
        $this->reference(['displacement_cc' => 2494, 'power_kw' => 100]);
        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing([
            'engine_displacement_cc' => 0, 'cylinders' => 0, 'power_hp' => 0, 'power_kw' => 0,
        ])->id)->assertOk()->assertJsonCount(1, 'data.consumption.references');
        $recommendation = $response->json('data.consumption.references.0.recommendation');
        $this->assertTrue($recommendation['eligible']);
        $this->assertFalse($recommendation['has_discriminating_match']);
        $this->assertSame([], $recommendation['conflicting_criteria']);
        $this->assertContains('engine_displacement_cc', $recommendation['missing_criteria']);
        $this->assertContains('cylinders', $recommendation['missing_criteria']);
        $this->assertContains('power_hp', $recommendation['missing_criteria']);
    }

    public function test_conflicting_listing_details_remain_inspectable_without_a_recommendation(): void
    {
        $this->reference(['displacement_cc' => 2494, 'cylinders' => 4]);
        $this->reference(['engine_l' => 3.5, 'displacement_cc' => 3456, 'cylinders' => 6]);
        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing([
            'engine_displacement_cc' => 1598, 'cylinders' => 3,
        ])->id)->assertOk()->assertJsonCount(2, 'data.consumption.references')
            ->assertJsonPath('data.consumption.official', null);
        foreach ($response->json('data.consumption.references') as $reference) {
            $this->assertFalse($reference['recommendation']['eligible']);
            $this->assertFalse($reference['recommendation']['has_discriminating_match']);
            $this->assertNull($reference['consumption']['combined_l_100km']);
        }
    }

    public function test_manufacturing_year_only_orders_ties_and_does_not_claim_a_model_year_match(): void
    {
        $older = $this->reference(['model_year' => 2012]);
        $sameYear = $this->reference(['model_year' => 2013]);
        $response = $this->getJson('/api/catalog/vehicles/'.$this->listing()->id)
            ->assertOk()->assertJsonPath('data.consumption.references.0.id', $sameYear->id);
        $references = collect($response->json('data.consumption.references'))->keyBy('id');
        foreach ([$older->id, $sameYear->id] as $id) {
            $recommendation = $references[$id]['recommendation'];
            $this->assertSame(1, $recommendation['rank']);
            $this->assertFalse($recommendation['has_discriminating_match']);
            $this->assertNotContains('model_year', $recommendation['matched_criteria']);
            $this->assertNotContains('specification_market', $recommendation['matched_criteria']);
        }
    }

    public function test_the_listing_index_does_not_query_references_for_every_vehicle(): void
    {
        $this->reference();
        $this->listing();
        $this->listing();

        DB::enableQueryLog();
        $this->getJson('/api/catalog/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.consumption.official', null)
            ->assertJsonPath('data.0.consumption.references', [])
            ->assertJsonPath('data.1.consumption.references', []);
        $referenceQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains(strtolower($query['query']), 'motorisations'));
        DB::disableQueryLog();

        $this->assertCount(0, $referenceQueries);
    }

    public function test_a_model_without_references_returns_an_empty_list(): void
    {
        $this->getJson('/api/catalog/vehicles/'.$this->listing()->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.official', null)
            ->assertJsonPath('data.consumption.references', []);
    }
}
