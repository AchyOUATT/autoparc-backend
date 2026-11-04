<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\DrivetrainsSeeder;
use Database\Seeders\EngineTypesSeeder;
use Database\Seeders\TrimsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HyundaiProvisionalTrimsTest extends TestCase
{
    use RefreshDatabase;

    private array $backlog;
    private int $brandId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CountriesSeeder::class, BrandsSeeder::class, DrivetrainsSeeder::class,
            EngineTypesSeeder::class, VehicleModelsSeeder::class]);
        $this->brandId = Brand::where('slug', 'hyundai')->sole()->id;
        $this->backlog = json_decode(file_get_contents(database_path('catalogue/hyundai-candidates.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function model(string $slug): VehicleModel
    {
        return VehicleModel::where('brand_id', $this->brandId)->where('slug', $slug)->sole();
    }

    public function test_all_twelve_hyundai_entries_receive_only_the_proposed_and_historical_names(): void
    {
        $modelsBefore = DB::table('vehicle_models')->orderBy('id')->get()->toArray();
        $this->seed(TrimsSeeder::class);

        $newCount = $oldCount = 0;
        foreach ($this->backlog['entries'] as $entry) {
            $model = $this->model($entry['model_slug']);
            $expectedNames = array_column(array_merge($entry['proposed_trims'], $entry['existing_trims']), 'name');
            $trims = Trim::where('vehicle_model_id', $model->id)->get()->keyBy('name');
            $this->assertEqualsCanonicalizing($expectedNames, $trims->keys()->all());
            foreach ($entry['proposed_trims'] as $proposed) {
                $trim = $trims[$proposed['name']];
                $this->assertNull($trim->code);
                $this->assertNull($trim->default_engine_type_id);
                $this->assertNull($trim->default_drivetrain_id);
                $this->assertStringContainsString('provisoire', $trim->description);
                $this->assertSame(0, $trim->applications()->count());
                $newCount++;
            }
            $oldCount += count($entry['existing_trims']);
        }
        $this->assertSame(33, $newCount);
        $this->assertSame(7, $oldCount);
        $this->assertSame(12, count($this->backlog['entries']));
        $this->assertEquals($modelsBefore, DB::table('vehicle_models')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('trim_applications', 0);
    }

    public function test_the_backlog_keeps_every_unverified_scope_and_source_unknown(): void
    {
        $this->assertFalse($this->backlog['importable']);
        $this->assertFalse($this->backlog['origin']['manufacturer_evidence']);
        $this->assertSame(0, $this->backlog['counts']['documented_applications']);
        foreach ($this->backlog['entries'] as $entry) {
            $this->assertContains($entry['status'], ['proposed_pending_review', 'existing_pending_review']);
            $this->assertNull($entry['market']);
            $this->assertNull($entry['model_year_from']);
            $this->assertNull($entry['model_year_to']);
            $this->assertSame([], $entry['sources']);
            $this->assertArrayNotHasKey('verified_on', $entry);
        }
    }

    public function test_existing_seven_trims_and_any_enriched_candidate_are_never_overwritten(): void
    {
        $legacy = [];
        foreach ($this->backlog['entries'] as $entry) {
            foreach ($entry['existing_trims'] as $item) {
                $trim = Trim::create([
                    'vehicle_model_id' => $this->model($entry['model_slug'])->id,
                    'name' => $item['name'], 'code' => 'CUSTOM', 'rank' => 9,
                    'description' => 'Existing user-maintained description',
                    'default_engine_type_id' => DB::table('engine_types')->where('code', 'hybrid')->value('id'),
                    'default_drivetrain_id' => DB::table('drivetrains')->where('code', 'FWD')->value('id'),
                ]);
                $legacy[$trim->id] = $trim->fresh()->getAttributes();
            }
        }
        $candidate = Trim::create([
            'vehicle_model_id' => $this->model('tucson-lm')->id,
            'name' => 'GL', 'description' => 'Existing documented trim', 'code' => 'GL-EXISTING',
        ]);
        $application = $candidate->applications()->create([
            'market' => 'TEST', 'model_year_from' => 2014, 'model_year_to' => 2014,
            'sources' => [['url' => 'https://example.test/fixture', 'title' => 'Test fixture only', 'consulted_on' => '2026-01-01']],
            'verified_at' => '2026-01-01',
        ]);
        $candidateBefore = $candidate->fresh()->getAttributes();
        $applicationBefore = $application->fresh()->getAttributes();
        $vehicle = OwnedVehicle::create([
            'user_id' => User::factory()->create(['role' => 'client'])->id,
            'brand_id' => $this->brandId, 'vehicle_model_id' => $candidate->vehicle_model_id,
            'trim_id' => $candidate->id, 'manufacturing_year' => 2014,
        ]);
        $vehicleBefore = $vehicle->fresh()->getAttributes();

        $this->travel(1)->days();
        $this->seed(TrimsSeeder::class);

        $this->assertCount(7, $legacy);
        foreach ($legacy as $id => $attributes) {
            $this->assertSame($attributes, Trim::findOrFail($id)->getAttributes());
        }
        $this->assertSame($candidateBefore, $candidate->fresh()->getAttributes());
        $this->assertSame($applicationBefore, $application->fresh()->getAttributes());
        $this->assertSame($vehicleBefore, $vehicle->fresh()->getAttributes());
        $this->assertDatabaseCount('trim_applications', 1);
    }

    public function test_replaying_the_seed_changes_neither_ids_nor_timestamps(): void
    {
        $this->seed(TrimsSeeder::class);
        $before = DB::table('trims')->orderBy('id')->get()->toArray();
        $this->travel(1)->days();
        $this->seed(TrimsSeeder::class);
        $this->assertEquals($before, DB::table('trims')->orderBy('id')->get()->toArray());
    }

    public function test_public_reference_contains_the_provisional_names_without_documented_applications(): void
    {
        $this->seed(TrimsSeeder::class);
        $response = $this->getJson('/api/catalog/brands/'.$this->brandId.'/models')->assertOk();
        $count = 0;
        foreach ($response->json('data') as $model) {
            foreach ($model['trims'] as $trim) {
                $this->assertSame([], $trim['applications']);
                $count++;
            }
        }
        $this->assertSame(40, $count);
        $this->artisan('catalog:import-trims', ['file' => database_path('catalogue/hyundai-candidates.json')])->assertFailed();
        $this->assertDatabaseCount('trim_applications', 0);
    }
}
