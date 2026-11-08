<?php

namespace Tests\Feature;

use App\Console\Commands\ImportConsommations;
use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\DrivetrainsSeeder;
use Database\Seeders\EngineTypesSeeder;
use Database\Seeders\KiaCatalogueSeeder;
use Database\Seeders\TrimsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KiaCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private int $brandId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CountriesSeeder::class, BrandsSeeder::class, EngineTypesSeeder::class,
            DrivetrainsSeeder::class, VehicleModelsSeeder::class, TrimsSeeder::class]);
        $this->brandId = Brand::where('slug', 'kia')->sole()->id;
    }

    private function model(string $slug): VehicleModel
    {
        return VehicleModel::where('brand_id', $this->brandId)->where('slug', $slug)->sole();
    }

    private function kiaTrims()
    {
        return Trim::whereIn('vehicle_model_id', VehicleModel::where('brand_id', $this->brandId)->select('id'));
    }

    public function test_every_retained_batch_matches_the_reviewed_scoped_document_evidence(): void
    {
        $audit = json_decode(file_get_contents(database_path('catalogue/kia-verification.json')), true, flags: JSON_THROW_ON_ERROR);
        // Lock the reviewed data, not the live websites. A future catalogue edit
        // must also update its documentary review, including explicit limits.
        foreach ($audit['catalogue_hashes'] as $file => $hash) {
            $contents = str_replace("\r\n", "\n", file_get_contents(database_path('catalogue/'.$file)));
            $this->assertSame($hash, hash('sha256', $contents), $file);
        }
        $this->assertFalse($audit['global_production_dates_certified']);
        $this->assertCount(26, $audit['model_reviews']);

        $models = json_decode(file_get_contents(database_path('catalogue/kia-models.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($models['models'] as $model) {
            $this->assertSame('confirmed', $model['verification']['identity_status']);
            $this->assertFalse($model['verification']['global_production_dates_verified']);
            $this->assertNotEmpty($model['documented_year_scopes']);
            $this->assertNotEmpty($model['sources']);
        }

        $reviewed = collect([...$audit['trim_review']['entries'], $audit['trim_review']['legacy_au_support']]);
        $batch = json_decode(file_get_contents(database_path('catalogue/kia-trims-verified.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(19, $batch['entries']);
        foreach ($batch['entries'] as $entry) {
            $proof = $reviewed->first(fn ($candidate) => $candidate['model_slug'] === $entry['model_slug']
                && $candidate['market'] === $entry['market'] && $candidate['model_year_from'] === $entry['model_year_from']
                && $candidate['model_year_to'] === $entry['model_year_to']);
            $this->assertNotNull($proof, $entry['model_slug'].'/'.$entry['market']);
            $this->assertSame($proof['proposed_import_names'], array_column($entry['trims'], 'name'));
            $this->assertContains($entry['sources'][0]['url'], array_column($proof['sources'], 'url'));
            foreach ($proof['sources'] as $source) {
                $this->assertNotEmpty($source['publisher_authorship']);
                $this->assertNotEmpty($source['locator_pages_1_based']);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['downloaded_pdf_sha256']);
            }
        }
    }

    public function test_overlapping_generations_do_not_receive_an_arbitrary_consumption_reference(): void
    {
        $method = new \ReflectionMethod(ImportConsommations::class, 'modeleLePlusProche');
        $command = new ImportConsommations;
        $models = VehicleModel::where('brand_id', $this->brandId)->get();
        foreach ([$models, $models->reverse()->values()] as $ordered) {
            $this->assertNull($method->invoke($command, 'Cerato', 2012, $ordered, 'Kia'));
            $this->assertNull($method->invoke($command, 'Cerato', 2013, $ordered, 'Kia'));
            $this->assertNull($method->invoke($command, 'Cerato', 2019, $ordered, 'Kia'));
            $this->assertSame($this->model('cerato-td')->id, $method->invoke($command, 'Cerato', 2010, $ordered, 'Kia'));
            $this->assertSame($this->model('cerato-yd')->id, $method->invoke($command, 'Cerato', 2015, $ordered, 'Kia'));
            $this->assertNull($method->invoke($command, 'Cerato', null, $ordered, 'Kia'));
        }
    }

    public function test_missing_generations_and_regional_names_share_canonical_models(): void
    {
        $this->assertSame(26, VehicleModel::where('brand_id', $this->brandId)->count());
        foreach (['picanto-sa', 'rio-jb', 'cerato-ld', 'cerato-td', 'cerato-yd', 'sportage-km', 'sportage-sl',
            'sorento-bl', 'sorento-xm', 'sorento-mq4', 'optima-tf', 'optima-jf', 'k5-dl3', 'soul-am', 'soul-ps', 'soul-sk3'] as $slug) {
            $this->assertTrue($this->model($slug)->is_active);
        }
        $this->assertSame(2012, $this->model('cerato-yd')->production_start);
        $this->assertSame(2019, $this->model('cerato-yd')->production_end);
        $this->assertSame(2016, $this->model('sportage-sl')->production_end);
        $this->assertSame(2016, $this->model('optima-tf')->production_end);
        $this->assertSame(2014, $this->model('soul-am')->production_end);
        $this->assertSame(2025, $this->model('soul-sk3')->production_end);
        $this->assertSame(['Forte'], $this->model('cerato-bd')->aliases);
        $this->assertSame(['K5'], $this->model('optima-jf')->aliases);
        $this->assertSame(['Morning'], $this->model('picanto-ta')->aliases);
        $this->assertDatabaseMissing('vehicle_models', ['brand_id' => $this->brandId, 'slug' => 'forte-bd']);
        // K3 now also names a distinct newer model: it must not be an unscoped alias.
        foreach (VehicleModel::where('brand_id', $this->brandId)->get() as $model) {
            $this->assertNotContains('K3', $model->aliases);
        }
    }

    public function test_checked_batch_is_simulated_then_imported_without_technical_defaults(): void
    {
        $file = database_path('catalogue/kia-trims-verified.json');
        $this->artisan('catalog:import-trims', ['file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(4, $this->kiaTrims()->count());
        $this->assertDatabaseCount('trim_applications', 0);

        $legacy = $this->kiaTrims()->get()->keyBy('name');
        $otherTrims = DB::table('trims')->whereNotIn('id', $legacy->pluck('id'))->orderBy('id')->get()->toArray();
        $this->seed(KiaCatalogueSeeder::class);
        $this->assertSame(75, $this->kiaTrims()->count());
        $this->assertDatabaseCount('trim_applications', 75);
        $this->assertSame(18, $this->kiaTrims()->whereHas('applications')->distinct()->count('vehicle_model_id'));
        foreach ($this->kiaTrims()->get() as $trim) {
            $this->assertNull($trim->default_engine_type_id);
            $this->assertNull($trim->default_drivetrain_id);
        }
        foreach ($legacy as $name => $old) {
            $current = $this->model('sportage-ql')->trims()->where('name', $name)->sole();
            $this->assertSame($old->id, $current->id);
            $this->assertSame($old->code, $current->code);
        }
        $this->assertEquals($otherTrims, DB::table('trims')->whereNotIn('vehicle_model_id',
            VehicleModel::where('brand_id', $this->brandId)->select('id'))->orderBy('id')->get()->toArray());
        // Picanto TA has no invented trim; the two historical QL names now
        // have an independently documented Australian MY2020 scope.
        $this->assertSame(0, $this->model('picanto-ta')->trims()->count());
        foreach (['SX', 'GT Line'] as $name) {
            $application = $this->model('sportage-ql')->trims()->where('name', $name)->sole()->applications()->sole();
            $this->assertSame('AU', $application->market);
            $this->assertSame(2020, $application->model_year_from);
            $this->assertSame(2020, $application->model_year_to);
        }
        $this->assertTrue($this->model('soul-sk3')->trims()->where('name', 'GT-Line Turbo')->exists());
        $this->assertFalse($this->model('soul-sk3')->trims()->where('name', 'EX Designer Collection')->exists());
        $this->assertTrue($this->model('optima-tf')->trims()->where('name', 'Limited')->exists());
        $this->assertFalse($this->model('optima-tf')->trims()->where('name', 'SXL Turbo')->exists());
    }

    public function test_public_api_and_incremental_sync_expose_aliases_and_precise_scopes(): void
    {
        $this->seed(KiaCatalogueSeeder::class);
        $data = collect($this->getJson('/api/catalog/brands/'.$this->brandId.'/models')->assertOk()->json('data'));
        $cerato = $data->firstWhere('slug', 'cerato-bd');
        $this->assertSame(['Forte'], $cerato['aliases']);
        $ex = collect($cerato['trims'])->firstWhere('name', 'EX');
        $this->assertSame('US', $ex['applications'][0]['market']);
        $this->assertSame(2019, $ex['applications'][0]['model_year_from']);
        $this->assertSame(2019, $ex['applications'][0]['model_year_to']);
        $this->assertStringStartsWith('https://static.nhtsa.gov/', $ex['applications'][0]['sources'][0]['url']);

        $before = now()->toIso8601String();
        $this->travel(1)->minutes();
        $this->model('cerato-bd')->update(['aliases' => ['Forte', 'Temporary test alias']]);
        $delta = $this->getJson('/api/catalog/sync?since='.urlencode($before))->assertOk();
        $record = collect($delta->json('vehicle_models'))->firstWhere('id', $cerato['id']);
        $this->assertSame(['Forte', 'Temporary test alias'], $record['aliases']);
    }

    public function test_replaying_the_catalogue_preserves_ids_timestamps_and_existing_vehicle_links(): void
    {
        $this->seed(KiaCatalogueSeeder::class);
        $trim = $this->model('cerato-bd')->trims()->where('name', 'EX')->sole();
        $vehicle = OwnedVehicle::create(['user_id' => User::factory()->create(['role' => 'client'])->id,
            'brand_id' => $this->brandId, 'vehicle_model_id' => $trim->vehicle_model_id,
            'trim_id' => $trim->id, 'manufacturing_year' => 2019]);
        $before = [];
        foreach (['vehicle_models', 'trims', 'trim_applications', 'owned_vehicles'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        $this->travel(1)->days();
        $this->seed([VehicleModelsSeeder::class, TrimsSeeder::class, KiaCatalogueSeeder::class]);
        foreach ($before as $table => $records) {
            $this->assertEquals($records, DB::table($table)->orderBy('id')->get()->toArray(), $table);
        }
        $this->assertSame($trim->id, $vehicle->fresh()->trim_id);
    }

    public function test_legacy_default_cleanup_preserves_custom_corrections_and_explicit_vehicle_data(): void
    {
        $petrol = DB::table('engine_types')->where('code', 'petrol')->value('id');
        $diesel = DB::table('engine_types')->where('code', 'diesel')->value('id');
        $fwd = DB::table('drivetrains')->where('code', 'FWD')->value('id');
        $awd = DB::table('drivetrains')->where('code', 'AWD')->value('id');
        $model = $this->model('sportage-ql');
        foreach (['LX', 'EX', 'SX', 'GT Line'] as $name) {
            $model->trims()->where('name', $name)->update(['default_engine_type_id' => in_array($name, ['LX', 'EX']) ? $petrol : $diesel,
                'default_drivetrain_id' => in_array($name, ['LX', 'EX']) ? $fwd : $awd]);
        }
        // A staff correction differs from the old seeded pair and is retained.
        $custom = $model->trims()->where('name', 'GT Line')->sole();
        $custom->update(['default_drivetrain_id' => $fwd]);
        $customBefore = $custom->fresh()->getAttributes();
        $lx = $model->trims()->where('name', 'LX')->sole();
        $vehicle = OwnedVehicle::create(['user_id' => User::factory()->create(['role' => 'client'])->id,
            'brand_id' => $this->brandId, 'vehicle_model_id' => $model->id, 'trim_id' => $lx->id,
            'engine_type_id' => $petrol, 'drivetrain_id' => $awd, 'manufacturing_year' => 2020]);
        $vehicleBefore = $vehicle->fresh()->getAttributes();
        $otherBefore = DB::table('trims')->where('vehicle_model_id', '!=', $model->id)->orderBy('id')->get()->toArray();
        $this->travel(1)->hours();
        $migration = require database_path('migrations/2026_11_05_000002_clear_unscoped_kia_trim_defaults.php');
        $migration->up();
        foreach (['LX', 'EX', 'SX'] as $name) {
            $trim = $model->trims()->where('name', $name)->sole();
            $this->assertNull($trim->default_engine_type_id);
            $this->assertNull($trim->default_drivetrain_id);
        }
        $this->assertSame($customBefore, $custom->fresh()->getAttributes());
        $this->assertSame($vehicleBefore, $vehicle->fresh()->getAttributes());
        $this->assertEquals($otherBefore, DB::table('trims')->where('vehicle_model_id', '!=', $model->id)->orderBy('id')->get()->toArray());
        $after = DB::table('trims')->orderBy('id')->get()->toArray();
        $this->travel(1)->hours();
        $migration->up();
        $this->assertEquals($after, DB::table('trims')->orderBy('id')->get()->toArray());
    }
}
