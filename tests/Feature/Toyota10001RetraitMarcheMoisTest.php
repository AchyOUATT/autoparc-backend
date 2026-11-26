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
use App\Support\Toyota10001RetraitMarcheMois;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le marché JP et les mois posés par 210000 excluaient des véhicules sans
 * preuve que le catalogue CUSCO soit restrictif ou exhaustif. 220000 les
 * retire ; ces tests verrouillent le comportement attendu.
 */
class Toyota10001RetraitMarcheMoisTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, VehicleModel> */
    private array $modeles;

    private Part $piece;

    protected function setUp(): void
    {
        parent::setUp();

        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);
        $noms = ['corolla-e100' => ['Corolla', 'E100'], 'corolla-e110' => ['Corolla', 'E110'],
            'camry-xv10' => ['Camry', 'XV10'], 'camry-xv20' => ['Camry', 'XV20'], 'rav4-xa10' => ['RAV4', 'XA10']];
        $this->modeles = [];
        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            [$nom, $generation] = $noms[$cible['model_slug']];
            $this->modeles[$cible['model_slug']] = VehicleModel::create([
                'brand_id' => $marque->id, 'slug' => $cible['model_slug'], 'name' => $nom,
                'generation' => $generation, 'is_active' => true,
            ]);
        }

        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);
        $this->piece = Part::create([
            'sku' => Toyota10001PreuvesAftermarket::SKU,
            'name' => 'Filtre a huile visse 90915-10001',
            'description' => Toyota10001PreuvesAftermarket::DESCRIPTION_AVANT,
            'part_category_id' => $categorie->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 3000,
            'stock_quantity' => 15, 'is_active' => true,
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

    public function test_apres_210000_seul_un_marche_etranger_excluait_les_lignes_ce_que_220000_corrige(): void
    {
        Toyota10001PreuvesAftermarket::run();

        // Documente le défaut : sans 220000, un véhicule de marché AFR est exclu.
        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $verdict = $this->verdict($cible, ['specification_market' => 'AFR']);
            $this->assertSame('excluded', $verdict['status'], $cible['model_slug']);
        }

        Toyota10001RetraitMarcheMois::run();

        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $verdict = $this->verdict($cible, ['specification_market' => 'AFR']);
            $this->assertSame('candidate', $verdict['status'], $cible['model_slug']);
        }
    }

    public function test_retrait_du_marche_et_des_mois_en_gardant_preuve_url_et_notes(): void
    {
        Toyota10001PreuvesAftermarket::run();
        $notes = $this->piece->fitments()->orderBy('id')->pluck('notes')->all();

        $rapport = Toyota10001RetraitMarcheMois::run();

        $this->assertSame(15, $rapport['champs_retires']); // 5 lignes x (marché + 2 mois)
        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $ligne = $this->ligne($cible)->fresh();
            $this->assertNull($ligne->market_codes);
            $this->assertNull($ligne->month_from);
            $this->assertNull($ligne->month_to);
            $this->assertSame(FitmentEvidence::AFTERMARKET_CROSSREF, $ligne->evidence_level);
            $this->assertSame([Toyota10001PreuvesAftermarket::SOURCE_URL], $ligne->evidence_sources);
            $this->assertSame(FitmentSource::Declared, $ligne->source);
        }
        $this->assertSame($notes, $this->piece->fitments()->orderBy('id')->pluck('notes')->all());
        $this->assertSame(Toyota10001PreuvesAftermarket::DESCRIPTION_APRES, $this->piece->fresh()->description);
    }

    public function test_un_vehicule_hors_marche_jp_ou_hors_mois_reste_candidat_jamais_exclu(): void
    {
        Toyota10001PreuvesAftermarket::run();
        Toyota10001RetraitMarcheMois::run();

        foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
            $slug = $cible['model_slug'];
            $milieu = intdiv($cible['year_from'] + $cible['year_to'], 2);

            foreach (['USA', 'AFR', 'EUR', 'GEN', 'JP', null] as $marche) {
                $v = $this->verdict($cible, ['year' => $milieu, 'specification_market' => $marche]);
                $this->assertSame('candidate', $v['status'], "$slug marché ".($marche ?? 'inconnu'));
                $this->assertSame('insufficient_evidence', $v['reason']);
                $this->assertSame([], $v['excluded_criteria']);
                $this->assertSame(['evidence'], $v['missing_criteria'], "$slug : plus aucun critère de marché ou de mois à renseigner");
            }

            // Mois hors plage sur chaque année-borne, marché JP ou étranger.
            $avantDebut = $cible['month_from'] === 1 ? 12 : $cible['month_from'] - 1;
            $apresFin = $cible['month_to'] === 12 ? 1 : $cible['month_to'] + 1;
            foreach (['JP', 'USA', null] as $marche) {
                $debut = $this->verdict($cible, ['year' => $cible['year_from'], 'month' => $avantDebut, 'specification_market' => $marche]);
                $fin = $this->verdict($cible, ['year' => $cible['year_to'], 'month' => $apresFin, 'specification_market' => $marche]);
                foreach ([$debut, $fin] as $v) {
                    $this->assertSame('candidate', $v['status'], "$slug borne, marché ".($marche ?? 'inconnu'));
                    $this->assertSame([], $v['excluded_criteria']);
                }
            }

            // Année-borne sans mois : plus de « mois manquant ».
            $sansMois = $this->verdict($cible, ['year' => $cible['year_from'], 'specification_market' => 'JP']);
            $this->assertSame('candidate', $sansMois['status']);
            $this->assertSame(['evidence'], $sansMois['missing_criteria']);
        }
    }

    public function test_la_recherche_catalogue_garde_la_piece_en_candidate_pour_un_marche_africain(): void
    {
        Toyota10001PreuvesAftermarket::run();
        Toyota10001RetraitMarcheMois::run();
        $cible = Toyota10001PreuvesAftermarket::cibles()[2];
        $criteres = [
            'vehicle_model_id' => $this->modeles[$cible['model_slug']]->id,
            'year' => 1994,
            'engine_code' => $cible['engine_code'],
            'specification_market' => 'AFR',
        ];

        // Sans candidats : rien (aucune confirmation).
        $this->getJson('/api/catalog/compatible-parts?'.http_build_query($criteres))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/catalog/compatible-parts?'.http_build_query($criteres + ['include_candidates' => 1]))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->piece->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate')
            ->assertJsonPath('data.0.compatibility_assessment.reason', 'insufficient_evidence');
    }

    public function test_rejouer_le_retrait_ne_change_rien(): void
    {
        Toyota10001PreuvesAftermarket::run();
        Toyota10001RetraitMarcheMois::run();
        $avant = $this->instantane();

        $rapport = Toyota10001RetraitMarcheMois::run();

        $this->assertSame(0, $rapport['champs_retires']);
        $this->assertSame($avant, $this->instantane());
    }

    public function test_un_champ_modifie_a_la_main_n_est_pas_retire(): void
    {
        Toyota10001PreuvesAftermarket::run();
        $e100 = Toyota10001PreuvesAftermarket::cibles()[0];
        $e110 = Toyota10001PreuvesAftermarket::cibles()[1];
        $this->ligne($e100)->update(['market_codes' => ['JP', 'AFR'], 'month_from' => 7]);
        $this->ligne($e110)->update(['market_codes' => ['USA']]);

        Toyota10001RetraitMarcheMois::run();

        $a = $this->ligne($e100)->fresh();
        $this->assertSame(['JPN', 'AFR'], $a->market_codes, 'marché élargi à la main : conservé (Eloquent normalise JP en JPN)');
        $this->assertSame(7, $a->month_from, 'mois modifié à la main : conservé');
        $this->assertNull($a->month_to, 'mois resté tel que posé par 210000 : retiré');
        $b = $this->ligne($e110)->fresh();
        $this->assertSame(['USA'], $b->market_codes);
        $this->assertNull($b->month_from);
        $this->assertNull($b->month_to);
    }

    public function test_une_ligne_promue_a_un_niveau_suffisant_n_est_jamais_modifiee(): void
    {
        Toyota10001PreuvesAftermarket::run();
        $cible = Toyota10001PreuvesAftermarket::cibles()[3];
        $this->ligne($cible)->update([
            'evidence_level' => FitmentEvidence::MANUFACTURER_OFFICIAL,
            'evidence_sources' => ['https://exemple.test/toyota-epc'],
        ]);
        $avant = $this->ligne($cible)->fresh()->only(['evidence_level', 'evidence_sources', 'market_codes', 'month_from', 'month_to']);

        $rapport = Toyota10001RetraitMarcheMois::run();

        $this->assertSame(12, $rapport['champs_retires']); // 4 lignes seulement
        $this->assertSame($avant, $this->ligne($cible)->fresh()->only(['evidence_level', 'evidence_sources', 'market_codes', 'month_from', 'month_to']));
        $this->assertNotNull($this->ligne($cible)->fresh()->market_codes);
    }

    public function test_le_retour_arriere_remet_marche_et_mois_seulement_sur_les_champs_vides(): void
    {
        Toyota10001PreuvesAftermarket::run();
        $apres210000 = $this->instantane();
        $migration = $this->migration();

        $migration->up();
        $this->assertNotSame($apres210000, $this->instantane());
        $migration->down();
        $this->assertSame($apres210000, $this->instantane(), 'aller-retour : retour exact à l\'état de 210000');

        // Un champ rempli entre-temps n'est pas écrasé par down().
        $migration->up();
        $cible = Toyota10001PreuvesAftermarket::cibles()[0];
        $this->ligne($cible)->update(['market_codes' => ['AFR'], 'month_from' => 9]);
        $migration->down();

        $ligne = $this->ligne($cible)->fresh();
        $this->assertSame(['AFR'], $ligne->market_codes);
        $this->assertSame(9, $ligne->month_from);
        $this->assertSame($cible['month_to'], $ligne->month_to, 'champ resté vide : remis');

        // Rejouer down() ne change plus rien.
        $avant = $this->instantane();
        $migration->down();
        $this->assertSame($avant, $this->instantane());
    }

    public function test_la_chaine_complete_des_deux_migrations_se_defait_dans_l_ordre(): void
    {
        $initial = $this->instantane();
        $m210 = require database_path('migrations/2026_11_26_210000_qualifier_les_preuves_aftermarket_du_filtre_90915_10001.php');
        $m220 = $this->migration();

        $m210->up();
        $m220->up();
        $m220->down();
        $m210->down();

        $this->assertSame($initial, $this->instantane());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_26_220000_retirer_marche_et_mois_des_lignes_cusco_du_filtre_90915_10001.php');
    }

    /**
     * @param  array{model_slug: string, engine_code: string, year_from: int, year_to: int}  $cible
     * @param  array<string, mixed>  $surcharge
     * @return array<string, mixed>
     */
    private function verdict(array $cible, array $surcharge): array
    {
        $evaluation = app(PartCompatibilityAssessment::class);
        $criteres = [
            'vehicle_model_id' => $this->modeles[$cible['model_slug']]->id,
            'year' => intdiv($cible['year_from'] + $cible['year_to'], 2),
            'engine_code' => $cible['engine_code'],
        ];

        return $evaluation->assess($this->piece->fresh(), $evaluation->withModelState($surcharge + $criteres));
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

        return ['description' => $this->piece->fresh()->description, 'lignes' => $lignes];
    }
}
