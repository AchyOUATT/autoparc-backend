<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrimsTest extends TestCase
{
    use RefreshDatabase;

    private function model(string $name, ?string $slug = null): VehicleModel
    {
        $brand = Brand::firstOrCreate(['slug' => 'honda'], ['name' => 'Honda']);

        return VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => $name,
            'slug' => $slug,
            'generation' => 'Test',
            'production_start' => 2015,
        ]);
    }

    private function application(Trim $trim, int $year): void
    {
        DB::table('trim_applications')->insert([
            'trim_id' => $trim->id,
            'market' => 'US',
            'model_year_from' => $year,
            'model_year_to' => $year,
            'sources' => json_encode([['url' => 'https://example.test/brochure', 'title' => 'Brochure de test']]),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function report(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('catalog:audit-trims', array_merge(['--format' => 'json'], $options)));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_l_audit_distingue_absence_de_finition_et_documentation_partielle_sans_ecrire(): void
    {
        $missing = $this->model('Sans finition');
        $legacy = $this->model('Finition non documentee');
        Trim::create(['vehicle_model_id' => $legacy->id, 'name' => 'Version existante']);
        $documented = $this->model('Gamme partiellement documentee');
        $trim = Trim::create(['vehicle_model_id' => $documented->id, 'name' => 'Version verifiee']);
        Trim::create(['vehicle_model_id' => $documented->id, 'name' => 'Autre version']);
        $this->application($trim, 2018);
        $this->application($trim, 2019);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $report = $this->report();
        $rows = collect($report['models'])->keyBy('model_id');

        $this->assertSame('a_documenter', $rows[$missing->id]['status']);
        $this->assertSame('finitions_non_documentees', $rows[$legacy->id]['status']);
        $this->assertSame('documente_partiellement', $rows[$documented->id]['status']);
        $this->assertSame(2, $rows[$documented->id]['trims_count']);
        $this->assertSame(1, $rows[$documented->id]['documented_trims_count']);
        $this->assertSame(3, $report['summary']['catalogue']['models_total']);
        $this->assertSame(1, $report['summary']['catalogue']['models_without_trims']);

        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter|create|drop)\b/i', $query);
        }
    }

    public function test_le_filtre_des_manques_ne_fausse_pas_les_compteurs_globaux(): void
    {
        $missing = $this->model('A documenter');
        $present = $this->model('Avec finition');
        Trim::create(['vehicle_model_id' => $present->id, 'name' => 'Version']);

        $report = $this->report(['--only-missing' => true]);

        $this->assertSame([$missing->id], array_column($report['models'], 'model_id'));
        $this->assertSame(2, $report['summary']['catalogue']['models_total']);
        $this->assertSame(1, $report['summary']['selection']['models_total']);
        $this->assertTrue($report['scope']['only_missing']);
    }

    public function test_le_pilote_selectionne_la_marque_et_le_slug_sans_inventer_les_cibles_absentes(): void
    {
        $target = $this->model('Civic', 'civic-fc');
        $this->model('Hors pilote', 'autre');
        $otherBrand = Brand::create(['name' => 'Autre marque', 'slug' => 'autre-marque']);
        VehicleModel::create(['brand_id' => $otherBrand->id, 'name' => 'Homonyme', 'slug' => 'civic-fc']);

        $report = $this->report(['--pilot' => true]);

        $this->assertSame([$target->id], array_column($report['models'], 'model_id'));
        $this->assertSame(3, $report['summary']['catalogue']['models_total']);
        $this->assertCount(9, $report['scope']['pilot_targets_not_found']);
    }

    public function test_l_export_csv_conserve_les_noms_contenant_une_virgule(): void
    {
        $model = $this->model('Modele, serie speciale');

        $this->assertSame(0, Artisan::call('catalog:audit-trims', ['--format' => 'csv']));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, Artisan::output());
        rewind($stream);
        $headers = fgetcsv($stream, escape: '');
        $row = array_combine($headers, fgetcsv($stream, escape: ''));
        fclose($stream);

        $this->assertSame($model->name, $row['model_name']);
        $this->assertSame('0', $row['trims_count']);
        $this->assertSame('', $row['production_end']);
        $this->assertSame('a_documenter', $row['status']);
    }

    public function test_une_base_sans_modele_produit_un_rapport_vide_explicite(): void
    {
        $report = $this->report();

        $this->assertSame([], $report['models']);
        $this->assertSame(0, $report['summary']['catalogue']['models_total']);
        $this->assertSame(0, $report['summary']['catalogue']['trims_total']);
    }

    public function test_un_format_inconnu_est_refuse(): void
    {
        $this->assertSame(1, Artisan::call('catalog:audit-trims', ['--format' => 'xml']));
        $this->assertStringContainsString('--format accepte table, json ou csv.', Artisan::output());
    }

    public function test_le_backlog_ne_presente_aucune_finition_non_verifiee_comme_importable(): void
    {
        $backlog = json_decode(file_get_contents(database_path('catalogue/trims-pilot.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(10, $backlog['entries']);

        foreach ($backlog['entries'] as $entry) {
            $this->assertSame('blocked_source_access', $entry['status']);
            $this->assertSame([], $entry['trims']);
            $this->assertSame([], $entry['sources']);
        }
    }
}
