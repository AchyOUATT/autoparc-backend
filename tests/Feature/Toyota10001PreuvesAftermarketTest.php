<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Services\PartCompatibilityAssessment;
use App\Support\FitmentEvidence;
use App\Support\Toyota10001PreuvesAftermarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Toyota10001PreuvesAftermarketTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, VehicleModel> */
    private array $modeles;

    private Part $piece;

    protected function setUp(): void
    {
        parent::setUp();

        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);
        $this->modeles = [];
        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $generation = [
                'corolla-e100' => 'E100',
                'corolla-e110' => 'E110',
                'camry-xv10' => 'XV10',
                'camry-xv20' => 'XV20',
                'rav4-xa10' => 'XA10',
            ][$cible['model_slug']];
            $nom = str_starts_with($cible['model_slug'], 'corolla') ? 'Corolla'
                : (str_starts_with($cible['model_slug'], 'camry') ? 'Camry' : 'RAV4');
            $this->modeles[$cible['model_slug']] = VehicleModel::create([
                'brand_id' => $marque->id,
                'slug' => $cible['model_slug'],
                'name' => $nom,
                'generation' => $generation,
                'is_active' => true,
            ]);
        }

        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);
        $this->piece = Part::create([
            'sku' => Toyota10001PreuvesAftermarket::SKU,
            'name' => 'Filtre a huile visse 90915-10001',
            'description' => Toyota10001PreuvesAftermarket::DESCRIPTION_AVANT,
            'part_category_id' => $categorie->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 3000,
            'stock_quantity' => 15,
            'is_active' => true,
        ])->refresh();

        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $this->piece->fitments()->create([
                'vehicle_model_id' => $this->modeles[$cible['model_slug']]->id,
                'engine_code' => $cible['engine_code'],
                'year_from' => $cible['year_from'],
                'year_to' => $cible['year_to'],
                'source' => FitmentSource::Declared,
                'notes' => 'Application documentée, marché Japon (JP). Source : '.Toyota10001PreuvesAftermarket::SOURCE_URL,
            ]);
        }
    }

    public function test_les_cinq_lignes_recoivent_la_preuve_aftermarket_et_restent_candidates(): void
    {
        $rapport = Toyota10001PreuvesAftermarket::run();

        $this->assertSame(5, $rapport['fitments_qualifies']);
        $this->assertSame(1, $rapport['description_corrigee']);
        $this->assertSame(Toyota10001PreuvesAftermarket::DESCRIPTION_APRES, $this->piece->fresh()->description);

        $evaluation = app(PartCompatibilityAssessment::class);
        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $ligne = $this->ligne($cible)->fresh();
            $this->assertSame(FitmentEvidence::AFTERMARKET_CROSSREF, $ligne->evidence_level);
            $this->assertSame([Toyota10001PreuvesAftermarket::SOURCE_URL], $ligne->evidence_sources);
            $this->assertSame(['JP'], $ligne->market_codes);
            $this->assertSame($cible['month_from'], $ligne->month_from);
            $this->assertSame($cible['month_to'], $ligne->month_to);

            $modele = $this->modeles[$cible['model_slug']];
            $anneeInterieure = intdiv($cible['year_from'] + $cible['year_to'], 2);
            $complet = $evaluation->assess($this->piece->fresh(), $evaluation->withModelState([
                'vehicle_model_id' => $modele->id,
                'year' => $anneeInterieure,
                'engine_code' => $cible['engine_code'],
                'specification_market' => 'JP',
            ]));
            $this->assertSame('candidate', $complet['status'], $cible['model_slug']);
            $this->assertNotSame('confirmed', $complet['status']);
            $this->assertSame('insufficient_evidence', $complet['reason']);
            $this->assertFalse($complet['compatible']);

            $borne = $evaluation->assess($this->piece->fresh(), $evaluation->withModelState([
                'vehicle_model_id' => $modele->id,
                'year' => $cible['year_from'],
                'month' => $cible['month_from'],
                'engine_code' => $cible['engine_code'],
                'specification_market' => 'JP',
            ]));
            $this->assertSame('candidate', $borne['status'], $cible['model_slug'].' borne');
            $this->assertSame('insufficient_evidence', $borne['reason']);
        }
    }

    public function test_rejouer_la_qualification_ne_change_rien(): void
    {
        Toyota10001PreuvesAftermarket::run();
        $avant = $this->instantane();

        $rapport = Toyota10001PreuvesAftermarket::run();

        $this->assertSame(0, $rapport['fitments_qualifies']);
        $this->assertSame(0, $rapport['description_corrigee']);
        $this->assertSame($avant, $this->instantane());
    }

    public function test_le_retrait_de_la_migration_retablit_les_valeurs_precedentes(): void
    {
        $avant = $this->instantane();
        $migration = $this->migration();

        $migration->up();
        $this->assertSame(Toyota10001PreuvesAftermarket::DESCRIPTION_APRES, $this->piece->fresh()->description);
        $this->assertSame(5, $this->piece->fitments()->where('evidence_level', FitmentEvidence::AFTERMARKET_CROSSREF)->count());

        $migration->down();
        $this->assertSame($avant, $this->instantane());
    }

    public function test_un_champ_deja_renseigne_n_est_pas_ecrase(): void
    {
        $cible = Toyota10001PreuvesAftermarket::cibles()[0];
        $this->ligne($cible)->update([
            'evidence_level' => FitmentEvidence::UNVERIFIED,
            'evidence_sources' => ['https://exemple.test/autre'],
            'market_codes' => ['USA'],
            'month_from' => 1,
            'month_to' => 2,
        ]);
        $this->piece->update(['description' => 'Description deja corrigee par ailleurs.']);
        $avant = $this->ligne($cible)->fresh()->only([
            'evidence_level', 'evidence_sources', 'market_codes', 'month_from', 'month_to',
        ]);

        $rapport = Toyota10001PreuvesAftermarket::run();

        $this->assertSame(0, $rapport['description_corrigee']);
        $this->assertSame('Description deja corrigee par ailleurs.', $this->piece->fresh()->description);
        $this->assertSame($avant, $this->ligne($cible)->fresh()->only([
            'evidence_level', 'evidence_sources', 'market_codes', 'month_from', 'month_to',
        ]));
        $this->assertSame(4, $rapport['fitments_qualifies']);
        $this->assertSame(4, $this->piece->fitments()->where('evidence_level', FitmentEvidence::AFTERMARKET_CROSSREF)->count());
    }

    public function test_la_recherche_catalogue_laisse_les_cinq_lignes_candidates(): void
    {
        Toyota10001PreuvesAftermarket::run();
        $cible = Toyota10001PreuvesAftermarket::cibles()[2];
        $modele = $this->modeles[$cible['model_slug']];

        $this->getJson('/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $modele->id,
            'year' => 1994,
            'engine_code' => $cible['engine_code'],
            'specification_market' => 'JP',
        ]))->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $modele->id,
            'year' => 1994,
            'engine_code' => $cible['engine_code'],
            'specification_market' => 'JP',
            'include_candidates' => 1,
        ]))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->piece->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate')
            ->assertJsonPath('data.0.compatibility_assessment.reason', 'insufficient_evidence');
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_26_210000_qualifier_les_preuves_aftermarket_du_filtre_90915_10001.php');
    }

    /** @param  array{model_slug: string, engine_code: string, year_from: int, year_to: int}  $cible */
    private function ligne(array $cible): PartFitment
    {
        return $this->piece->fitments()
            ->where('vehicle_model_id', $this->modeles[$cible['model_slug']]->id)
            ->where('engine_code', $cible['engine_code'])
            ->where('year_from', $cible['year_from'])
            ->where('year_to', $cible['year_to'])
            ->firstOrFail();
    }

    /** @return array{description: ?string, lignes: list<array<string, mixed>>} */
    private function instantane(): array
    {
        $lignes = $this->piece->fresh()->fitments()->with('vehicleModel')->orderBy('id')->get()
            ->map(fn ($ligne) => [
                'slug' => $ligne->vehicleModel->slug,
                'engine_code' => $ligne->engine_code,
                'year_from' => $ligne->year_from,
                'year_to' => $ligne->year_to,
                'evidence_level' => $ligne->evidence_level,
                'evidence_sources' => $ligne->evidence_sources,
                'market_codes' => $ligne->market_codes,
                'month_from' => $ligne->month_from,
                'month_to' => $ligne->month_to,
                'notes' => $ligne->notes,
                'source' => $ligne->source?->value ?? $ligne->source,
            ])->all();

        return [
            'description' => $this->piece->fresh()->description,
            'lignes' => $lignes,
        ];
    }
}
