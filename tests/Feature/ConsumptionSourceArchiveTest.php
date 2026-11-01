<?php

namespace Tests\Feature;

use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Services\ConsumptionSourceArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConsumptionSourceArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['consumption.archive_store' => 'database']);
        Storage::fake('local');
    }

    private function import(): void
    {
        $this->artisan('catalog:import-consommations', [
            '--fichier' => [base_path('tests/Fixtures/nrcan-extrait.csv')],
        ])->assertSuccessful();
    }

    public function test_database_archive_survives_service_restart_and_local_disk_loss(): void
    {
        $content = str_repeat("Original source bytes\0\xff\n", 1000);
        $archive = app(ConsumptionSourceArchive::class)->store($content);
        $this->assertSame('verified', $archive['status']);
        $this->assertSame('database', $archive['store']);
        $this->assertSame(hash('sha256', $content), $archive['sha256']);
        $row = DB::table('consumption_source_archives')->where('sha256', $archive['sha256'])->first();
        $this->assertSame(strlen($content), (int) $row->original_size);
        $this->assertLessThan(strlen($content), strlen($row->body));
        Storage::disk('local')->deleteDirectory('consumption-sources');
        $this->assertSame($content, (new ConsumptionSourceArchive)->read($archive['sha256']));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_repeated_store_does_not_replace_original_evidence(): void
    {
        $service = app(ConsumptionSourceArchive::class);
        $archive = $service->store('reference source');
        $before = DB::table('consumption_source_archives')->first();
        $this->travel(1)->days();
        $service->store('reference source');
        $this->assertDatabaseCount('consumption_source_archives', 1);
        $this->assertEquals($before, DB::table('consumption_source_archives')->first());
        $this->assertSame('reference source', $service->read($archive['sha256']));
    }

    public function test_corrupt_archive_is_rejected_without_being_overwritten(): void
    {
        $service = app(ConsumptionSourceArchive::class);
        $archive = $service->store('source');
        DB::table('consumption_source_archives')->where('sha256', $archive['sha256'])
            ->update(['body' => 'corrupt base64']);
        try {
            $service->store('source');
            $this->fail('A corrupt archive must not be reported as verified.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Integrite', $exception->getMessage());
        }
        $this->assertSame('corrupt base64', DB::table('consumption_source_archives')->value('body'));
    }

    public function test_valid_gzip_with_wrong_content_or_size_does_not_pass_integrity_check(): void
    {
        $service = app(ConsumptionSourceArchive::class);
        $archive = $service->store('source');
        DB::table('consumption_source_archives')->where('sha256', $archive['sha256'])
            ->update(['body' => base64_encode(gzencode('tamper'))]);
        $this->expectException(RuntimeException::class);
        $service->read($archive['sha256']);
    }

    public function test_database_write_failure_fails_import_and_journal_without_false_archive_proof(): void
    {
        $archives = Mockery::mock(ConsumptionSourceArchive::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $archives->shouldReceive('insertArchive')->once()->andThrow(new RuntimeException('private database unavailable'));
        $this->app->instance(ConsumptionSourceArchive::class, $archives);
        try {
            $this->artisan('catalog:import-consommations', [
                '--fichier' => [base_path('tests/Fixtures/nrcan-extrait.csv')],
            ])->run();
            $this->fail('The command must fail when its evidence cannot be stored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Ecriture', $exception->getMessage());
        }
        $this->assertDatabaseCount('consumption_source_archives', 0);
        $this->assertDatabaseCount('motorisations', 0);
        $this->assertDatabaseHas('consumption_imports', ['source' => 'nrcan', 'status' => 'failed']);
    }

    public function test_identical_reimport_repairs_only_archive_metadata_and_preserves_selected_reference(): void
    {
        // The factory creates catalogue entries. Keep that catalogue stable
        // across both imports so this test isolates archive repair, not relinking.
        $vehicle = OwnedVehicle::factory()->create();
        $this->import();
        $reference = Motorisation::firstOrFail();
        $vehicle->update(['motorisation_id' => $reference->id]);
        $provenance = $reference->provenance;
        unset($provenance['archive']);
        DB::table('motorisations')->where('id', $reference->id)->update(['provenance' => json_encode($provenance)]);
        $before = $reference->fresh()->getRawOriginal();
        // Simulate a restored database with source archives missing, while the
        // original input remains available to an explicitly repeated import.
        DB::table('consumption_source_archives')->delete();
        $this->travel(1)->days();
        $this->import();
        $after = $reference->fresh();
        $this->assertSame('verified', $after->provenance['archive']['status']);
        $this->assertSame('database', $after->provenance['archive']['store']);
        $repaired = $after->provenance;
        unset($repaired['archive']);
        $this->assertSame($provenance, $repaired);
        $afterRaw = $after->getRawOriginal();
        unset($before['provenance'], $afterRaw['provenance']);
        $this->assertSame($before, $afterRaw);
        $this->assertSame($reference->id, $vehicle->fresh()->motorisation_id);
        $this->assertDatabaseCount('motorisations', 6);
        $this->assertDatabaseCount('consumption_source_archives', 1);
    }

    public function test_detected_corruption_invalidates_existing_archive_proof_and_fails_reimport(): void
    {
        $this->import();
        $reference = Motorisation::firstOrFail();
        DB::table('consumption_source_archives')->update(['body' => '!']);
        try {
            $this->artisan('catalog:import-consommations', [
                '--fichier' => [base_path('tests/Fixtures/nrcan-extrait.csv')],
            ])->run();
            $this->fail('The corrupt source must stop the import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Integrite', $exception->getMessage());
        }
        $this->assertSame('failed', $reference->fresh()->provenance['archive']['status']);
        $this->assertSame('failed', DB::table('consumption_imports')->latest('id')->value('status'));
        $this->assertDatabaseCount('motorisations', 6);
    }

    public function test_legacy_recovery_accepts_only_bytes_matching_previously_recorded_hash(): void
    {
        $service = app(ConsumptionSourceArchive::class);
        $hash = hash('sha256', 'original');
        Storage::disk('local')->put('consumption-sources/'.$hash, 'original');
        $archive = $service->recoverLegacyLocal($hash);
        $this->assertSame('verified', $archive['status']);
        $this->assertSame('original', $service->read($hash));
        $wrongHash = hash('sha256', 'lost original');
        Storage::disk('local')->put('consumption-sources/'.$wrongHash, 'different bytes');
        $this->expectException(RuntimeException::class);
        $service->recoverLegacyLocal($wrongHash);
    }

    public function test_missing_legacy_source_is_reported_unknown_without_fabricating_evidence(): void
    {
        $this->import();
        $reference = Motorisation::firstOrFail();
        DB::table('consumption_source_archives')->delete();
        Storage::disk('local')->deleteDirectory('consumption-sources');
        $this->artisan('catalog:archive-consommations')->assertFailed();
        $this->assertSame('unknown', $reference->fresh()->provenance['archive']['status']);
        $this->assertDatabaseCount('consumption_source_archives', 0);
    }

    public function test_unsupported_store_never_falls_back_to_ephemeral_disk(): void
    {
        config(['consumption.archive_store' => 'local']);
        try {
            app(ConsumptionSourceArchive::class)->store('source');
            $this->fail('An unsupported archive store must fail explicitly.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('database', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('consumption_source_archives', 0);
    }

    public function test_dry_run_does_not_create_archives_or_import_journal(): void
    {
        $this->artisan('catalog:import-consommations', [
            '--fichier' => [base_path('tests/Fixtures/nrcan-extrait.csv')], '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertDatabaseCount('consumption_source_archives', 0);
        $this->assertDatabaseCount('consumption_imports', 0);
        $this->assertDatabaseCount('motorisations', 0);
    }

    public function test_sources_above_the_limit_fail_before_being_archived(): void
    {
        try {
            app(ConsumptionSourceArchive::class)->store(str_repeat('x', config('consumption.archive_max_bytes') + 1));
            $this->fail('An oversized source must be rejected before persistence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('volumineuse', $exception->getMessage());
        }
        $this->assertDatabaseCount('consumption_source_archives', 0);
    }

    public function test_archive_round_trip_and_bounded_decompression_fit_production_memory(): void
    {
        // A separate process exercises PHP's real production limit. The test
        // runner's accumulated fixtures must not change the archive's budget.
        $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$migration = require database_path('migrations/2026_11_01_000001_conserver_les_sources_de_consommation.php');
$migration->up();
$archives = $app->make(App\Services\ConsumptionSourceArchive::class);
$maximum = config('consumption.archive_max_bytes');
$bytes = random_bytes($maximum);
$descriptor = $archives->store($bytes);
if ($descriptor['status'] !== 'verified' || $descriptor['sha256'] !== hash('sha256', $bytes)) {
    throw new RuntimeException('The maximum-sized source did not round-trip.');
}
unset($bytes);

// Build a 256 MiB expansion incrementally so creating the fixture itself does
// not need 256 MiB. A corrupt declared size must stop inflation before OOM.
$deflater = deflate_init(ZLIB_ENCODING_GZIP);
$chunk = str_repeat('x', 1024 * 1024);
$compressed = '';
for ($i = 0; $i < 256; $i++) {
    $compressed .= deflate_add($deflater, $chunk, ZLIB_NO_FLUSH);
}
$compressed .= deflate_add($deflater, '', ZLIB_FINISH);
$digest = hash('sha256', 'corrupt size header');
Illuminate\Support\Facades\DB::table('consumption_source_archives')->insert([
    'sha256' => $digest, 'encoding' => 'gzip+base64', 'body' => base64_encode($compressed),
    'original_size' => 1, 'created_at' => now(),
]);
$rejected = false;
try {
    $archives->read($digest);
} catch (RuntimeException) {
    $rejected = true;
}
if (! $rejected) {
    throw new RuntimeException('The corrupt size header was accepted.');
}
echo json_encode(['original_size' => $maximum, 'peak_bytes' => memory_get_peak_usage(true)]);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=192M', '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'DB_URL' => '', 'CONSUMPTION_SOURCE_ARCHIVE_STORE' => 'database',
        ], null, 30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(config('consumption.archive_max_bytes'), $result['original_size']);
        $this->assertLessThan(192 * 1024 * 1024, $result['peak_bytes']);
    }
}
