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
