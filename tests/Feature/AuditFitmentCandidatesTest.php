<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditFitmentCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function document(): array
    {
        return json_decode(file_get_contents(database_path('catalogue/fitment-candidates/toyota-90915-10001.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function file(array|string $document): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fitment-candidates-');
        $this->files[] = $file;
        file_put_contents($file, is_array($document) ? json_encode($document, JSON_THROW_ON_ERROR) : $document);

        return $file;
    }

    private function model(): VehicleModel
    {
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);

        return VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Corolla', 'generation' => 'E140',
            'slug' => 'corolla-e140', 'production_start' => 2006, 'production_end' => 2013,
        ]);
    }

    private function report(array $options = [], int $expectedExit = 0): array
    {
        $this->assertSame($expectedExit, Artisan::call('catalog:audit-fitment-candidates', ['--format' => 'json', ...$options]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_default_backlog_exposes_all_missing_models_and_year_extensions_without_publishing(): void
    {
        $model = $this->model();
        $report = $this->report();

        $this->assertSame([
            'total_entries' => 11,
            'matched_entries' => 1,
            'missing_model_entries' => 10,
            'missing_model_slugs' => ['camry-xv10', 'camry-xv20', 'corolla-e100', 'corolla-e110', 'corolla-e120', 'corolla-e130', 'rav4-xa10', 'yaris-xp90'],
            'source_bounds_exceeded_entries' => 4,
            'blocked_entries' => 11,
        ], $report['summary']);
        $this->assertSame('pending_review', $report['status']);
        $this->assertSame($model->id, $report['entries'][0]['current_model_id']);
        $this->assertSame(1, VehicleModel::count());
        $this->assertDatabaseCount('part_fitments', 0);
        foreach ($report['entries'] as $entry) {
            $this->assertSame('pending_review', $entry['publication_status']);
            $this->assertContains('pending_review', array_column($entry['issues'], 'code'));
            $this->assertContains('missing_evidence', array_column($entry['issues'], 'code'));
        }
    }

    public function test_fail_on_blocked_preserves_json_output_and_manual_fitments_prices_and_stock(): void
    {
        $model = $this->model();
        $category = PartCategory::create(['name' => 'Catégorie de test', 'slug' => 'test']);
        $part = Part::create([
            'sku' => '90915-10001', 'name' => 'Référence existante', 'part_category_id' => $category->id,
            'selling_price' => 4850, 'stock_quantity' => 7, 'type' => 'aftermarket', 'condition' => 'new',
        ]);
        $manual = $part->fitments()->create([
            'vehicle_model_id' => $model->id, 'engine_code' => 'MANUAL-TEST',
            'year_from' => 2010, 'year_to' => 2011, 'notes' => 'Déclaration conservée', 'source' => FitmentSource::Declared,
        ]);
        $part->refresh();
        $manual->refresh();
        $snapshot = [$part->getAttributes(), $manual->getAttributes()];
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ([false, true] as $failOnBlocked) {
            $report = $this->report(['--fail-on-blocked' => $failOnBlocked], $failOnBlocked ? 1 : 0);
            $this->assertSame(11, $report['summary']['blocked_entries']);
            $this->assertSame($snapshot, [$part->fresh()->getAttributes(), $manual->fresh()->getAttributes()]);
        }
        $this->assertDatabaseCount('parts', 1);
        $this->assertDatabaseCount('part_fitments', 1);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter|create|drop)\b/i', $query);
        }
    }

    public function test_model_resolution_is_scoped_to_the_document_brand(): void
    {
        $other = Brand::create(['name' => 'Autre', 'slug' => 'autre']);
        VehicleModel::create(['brand_id' => $other->id, 'name' => 'Homonyme', 'slug' => 'corolla-e140']);

        $report = $this->report();

        $this->assertSame(0, $report['summary']['matched_entries']);
        $this->assertSame(11, $report['summary']['missing_model_entries']);
        $this->assertNull($report['entries'][0]['current_model_id']);
    }

    public function test_supplied_source_metadata_never_promotes_a_pending_application(): void
    {
        $this->model();
        $document = $this->document();
        $document['entries'] = [array_replace($document['entries'][0], [
            'sources' => [[
                'url' => 'https://example.test/catalogue', 'title' => 'Exemple de métadonnées de test',
                'consulted_on' => now()->toDateString(),
            ]],
            'verified_on' => now()->toDateString(),
        ])];

        $report = $this->report(['file' => $this->file($document)]);

        $this->assertSame(1, $report['summary']['blocked_entries']);
        $this->assertSame('pending_review', $report['entries'][0]['publication_status']);
        $this->assertContains('pending_review', array_column($report['entries'][0]['issues'], 'code'));
        $this->assertNotContains('missing_evidence', array_column($report['entries'][0]['issues'], 'code'));
        $this->assertDatabaseCount('part_fitments', 0);
    }

    public function test_table_output_explains_review_points_without_claiming_to_import(): void
    {
        $this->model();

        $this->assertSame(0, Artisan::call('catalog:audit-fitment-candidates'));
        $output = Artisan::output();
        $this->assertStringContainsString('11 applications proposées', $output);
        $this->assertStringContainsString('10 lignes sans génération', $output);
        $this->assertStringContainsString('Lecture seule', $output);
        $this->assertStringContainsString('Les années proposées dépassent', $output);
    }

    public static function invalidDocuments(): array
    {
        return [
            'unknown root field' => ['unknown_root'],
            'unknown entry field' => ['unknown_entry'],
            'string version' => ['string_version'],
            'string proposed year' => ['string_proposed_year'],
            'string claimed year' => ['string_claimed_year'],
            'reversed proposed years' => ['reversed_proposed_years'],
            'reversed claimed years' => ['reversed_claimed_years'],
            'bad engine code' => ['bad_engine_code'],
            'invalid slug' => ['bad_slug'],
            'bad market' => ['bad_market'],
            'unrecognized status' => ['bad_status'],
            'associative entry list' => ['entries_not_list'],
            'associative notes' => ['notes_not_list'],
            'duplicate application' => ['duplicate'],
            'http evidence URL' => ['http_source'],
            'credential bearing source URL' => ['credential_source'],
            'future consultation' => ['future_consultation'],
            'verification before consultation' => ['verification_before_consultation'],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function test_invalid_documents_fail_without_writing(string $case): void
    {
        $document = $this->document();
        $document['entries'] = [$document['entries'][0]];
        $entry = &$document['entries'][0];
        switch ($case) {
            case 'unknown_root': $document['verified'] = true;
                break;
            case 'unknown_entry': $entry['verified'] = true;
                break;
            case 'string_version': $document['version'] = '1';
                break;
            case 'string_proposed_year': $entry['proposed_year_from'] = '2006';
                break;
            case 'string_claimed_year': $entry['claimed_source']['year_from'] = '1998';
                break;
            case 'reversed_proposed_years': $entry['proposed_year_to'] = 2000;
                break;
            case 'reversed_claimed_years': $entry['claimed_source']['year_to'] = 1990;
                break;
            case 'bad_engine_code': $entry['engine_code'] = '1zz fe';
                break;
            case 'bad_slug': $entry['model_slug'] = '../corolla';
                break;
            case 'bad_market': $entry['claimed_market'] = 'us';
                break;
            case 'bad_status': $document['status'] = 'verified';
                break;
            case 'entries_not_list': $document['entries'] = ['candidate' => $entry];
                break;
            case 'notes_not_list': $entry['review_notes'] = ['note' => 'Texte'];
                break;
            case 'duplicate': $document['entries'][] = $entry;
                break;
            default:
                $entry['sources'] = [[
                    'url' => match ($case) {
                        'http_source' => 'http://example.test/catalogue',
                        'credential_source' => 'https://user:secret@example.test/catalogue',
                        default => 'https://example.test/catalogue',
                    },
                    'title' => 'Document fictif de test',
                    'consulted_on' => $case === 'future_consultation' ? now()->addDay()->toDateString() : now()->toDateString(),
                ]];
                $entry['verified_on'] = $case === 'verification_before_consultation' ? now()->subDay()->toDateString() : now()->toDateString();
        }

        $this->assertSame(1, Artisan::call('catalog:audit-fitment-candidates', ['file' => $this->file($document)]));
        $this->assertDatabaseCount('vehicle_models', 0);
        $this->assertDatabaseCount('part_fitments', 0);
        $this->assertDatabaseCount('parts', 0);
    }

    public function test_unreadable_invalid_json_and_non_object_documents_are_refused(): void
    {
        foreach (['/missing-fitment-candidates.json', $this->file('{broken json'), $this->file('[]')] as $file) {
            $this->assertSame(1, Artisan::call('catalog:audit-fitment-candidates', ['file' => $file]));
        }
        $this->assertSame(1, Artisan::call('catalog:audit-fitment-candidates', ['--format' => 'xml']));
        $this->assertDatabaseCount('part_fitments', 0);
    }
}
