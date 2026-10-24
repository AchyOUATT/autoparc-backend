<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Trim;
use App\Models\TrimApplication;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrimApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private function reference(): array
    {
        $brand = Brand::create(['name' => 'Marque de test', 'slug' => 'marque-test']);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'Modele de test',
            'generation' => 'G1',
            'production_start' => 2010,
            'production_end' => 2020,
        ]);
        $trim = Trim::create([
            'vehicle_model_id' => $model->id,
            'name' => 'Finition de test',
            'description' => 'Libellé documenté pour le marché et le millésime indiqués.',
        ]);

        return [$brand, $model, $trim];
    }

    private function document(Trim $trim): TrimApplication
    {
        return TrimApplication::create([
            'trim_id' => $trim->id,
            'market' => 'US',
            'model_year_from' => 2014,
            'model_year_to' => 2014,
            'sources' => [[
                'url' => 'https://example.com/brochure-test',
                'title' => 'Source fictive réservée au test',
                'consulted_on' => '2026-01-01',
            ]],
            'verified_at' => '2026-01-01',
        ]);
    }

    public function test_les_finitions_publiques_gardent_leur_perimetre_et_ne_deduisent_pas_un_moteur(): void
    {
        [$brand, $model, $trim] = $this->reference();
        $application = $this->document($trim);

        $this->getJson("/api/catalog/brands/{$brand->id}/models")
            ->assertOk()
            ->assertJsonPath('data.0.id', $model->id)
            ->assertJsonPath('data.0.trims.0.id', $trim->id)
            ->assertJsonPath('data.0.trims.0.default_engine_type_id', null)
            ->assertJsonPath('data.0.trims.0.default_drivetrain_id', null)
            ->assertJsonPath('data.0.trims.0.applications.0.id', $application->id)
            ->assertJsonPath('data.0.trims.0.applications.0.market', 'US')
            ->assertJsonPath('data.0.trims.0.applications.0.model_year_from', 2014)
            ->assertJsonPath('data.0.trims.0.applications.0.model_year_to', 2014)
            ->assertJsonPath('data.0.trims.0.applications.0.sources.0.title', 'Source fictive réservée au test');
    }

    public function test_la_synchronisation_transmet_description_et_sources_sans_perdre_la_frontiere_du_curseur(): void
    {
        $this->travelTo(now()->startOfSecond());
        [, , $trim] = $this->reference();
        $application = $this->document($trim);
        $cursor = now()->toIso8601String();

        // A cursor and a DB timestamp may fall in the same second. Re-reading
        // that boundary is required even when the timestamp equals the cursor.
        $this->getJson('/api/catalog/sync?since='.urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('trims.0.description', $trim->description)
            ->assertJsonPath('trim_applications.0.id', $application->id)
            ->assertJsonPath('trim_applications.0.sources.0.url', 'https://example.com/brochure-test');

        $this->travel(2)->seconds();
        $this->getJson('/api/catalog/sync?since='.urlencode(now()->toIso8601String()))
            ->assertOk()
            ->assertJsonCount(0, 'trims')
            ->assertJsonCount(0, 'trim_applications');
    }

    public function test_une_finition_historique_ne_devient_pas_verifiee_par_sa_seule_presence(): void
    {
        [$brand] = $this->reference();

        $this->getJson("/api/catalog/brands/{$brand->id}/models")
            ->assertOk()
            ->assertJsonCount(0, 'data.0.trims.0.applications');
    }

    public function test_la_reprise_reannonce_les_references_historiques_et_preserve_les_dates_de_creation(): void
    {
        [$brand, $model, $trim] = $this->reference();
        $knownCreation = '2025-01-01 12:00:00';
        DB::table('brands')->where('id', $brand->id)->update([
            'created_at' => $knownCreation,
            'updated_at' => $knownCreation,
        ]);
        DB::table('vehicle_models')->where('id', $model->id)->update([
            'created_at' => null,
            'updated_at' => null,
        ]);
        DB::table('trims')->where('id', $trim->id)->update(['updated_at' => $knownCreation]);

        $this->travelTo(now()->startOfSecond());
        $cursor = now()->toIso8601String();
        $this->travel(2)->seconds();
        $repair = require database_path('migrations/2026_10_21_000002_repair_reference_catalog_timestamps.php');
        $repair->up();
        $repairedAt = $model->fresh()->updated_at;

        $this->assertSame($knownCreation, $brand->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($model->fresh()->created_at);
        $this->assertTrue($brand->fresh()->updated_at->equalTo($repairedAt));

        $this->assertSame($model->id, $model->fresh()->id);
        $this->getJson('/api/catalog/sync?since='.urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('brands.0.id', $brand->id)
            ->assertJsonPath('vehicle_models.0.id', $model->id)
            ->assertJsonPath('trims.0.description', $trim->description);
    }
}
