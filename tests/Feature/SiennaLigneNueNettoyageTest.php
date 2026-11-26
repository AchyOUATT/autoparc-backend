<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use App\Support\SiennaLigneNueCompatibilitesRetrait;
use App\Support\SiennaMotorisationsReattribution;
use App\Support\ToyotaSiennaReferentielImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ligne nue `sienna` (décision du propriétaire du 26/11/2026) : retrait de
 * ses 4 compatibilités une fois remplacées au niveau génération, et
 * réattribution de ses 86 motorisations nrcan aux XL20 / XL30 / XL40.
 *
 * L'état de départ reproduit la production lue le 26/11/2026 : ligne nue
 * active 2007-ouvert, 4 compatibilités déclarées, et les 86 cotes nrcan
 * (fixture `tests/fixtures/sienna-motorisations-production-2026-11-26.json`,
 * colonnes utiles seulement).
 */
class SiennaLigneNueNettoyageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Part> */
    private array $pieces = [];

    private VehicleModel $nue;

    /** @var array<int, int> identifiant de production → identifiant local */
    private array $cotes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $toyota = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota', 'is_active' => true]);
        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);
        foreach (['90915-YZZD1', '04152-YZZA1', '90915-YZZN1'] as $sku) {
            $this->pieces[$sku] = Part::create([
                'sku' => $sku, 'name' => 'Filtre '.$sku, 'part_category_id' => $categorie->id,
                'manufacturer_reference' => $sku, 'type' => 'aftermarket', 'condition' => 'new',
                'selling_price' => 3000, 'currency' => 'XOF', 'stock_quantity' => 5,
                'is_active' => true, 'is_available' => true,
            ]);
        }

        $this->nue = VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Sienna', 'slug' => 'sienna', 'generation' => null,
            'body_type' => 'monospace', 'segment' => 'E', 'production_start' => 2007, 'production_end' => null,
            'is_active' => true,
        ]);
        foreach ($this->anciennes() as [$sku, $moteur, $de, $a]) {
            $this->pieces[$sku]->fitments()->create([
                'vehicle_model_id' => $this->nue->id, 'engine_code' => $moteur,
                'year_from' => $de, 'year_to' => $a, 'source' => 'declared',
                'notes' => 'Catalogue de correspondance du 18/10/2026 : Sienna '.$moteur,
            ]);
        }

        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/sienna-motorisations-production-2026-11-26.json')), true);
        foreach ($fixture as $ligne) {
            $prodId = $ligne['prod_id'];
            unset($ligne['prod_id']);
            $this->cotes[$prodId] = Motorisation::create([
                ...$ligne,
                'brand_id' => $toyota->id,
                'vehicle_model_id' => $this->nue->id,
                'cle_source' => hash('sha256', 'sienna-'.$prodId),
                'identity_key' => hash('sha256', 'identite-'.$prodId),
                'is_current' => true,
                'method' => 'unknown',
            ])->id;
        }

        // Les générations et leurs lignes réévaluées (PR #43).
        ToyotaSiennaReferentielImport::run();
    }

    /** @return list<array{0: string, 1: string, 2: int, 3: ?int}> */
    private function anciennes(): array
    {
        return [
            ['04152-YZZA1', '2GR-FE', 2007, 2016],
            ['04152-YZZA1', '1AR-FE', 2011, 2013],
            ['04152-YZZA1', '2GR-FKS', 2017, 2020],
            ['90915-YZZN1', 'A25A-FXS', 2021, null],
        ];
    }

    private function modele(string $slug): int
    {
        return (int) VehicleModel::where('slug', $slug)->value('id');
    }

    private function migration(string $fichier): object
    {
        return require database_path('migrations/'.$fichier.'.php');
    }

    // ── Compatibilités de la ligne nue ───────────────────────────────────────

    public function test_les_quatre_lignes_nues_partent_avec_leur_correspondance_archivee(): void
    {
        $rapport = SiennaLigneNueCompatibilitesRetrait::run();

        $this->assertCount(4, $rapport['removed']);
        $this->assertSame([], $rapport['kept_replacement_missing']);
        $this->assertSame(0, PartFitment::where('vehicle_model_id', $this->nue->id)->count());

        $archives = DB::table('part_fitment_archives')->orderBy('id')->get();
        $this->assertCount(4, $archives);
        $parCle = $archives->keyBy('fitment_key');
        $this->assertSame(
            ['04152-YZZA1|sienna-xl20|2GR-FE|2006-2010', '04152-YZZA1|sienna-xl30|2GR-FE|2010-2016'],
            json_decode($parCle['04152-YZZA1|sienna|2GR-FE|2007-2016']->replaced_by, true),
        );
        $this->assertSame(['04152-YZZA1|sienna-xl30|1AR-FE|2010-2013'], json_decode($parCle['04152-YZZA1|sienna|1AR-FE|2011-2013']->replaced_by, true));
        $this->assertSame(['04152-YZZA1|sienna-xl30|2GR-FKS|2016-2020'], json_decode($parCle['04152-YZZA1|sienna|2GR-FKS|2017-2020']->replaced_by, true));
        $this->assertSame(['90915-YZZN1|sienna-xl40|A25A-FXS|2020-'], json_decode($parCle['90915-YZZN1|sienna|A25A-FXS|2021-']->replaced_by, true));

        // La ligne complète est gardée, notes comprises.
        $attributs = json_decode($parCle['04152-YZZA1|sienna|2GR-FE|2007-2016']->attributes, true);
        $this->assertSame('Catalogue de correspondance du 18/10/2026 : Sienna 2GR-FE', $attributs['notes']);
        $this->assertSame('declared', $attributs['source']);

        // Les lignes de génération ne bougent pas.
        $this->assertSame(1, PartFitment::where('vehicle_model_id', $this->modele('sienna-xl20'))->where('part_id', $this->pieces['04152-YZZA1']->id)->count());
        $this->assertSame(3, PartFitment::where('vehicle_model_id', $this->modele('sienna-xl30'))->count());
        $this->assertSame(1, PartFitment::where('vehicle_model_id', $this->modele('sienna-xl40'))->count());
    }

    public function test_une_ligne_sans_remplacante_documentee_reste_en_place(): void
    {
        PartFitment::where('vehicle_model_id', $this->modele('sienna-xl30'))->where('engine_code', '1AR-FE')->delete();

        $rapport = SiennaLigneNueCompatibilitesRetrait::run();

        $this->assertCount(3, $rapport['removed']);
        $this->assertCount(1, $rapport['kept_replacement_missing']);
        $this->assertStringContainsString('1AR-FE', $rapport['kept_replacement_missing'][0]);
        $restante = PartFitment::where('vehicle_model_id', $this->nue->id)->get();
        $this->assertCount(1, $restante);
        $this->assertSame('1AR-FE', $restante->first()->engine_code);
    }

    public function test_une_couverture_incomplete_garde_la_ligne(): void
    {
        // La XL30 2GR-FE ne couvrirait plus 2016 : la ligne 2007-2016 reste.
        PartFitment::where('vehicle_model_id', $this->modele('sienna-xl30'))->where('engine_code', '2GR-FE')->update(['year_to' => 2015]);

        $rapport = SiennaLigneNueCompatibilitesRetrait::run();

        $this->assertContains('2GR-FE', PartFitment::where('vehicle_model_id', $this->nue->id)->pluck('engine_code')->all());
        $this->assertCount(3, $rapport['removed']);
    }

    public function test_le_retrait_est_idempotent_et_le_down_restaure(): void
    {
        $avant = PartFitment::where('vehicle_model_id', $this->nue->id)->orderBy('id')->get()
            ->map(fn ($f) => collect($f->getAttributes())->except('updated_at')->all())->all();
        SiennaLigneNueCompatibilitesRetrait::run();
        $second = SiennaLigneNueCompatibilitesRetrait::run();

        $this->assertSame([], $second['removed']);
        $this->assertCount(4, $second['absent']);
        $this->assertSame(4, DB::table('part_fitment_archives')->count());

        $this->assertSame(4, SiennaLigneNueCompatibilitesRetrait::restaurer());
        $this->assertSame(0, DB::table('part_fitment_archives')->count());
        $restaurees = PartFitment::where('vehicle_model_id', $this->nue->id)->orderBy('year_from')->get();
        $this->assertSame(
            [['2GR-FE', 2007, 2016], ['1AR-FE', 2011, 2013], ['2GR-FKS', 2017, 2020], ['A25A-FXS', 2021, null]],
            $restaurees->map(fn ($f) => [$f->engine_code, $f->year_from, $f->year_to])->all(),
        );
        $apres = PartFitment::where('vehicle_model_id', $this->nue->id)->orderBy('id')->get()
            ->map(fn ($f) => collect($f->getAttributes())->except('updated_at')->all())->all();
        $this->assertEquals($avant, $apres, 'Restaurées à l\'identique : même id, même contenu.');
    }

    public function test_l_api_ne_trouve_plus_rien_sur_la_ligne_nue_et_tout_sur_les_generations(): void
    {
        SiennaLigneNueCompatibilitesRetrait::run();
        $service = app(CompatibilityService::class);
        $yzza1 = $this->pieces['04152-YZZA1']->id;
        $yzzn1 = $this->pieces['90915-YZZN1']->id;

        $this->assertSame([], $service->partsForCriteria($this->nue->id, 2012, null, null, null, '1AR-FE')->pluck('id')->all());
        $this->assertContains($yzza1, $service->partsForCriteria($this->modele('sienna-xl30'), 2012, null, null, null, '1AR-FE')->pluck('id')->all());
        $this->assertContains($yzza1, $service->partsForCriteria($this->modele('sienna-xl20'), 2008, null, null, null, '2GR-FE')->pluck('id')->all());
        $this->assertContains($yzzn1, $service->partsForCriteria($this->modele('sienna-xl40'), 2022, null, null, null, 'A25A-FXS')->pluck('id')->all());
        // Négatif : pas de cartouche sur une XL40 hybride, pas de 1AR-FE en 2015.
        $this->assertNotContains($yzza1, $service->partsForCriteria($this->modele('sienna-xl40'), 2022, null, null, null, 'A25A-FXS')->pluck('id')->all());
        $this->assertNotContains($yzza1, $service->partsForCriteria($this->modele('sienna-xl30'), 2015, null, null, null, '1AR-FE')->pluck('id')->all());
    }

    // ── Motorisations de la ligne nue ────────────────────────────────────────

    public function test_les_86_cotes_de_production_sont_toutes_reattribuees(): void
    {
        $rapport = SiennaMotorisationsReattribution::run();

        $this->assertSame(86, $rapport['reassigned']);
        $this->assertSame(0, $rapport['ambiguous']);
        $this->assertSame(0, $rapport['not_reassigned']);
        $this->assertSame([], $rapport['skipped_missing_generation']);
        $this->assertSame([], $rapport['ambiguous_ids']);
        $this->assertSame(0, Motorisation::where('vehicle_model_id', $this->nue->id)->count());

        $parGeneration = Motorisation::query()->join('vehicle_models', 'vehicle_models.id', '=', 'motorisations.vehicle_model_id')
            ->selectRaw('vehicle_models.slug as slug, count(*) as n')->groupBy('vehicle_models.slug')->pluck('n', 'slug')->map(fn ($n) => (int) $n)->all();
        ksort($parGeneration);
        // XL20 : 2007-2009 ×4 + 2010 ×4 (2 année-modèle + 2 de nature
        // inconnue, réattribuées : GSL2# produits jusqu'en 01/2010 et
        // boîte A5 absente de la XL30) ; XL30 : 2011-2020 ; XL40 : 2021-2026.
        $this->assertSame(['sienna-xl20' => 16, 'sienna-xl30' => 46, 'sienna-xl40' => 24], $parGeneration);

        // Aucune cote créée ni supprimée.
        $this->assertSame(86, Motorisation::count());
        $this->assertSame(86, DB::table('motorisation_generation_assignments')->count());
    }

    public function test_les_annees_de_transition_sont_tranchees_par_la_boite(): void
    {
        SiennaMotorisationsReattribution::run();
        $slug = fn (int $prodId) => VehicleModel::find(Motorisation::find($this->cotes[$prodId])->vehicle_model_id)->slug;

        $this->assertSame('sienna-xl20', $slug(54001), '2010 année-modèle, A5 : XL20 (Toyota, Sienna 2010 en 5 rapports).');
        $this->assertSame('sienna-xl20', $slug(54002));
        $this->assertSame('sienna-xl20', $slug(24780), '2010 de nature inconnue, A5 : XL20 (GSL2# jusqu\'en 01/2010 ; A5 absente de la XL30).');
        $this->assertSame('sienna-xl20', $slug(24781));
        $this->assertSame('sienna-xl30', $slug(55107), '2011 2,7 l AS6 : XL30 1AR-FE.');
        $this->assertSame('sienna-xl30', $slug(36870), '2020 année-modèle AS8 : XL30 2GR-FKS, pas la XL40.');
        $this->assertSame('sienna-xl30', $slug(7683), '2020 de nature inconnue, AS8 : XL30 dans les deux lectures.');
        $this->assertSame('sienna-xl40', $slug(37841), '2021 2,5 l AV : XL40 hybride.');

        $journal = DB::table('motorisation_generation_assignments')->where('motorisation_id', $this->cotes[55107])->first();
        $this->assertSame('1AR-FE', $journal->engine_code_deduced);
        $this->assertSame('ASL30', $journal->chassis_codes_deduced);
        $this->assertSame('xl30-1ar-fe-as6', $journal->rule);

        // Le moteur déduit n'est PAS recopié dans la cote.
        $this->assertNull(Motorisation::find($this->cotes[55107])->engine_code);
    }

    public function test_une_cote_sans_regle_ou_utilisee_par_un_garage_n_est_pas_deplacee(): void
    {
        $etrange = Motorisation::create([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2012, 'year_kind' => 'model_year',
            'make_raw' => 'Toyota', 'model_raw' => 'Sienna', 'vehicle_model_id' => $this->nue->id,
            'brand_id' => $this->nue->brand_id, 'engine_l' => 3.5, 'cylinders' => 6, 'transmission_code' => 'A5',
            'fuel_code' => 'X', 'market' => 'CA', 'cle_source' => hash('sha256', 'etrange'), 'identity_key' => hash('sha256', 'etrange'),
        ]);
        $verifiee = Motorisation::find($this->cotes[55108]);
        $verifiee->forceFill(['verified_at' => now()])->save();

        $rapport = SiennaMotorisationsReattribution::run();

        $this->assertSame(2, $rapport['not_reassigned']);
        $this->assertSame($this->nue->id, (int) $etrange->fresh()->vehicle_model_id);
        $this->assertSame($this->nue->id, (int) $verifiee->fresh()->vehicle_model_id);
        $this->assertSame('not_reassigned', DB::table('motorisation_generation_assignments')->where('motorisation_id', $etrange->id)->value('decision'));
    }

    public function test_la_reattribution_est_idempotente_et_le_down_restaure(): void
    {
        SiennaMotorisationsReattribution::run();
        $second = SiennaMotorisationsReattribution::run();

        $this->assertSame(0, $second['reassigned']);
        // Plus aucune cote sur la ligne nue : le journal (86) suffit a prouver l'idempotence.
        $this->assertSame(0, $second['already_logged']);
        $this->assertSame(86, DB::table('motorisation_generation_assignments')->count());

        $this->assertSame(86, SiennaMotorisationsReattribution::restaurer());
        $this->assertSame(86, Motorisation::where('vehicle_model_id', $this->nue->id)->count());
        $this->assertSame(0, DB::table('motorisation_generation_assignments')->count());
    }

    public function test_sans_generations_rien_n_est_journalise(): void
    {
        VehicleModel::whereIn('slug', ['sienna-xl20', 'sienna-xl30', 'sienna-xl40'])->delete();

        $rapport = SiennaMotorisationsReattribution::run();

        $this->assertSame(0, $rapport['reassigned']);
        $this->assertEqualsCanonicalizing(['sienna-xl20', 'sienna-xl30', 'sienna-xl40'], $rapport['skipped_missing_generation']);
        $this->assertSame(86, Motorisation::where('vehicle_model_id', $this->nue->id)->count());
        // Aucune cote n'est journalisée tant que les générations manquent :
        // une exécution ultérieure pourra conclure (y compris les 2010 A5).
        $this->assertSame(0, DB::table('motorisation_generation_assignments')->count());
    }

    public function test_le_rattachement_automatique_ne_defait_pas_la_decision(): void
    {
        SiennaMotorisationsReattribution::run();

        $this->artisan('catalog:import-consommations', ['--relier-seulement' => true])->assertSuccessful();

        // Sans la garde, la règle d'années poserait la 2010 A5 sur la XL30
        // (2010-2020) et la 2010 ambiguë aussi.
        $this->assertSame('sienna-xl20', VehicleModel::find(Motorisation::find($this->cotes[54001])->vehicle_model_id)->slug);
        $this->assertSame('sienna-xl20', VehicleModel::find(Motorisation::find($this->cotes[24780])->vehicle_model_id)->slug, 'Sans la garde, la règle d\'années poserait aussi la 2010 A5 legacy sur la XL30.');
    }

    // ── Les trois migrations, rejouées ───────────────────────────────────────

    public function test_les_migrations_rejouees_deux_fois_ne_changent_rien_de_plus(): void
    {
        $journaux = $this->migration('2026_11_26_180000_creer_les_journaux_de_restructuration_du_referentiel');
        $retrait = $this->migration('2026_11_26_180100_retirer_les_compatibilites_de_la_sienna_nue');
        $reattribution = $this->migration('2026_11_26_180200_reattribuer_les_motorisations_de_la_sienna');

        ob_start();
        foreach ([1, 2] as $passe) {
            $journaux->up();
            $retrait->up();
            $reattribution->up();
        }
        ob_end_clean();

        $this->assertSame(0, PartFitment::where('vehicle_model_id', $this->nue->id)->count());
        $this->assertSame(4, DB::table('part_fitment_archives')->count());
        $this->assertSame(0, Motorisation::where('vehicle_model_id', $this->nue->id)->count());
        $this->assertSame(86, DB::table('motorisation_generation_assignments')->count());

        // down() dans l'ordre inverse : retour exact à l'état de départ.
        $reattribution->down();
        $retrait->down();
        $this->assertSame(4, PartFitment::where('vehicle_model_id', $this->nue->id)->count());
        $this->assertSame(86, Motorisation::where('vehicle_model_id', $this->nue->id)->count());
    }
}
