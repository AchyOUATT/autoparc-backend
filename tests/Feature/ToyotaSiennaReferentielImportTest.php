<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\FitmentEvidence;
use App\Support\ToyotaSiennaReferentielImport;
use Database\Seeders\ColorsSeeder;
use Database\Seeders\DrivetrainsSeeder;
use Database\Seeders\EngineTypesSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Restructuration du référentiel Sienna (26/11/2026) : générations XL20 /
 * XL30 / XL40, compatibilités réévaluées, ligne nue inactive et intacte.
 */
class ToyotaSiennaReferentielImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Part> */
    private array $pieces = [];

    private VehicleModel $nue;

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

        // État de production lu le 26/11/2026 : ligne nue 2007-ouvert, active,
        // quatre compatibilités déclarées.
        $this->nue = VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Sienna', 'slug' => 'sienna', 'generation' => null,
            'body_type' => 'monospace', 'segment' => 'E', 'production_start' => 2007, 'production_end' => null,
            'is_active' => true,
        ]);
        foreach ([
            ['04152-YZZA1', '2GR-FE', 2007, 2016],
            ['04152-YZZA1', '1AR-FE', 2011, 2013],
            ['04152-YZZA1', '2GR-FKS', 2017, 2020],
            ['90915-YZZN1', 'A25A-FXS', 2021, null],
        ] as [$sku, $moteur, $de, $a]) {
            $this->pieces[$sku]->fitments()->create([
                'vehicle_model_id' => $this->nue->id, 'engine_code' => $moteur,
                'year_from' => $de, 'year_to' => $a, 'source' => 'declared', 'notes' => 'Catalogue de correspondance du 18/10/2026',
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function lignesNues(): array
    {
        return PartFitment::where('vehicle_model_id', $this->nue->id)->orderBy('id')
            ->get(['id', 'part_id', 'engine_code', 'year_from', 'year_to', 'notes', 'source'])
            ->map(fn ($f) => $f->only(['id', 'part_id', 'engine_code', 'year_from', 'year_to', 'notes']))
            ->all();
    }

    public function test_il_pose_les_trois_generations_avec_leurs_bornes_epc(): void
    {
        $rapport = ToyotaSiennaReferentielImport::run();

        $this->assertSame(['sienna-xl20', 'sienna-xl30', 'sienna-xl40'], $rapport['models_created']);
        $attendu = ['sienna-xl20' => ['XL20', 2003, 2010], 'sienna-xl30' => ['XL30', 2010, 2020], 'sienna-xl40' => ['XL40', 2020, null]];
        foreach ($attendu as $slug => [$gen, $de, $a]) {
            $modele = VehicleModel::where('slug', $slug)->firstOrFail();
            $this->assertSame('Sienna', $modele->name);
            $this->assertSame($gen, $modele->generation);
            $this->assertSame($de, $modele->production_start);
            $this->assertSame($a, $modele->production_end);
            $this->assertTrue((bool) $modele->is_active);
            $this->assertSame('monospace', $modele->body_type);
        }
        $this->assertSame(
            array_map(fn ($g) => [$g[1], $g[4], $g[5]], VehicleModelsSeeder::GENERATIONS_SIENNA),
            array_values($attendu),
        );
    }

    public function test_les_compatibilites_reevaluees_portent_chassis_epc_et_restent_dans_leur_generation(): void
    {
        $rapport = ToyotaSiennaReferentielImport::run();
        $this->assertSame(6, $rapport['fitments_inserted']);

        $poses = PartFitment::with(['part', 'vehicleModel'])
            ->whereHas('vehicleModel', fn ($q) => $q->where('slug', 'like', 'sienna-xl%'))
            ->get()
            ->map(fn ($f) => [$f->part->sku, $f->vehicleModel->slug, $f->engine_code, $f->year_from, $f->year_to, $f->chassis_code])
            ->sort()->values()->all();

        $this->assertSame([
            ['04152-YZZA1', 'sienna-xl20', '2GR-FE', 2006, 2010, 'GSL20/GSL23/GSL25'],
            ['04152-YZZA1', 'sienna-xl30', '1AR-FE', 2010, 2013, 'ASL30'],
            ['04152-YZZA1', 'sienna-xl30', '2GR-FE', 2010, 2016, 'GSL30/GSL33/GSL35'],
            ['04152-YZZA1', 'sienna-xl30', '2GR-FKS', 2016, 2020, 'GSL30/GSL33/GSL35'],
            ['90915-YZZD1', 'sienna-xl20', '3MZ-FE', 2003, 2006, 'MCL20/MCL23/MCL25'],
            ['90915-YZZN1', 'sienna-xl40', 'A25A-FXS', 2020, null, 'AXLH40/AXLH45'],
        ], $poses);

        foreach (PartFitment::with('vehicleModel')->whereHas('vehicleModel', fn ($q) => $q->where('slug', 'like', 'sienna-xl%'))->get() as $ligne) {
            $gen = $ligne->vehicleModel;
            $this->assertGreaterThanOrEqual($gen->production_start, $ligne->year_from, $gen->slug);
            if ($gen->production_end !== null) {
                $this->assertLessThanOrEqual($gen->production_end, $ligne->year_to, $gen->slug);
            }
            $this->assertStringContainsString('EPC Toyota, marché USA', $ligne->notes);
            $this->assertMatchesRegularExpression('#toyota-epc\.aftermarketcatalog\.com/vehicle/US/6224[234]0/#', $ligne->notes);
            $this->assertStringContainsString('06_OilFilters2025_hi.pdf', $ligne->notes);
            $this->assertSame('declared', $ligne->source->value);
        }
    }

    public function test_la_ligne_nue_devient_inactive_sans_perdre_ses_lignes_ni_ses_liens(): void
    {
        $avant = $this->lignesNues();
        $motorisation = Motorisation::create([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2015, 'year_kind' => 'model_year',
            'make_raw' => 'Toyota', 'model_raw' => 'Sienna', 'vehicle_model_id' => $this->nue->id,
            'brand_id' => $this->nue->brand_id, 'engine_l' => 3.5, 'cylinders' => 6, 'transmission_code' => 'AS6',
            'fuel_code' => 'X', 'consumption_city' => 13.0, 'consumption_highway' => 9.4, 'consumption_combined' => 11.4,
            'cle_source' => bin2hex(random_bytes(16)),
        ])->id;

        $rapport = ToyotaSiennaReferentielImport::run();

        $this->assertSame('deactivated', $rapport['bare_model']);
        $nue = $this->nue->fresh();
        $this->assertFalse((bool) $nue->is_active);
        $this->assertNull($nue->production_start);
        $this->assertNull($nue->production_end);
        $this->assertSame('sienna', $nue->slug, 'Ni renommée…');
        $this->assertSame('Sienna', $nue->name);
        $this->assertSame($avant, $this->lignesNues(), '…ni ses quatre lignes modifiées.');
        $this->assertSame($this->nue->id, (int) DB::table('motorisations')->where('id', $motorisation)->value('vehicle_model_id'),
            'Les motorisations nrcan restent sur la ligne nue.');
    }

    public function test_une_ligne_nue_referencee_par_un_vehicule_reste_active(): void
    {
        $this->seed([ColorsSeeder::class, EngineTypesSeeder::class, DrivetrainsSeeder::class]);
        Vehicle::factory()->create(['brand_id' => $this->nue->brand_id, 'vehicle_model_id' => $this->nue->id]);

        $rapport = ToyotaSiennaReferentielImport::run();

        $this->assertSame('kept_active_vehicles_attached', $rapport['bare_model']);
        $this->assertTrue((bool) $this->nue->fresh()->is_active);
        $this->assertSame(2007, $this->nue->fresh()->production_start);
    }

    public function test_rejouer_est_idempotent(): void
    {
        ToyotaSiennaReferentielImport::run();
        $modeles = VehicleModel::count();
        $lignes = PartFitment::count();

        $rapport = ToyotaSiennaReferentielImport::run();

        $this->assertSame([], $rapport['models_created']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(6, $rapport['fitments_already_present']);
        $this->assertSame(0, $rapport['fitments_evidence_qualified']);
        $this->assertSame($modeles, VehicleModel::count());
        $this->assertSame($lignes, PartFitment::count());
    }

    public function test_chaque_ligne_porte_une_preuve_structuree_et_le_rejeu_qualifie_les_lignes_anciennes(): void
    {
        ToyotaSiennaReferentielImport::run();

        $lignes = PartFitment::whereHas('vehicleModel', fn ($q) => $q->where('slug', 'like', 'sienna-xl%'))->get();
        $this->assertCount(6, $lignes);
        foreach ($lignes as $ligne) {
            $this->assertTrue(FitmentEvidence::isDocumented($ligne), $ligne->notes);
            $this->assertSame('manufacturer_official', $ligne->evidence_level, 'Le PDF Toyota nomme directement la référence sur chaque ligne.');
            $this->assertNotEmpty($ligne->evidence_sources);
            $sources = is_array($ligne->evidence_sources) ? $ligne->evidence_sources : [];
            $this->assertTrue(
                collect($sources)->contains(fn ($url) => str_contains((string) $url, '06_OilFilters2025_hi.pdf')),
                'La source structurée doit inclure le PDF constructeur déjà cité.',
            );
            $this->assertTrue(
                collect($sources)->contains(fn ($url) => (bool) preg_match('#toyota-epc\.aftermarketcatalog\.com/vehicle/US/6224[234]0/#', (string) $url)),
                'La source structurée doit inclure le catalogue EPC 622420/622430/622440 déjà cité.',
            );
            foreach ($sources as $url) {
                $this->assertStringContainsString($url, $ligne->notes, 'Une source structurée doit être citée par la note.');
            }
        }

        // Lignes posées avant les colonnes de preuve (migration 170000) :
        // le rejeu de la 203000 les qualifie, une seule fois, sans réécrire
        // une ligne déjà qualifiée ou dont la note a changé.
        \DB::table('part_fitments')->whereIn('id', $lignes->pluck('id'))->update(['evidence_level' => null, 'evidence_sources' => null]);
        $deja = $lignes->first();
        $deja->update(['evidence_level' => 'unverified']);
        $this->assertSame(5, ToyotaSiennaReferentielImport::run()['fitments_evidence_qualified']);
        $this->assertSame('unverified', $deja->fresh()->evidence_level, 'Une preuve déjà posée n\'est jamais réécrite.');
        $this->assertSame(0, ToyotaSiennaReferentielImport::run()['fitments_evidence_qualified']);
        $this->assertSame(5, PartFitment::whereIn('id', $lignes->pluck('id'))->whereNotNull('evidence_level')->where('evidence_level', '!=', 'unverified')->count());
    }

    public function test_sans_l_article_la_ligne_est_ignoree_sans_erreur(): void
    {
        $this->pieces['90915-YZZD1']->forceDelete();

        $rapport = ToyotaSiennaReferentielImport::run();

        $this->assertSame(['90915-YZZD1'], $rapport['fitments_skipped_missing_part']);
        $this->assertSame(5, $rapport['fitments_inserted']);
    }

    private function statut(string $sku, string $slug, ?int $annee, ?string $moteur): ?string
    {
        $criteres = array_filter([
            'vehicle_model_id' => VehicleModel::where('slug', $slug)->value('id'),
            'year' => $annee,
            'engine_code' => $moteur,
            'include_candidates' => 1,
        ], fn ($v) => $v !== null);

        foreach ($this->getJson('/api/catalog/compatible-parts?'.http_build_query($criteres))->assertOk()->json('data') as $piece) {
            if ((int) $piece['id'] === $this->pieces[$sku]->id) {
                return $piece['compatibility_assessment']['status'];
            }
        }

        return null;
    }

    public function test_api_positifs_et_negatifs_par_generation(): void
    {
        ToyotaSiennaReferentielImport::run();

        // XL20 : 3MZ-FE → YZZD1, 2GR-FE → YZZA1 ; jamais l'inverse.
        $this->assertSame('confirmed', $this->statut('90915-YZZD1', 'sienna-xl20', 2005, '3MZ-FE'));
        $this->assertNull($this->statut('90915-YZZD1', 'sienna-xl20', 2008, '2GR-FE'), 'Le 2GR-FE prend une cartouche.');
        $this->assertNull($this->statut('90915-YZZD1', 'sienna-xl20', 2007, '3MZ-FE'), 'MCL2# fabriqués jusqu\'en 12/2006.');
        $this->assertSame('confirmed', $this->statut('04152-YZZA1', 'sienna-xl20', 2007, '2GR-FE'));
        $this->assertSame('confirmed', $this->statut('04152-YZZA1', 'sienna-xl20', 2010, '2GR-FE'), 'GSL2# fabriqués jusqu\'en 01/2010.');
        $this->assertNull($this->statut('04152-YZZA1', 'sienna-xl20', 2011, '2GR-FE'), 'Plus de GSL2# après 01/2010.');
        $this->assertNull($this->statut('04152-YZZA1', 'sienna-xl20', 2004, '3MZ-FE'));

        // XL30
        $this->assertSame('confirmed', $this->statut('04152-YZZA1', 'sienna-xl30', 2012, '2GR-FE'));
        $this->assertSame('confirmed', $this->statut('04152-YZZA1', 'sienna-xl30', 2010, '2GR-FE'), 'GSL3# fabriqués dès 01/2010 : janvier 2010 chevauche GSL2# et GSL3#.');
        $this->assertSame('confirmed', $this->statut('04152-YZZA1', 'sienna-xl30', 2010, '1AR-FE'), 'ASL30 fabriqué dès 05/2010.');
        $this->assertNull($this->statut('04152-YZZA1', 'sienna-xl30', 2014, '1AR-FE'), 'ASL30 fabriqué jusqu\'en 08/2013.');
        $this->assertSame('confirmed', $this->statut('04152-YZZA1', 'sienna-xl30', 2018, '2GR-FKS'));
        $this->assertNull($this->statut('90915-YZZN1', 'sienna-xl30', 2018, '2GR-FKS'));

        // XL40
        $this->assertSame('confirmed', $this->statut('90915-YZZN1', 'sienna-xl40', 2022, 'A25A-FXS'));
        $this->assertNull($this->statut('04152-YZZA1', 'sienna-xl40', 2022, 'A25A-FXS'));

        // Critère inconnu : candidat, jamais confirmé.
        $this->assertSame('candidate', $this->statut('04152-YZZA1', 'sienna-xl30', 2012, null));
        $this->assertSame('candidate', $this->statut('90915-YZZN1', 'sienna-xl40', null, 'A25A-FXS'));
    }
}
