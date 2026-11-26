<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\OemNumber;
use App\Models\OemNumberRelation;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\PartCompatibilityAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trois verdicts (confirmed / candidate / excluded, plus unknown sans ligne),
 * preuve suffisante, non-transmission des compatibilités par un numéro
 * remplacé, modèles inactifs hors des recherches courantes.
 */
class CompatibiliteTroisEtatsPreuveTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private VehicleModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::create(['name' => 'Toyota test', 'slug' => 'toyota-test-3e']);
        $this->model = VehicleModel::create([
            'brand_id' => $this->brand->id, 'name' => 'Camry', 'generation' => 'XV20', 'slug' => 'camry-xv20-test',
        ]);
    }

    private function part(array $fitment = [], bool $avecLigne = true, ?VehicleModel $model = null): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'categorie-3e'], ['name' => 'Catégorie 3E']);
        $part = Part::create([
            'name' => 'Filtre 3E', 'part_category_id' => $category->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000,
        ]);
        if ($avecLigne) {
            $part->fitments()->create([
                'vehicle_model_id' => ($model ?? $this->model)->id,
                'source' => FitmentSource::Declared,
                ...$fitment,
            ]);
        }

        return $part->fresh();
    }

    private function assess(Part $part, array $criteria = []): array
    {
        $svc = app(PartCompatibilityAssessment::class);

        return $svc->assess($part, $svc->withModelState([
            'vehicle_model_id' => $this->model->id, 'year' => 1999, ...$criteria,
        ]));
    }

    private function url(array $extra = []): string
    {
        return '/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $this->model->id, 'year' => 1999, ...$extra,
        ]);
    }

    // ── Trois verdicts ─────────────────────────────────────────────────

    public function test_trois_verdicts_et_inconnu(): void
    {
        $part = $this->part(['engine_code' => '1MZ-FE', 'year_from' => 1996, 'year_to' => 2001]);

        $ok = $this->assess($part, ['engine_code' => '1MZ-FE']);
        $this->assertSame(['confirmed', true, true], [$ok['status'], $ok['compatible'], $ok['compatibility_ready']]);

        $aVerifier = $this->assess($part);
        $this->assertSame(['candidate', false, false], [$aVerifier['status'], $aVerifier['compatible'], $aVerifier['compatibility_ready']]);
        $this->assertSame(['engine_code'], $aVerifier['missing_criteria']);

        $exclu = $this->assess($part, ['engine_code' => '5S-FE']);
        $this->assertSame(['excluded', false, false], [$exclu['status'], $exclu['compatible'], $exclu['compatibility_ready']]);
        $this->assertSame(['engine_code'], $exclu['excluded_criteria']);

        $horsPlage = $this->assess($part, ['engine_code' => '1MZ-FE', 'year' => 2003]);
        $this->assertSame('excluded', $horsPlage['status']);
        $this->assertSame(['year'], $horsPlage['excluded_criteria']);

        $autre = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Corolla', 'generation' => 'E110']);
        $inconnu = app(PartCompatibilityAssessment::class)->assess($part, ['vehicle_model_id' => $autre->id, 'year' => 1999]);
        $this->assertSame('unknown', $inconnu['status']);
        $this->assertSame('no_matching_fitment', $inconnu['reason']);
    }

    public function test_une_ligne_compatible_l_emporte_sur_une_ligne_exclue(): void
    {
        $part = $this->part(['engine_code' => '5S-FE']);
        $part->fitments()->create(['vehicle_model_id' => $this->model->id, 'engine_code' => '1MZ-FE', 'source' => FitmentSource::Declared]);

        $this->assertSame('confirmed', $this->assess($part->fresh(), ['engine_code' => '1MZ-FE'])['status']);
    }

    public function test_une_candidate_l_emporte_sur_une_exclusion(): void
    {
        $part = $this->part(['engine_code' => '5S-FE']);
        $part->fitments()->create(['vehicle_model_id' => $this->model->id, 'drivetrain_id' => null, 'year_from' => 1996, 'source' => FitmentSource::Declared]);

        $resultat = $this->assess($part->fresh(), ['engine_code' => '1MZ-FE', 'year' => null]);
        $this->assertSame('candidate', $resultat['status']);
        $this->assertSame(['year'], $resultat['missing_criteria']);
    }

    // ── Preuve suffisante ──────────────────────────────────────────────

    public function test_preuve_suffisante_ou_non(): void
    {
        foreach (['manufacturer_official', 'manufacturer_epc', 'dealer_catalog', null] as $niveau) {
            $this->assertSame('confirmed', $this->assess($this->part(['evidence_level' => $niveau]))['status'], (string) $niveau);
        }

        foreach (['aftermarket_crossref', 'unverified'] as $niveau) {
            $resultat = $this->assess($this->part(['evidence_level' => $niveau]));
            $this->assertSame('candidate', $resultat['status'], $niveau);
            $this->assertSame(['evidence'], $resultat['missing_criteria']);
            $this->assertSame('insufficient_evidence', $resultat['reason']);
        }

        $fabriquee = $this->assess($this->part(['source' => FitmentSource::Generated]));
        $this->assertSame('candidate', $fabriquee['status']);
        $this->assertSame('generated_fitment', $fabriquee['reason']);
    }

    public function test_api_ne_confirme_pas_une_preuve_insuffisante(): void
    {
        $faible = $this->part(['evidence_level' => 'aftermarket_crossref']);
        $forte = $this->part(['evidence_level' => 'manufacturer_epc', 'evidence_sources' => ['https://exemple.test/epc']]);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $forte->id);

        $liste = collect($this->getJson($this->url(['include_candidates' => 1]))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('candidate', $liste[$faible->id]['compatibility_assessment']['status']);
        $this->assertFalse($liste[$faible->id]['compatibility_assessment']['compatible']);
        $this->assertSame('confirmed', $liste[$forte->id]['compatibility_assessment']['status']);
    }

    // ── Numéro remplacé : aucune transmission ──────────────────────────

    private function partAvecAncienNumero(bool $drapeauHistorique, bool $lienQualifie, bool $ancienPrincipal = false): Part
    {
        $part = $this->part(avecLigne: false);
        $actuel = OemNumber::create(['number' => '90915-YZZD1']);
        $ancien = OemNumber::create([
            'number' => '90915-20001',
            'is_superseded' => $drapeauHistorique,
            'superseded_by_id' => $drapeauHistorique ? $actuel->id : null,
        ]);
        if ($lienQualifie) {
            OemNumberRelation::create([
                'from_oem_number_id' => $ancien->id, 'to_oem_number_id' => $actuel->id,
                'kind' => OemNumberRelation::KIND_DISTRIBUTOR_REPLACEMENT, 'evidence_level' => 'dealer_catalog',
                'sources' => ['https://exemple.test/concession'],
            ]);
        }
        $part->oemNumbers()->attach($actuel->id, ['is_primary' => ! $ancienPrincipal]);
        $part->oemNumbers()->attach($ancien->id, ['is_primary' => $ancienPrincipal]);
        // Seul l'ancien numéro porte une compatibilité.
        $ancien->fitments()->create(['vehicle_model_id' => $this->model->id]);

        return $part->fresh();
    }

    public function test_un_numero_remplace_ne_transmet_pas_ses_compatibilites(): void
    {
        foreach ([[true, false], [false, true], [true, true]] as [$drapeau, $lien]) {
            $part = $this->partAvecAncienNumero($drapeau, $lien);
            $this->assertSame('unknown', $this->assess($part)['status'], json_encode([$drapeau, $lien]));
        }

        $this->getJson($this->url(['include_candidates' => 1]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_le_numero_principal_d_une_piece_ancienne_garde_ses_compatibilites(): void
    {
        $part = $this->partAvecAncienNumero(true, true, ancienPrincipal: true);

        $this->assertSame('confirmed', $this->assess($part)['status']);
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_la_fiche_oem_expose_la_preuve_du_remplacement(): void
    {
        $this->partAvecAncienNumero(true, true);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $ancien = OemNumber::where('number', '90915-20001')->first();

        $this->getJson("/api/oem-numbers/{$ancien->id}")->assertOk()
            ->assertJsonPath('data.replaced_by.0.number', '90915-YZZD1')
            ->assertJsonPath('data.replaced_by.0.kind', 'distributor_replacement')
            ->assertJsonPath('data.replaced_by.0.evidence_level', 'dealer_catalog')
            ->assertJsonPath('data.replaced_by.0.sources', ['https://exemple.test/concession']);
    }

    // ── Modèles inactifs ───────────────────────────────────────────────

    public function test_un_modele_inactif_sort_des_recherches_courantes_mais_reste_consultable(): void
    {
        $part = $this->part();
        $this->model->update(['is_active' => false]);

        $this->getJson($this->url(['include_candidates' => 1]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url(['include_inactive' => 1]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');

        $inactif = $this->assess($part);
        $this->assertSame('unknown', $inactif['status']);
        $this->assertSame('inactive_vehicle_model', $inactif['reason']);
        $this->assertSame('confirmed', $this->assess($part, ['include_inactive' => true])['status']);

        // Fiche : le résumé courant l'ignore, la liste complète le garde, marquée.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $fiche = $this->getJson("/api/parts/{$part->id}")->assertOk()->json('data');
        $this->assertSame(0, $fiche['compatibility']['count']);
        $this->assertCount(1, $fiche['fitments']);
        $this->assertFalse($fiche['fitments'][0]['vehicle_model_active']);
    }

    public function test_garage_et_oem_ignorent_un_modele_inactif_sauf_demande(): void
    {
        $part = $this->part();
        $oem = OemNumber::create(['number' => '04152-INACT']);
        $oem->fitments()->create(['vehicle_model_id' => $this->model->id]);
        $this->model->update(['is_active' => false]);

        $owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'inactif-3e']);
        OwnedVehicle::create([
            'user_id' => $owner->id, 'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id, 'manufacturing_year' => 1999,
        ]);

        $this->actingAsClient($owner)->getJson("/api/my/parts/{$part->id}/garage-compatibility")
            ->assertOk()->assertJsonPath('data.0.status', 'unknown')->assertJsonPath('data.0.reason', 'inactive_vehicle_model');
        $this->actingAsClient($owner)->getJson("/api/my/parts/{$part->id}/garage-compatibility?include_inactive=1")
            ->assertOk()->assertJsonPath('data.0.status', 'confirmed');

        $this->getJson('/api/oem/04152-INACT/models')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/oem/04152-INACT/models?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
    }

    // ── Lignes du type de la production ────────────────────────────────

    public function test_lignes_de_production_gardent_leur_verdict(): void
    {
        // 90915-YZZD2 sur Hilux AN10 : code moteur, bornes, note fournisseur.
        $diesel = $this->part(['engine_code' => '1KD-FTV', 'year_from' => 2005, 'year_to' => 2015,
            'notes' => 'Premier document fournisseur, ligne retenue par l\'audit du commercant sur source constructeur.']);
        // 90915-10001 : châssis informatif, marché japonais dans la note.
        $jdm = $this->part(['engine_code' => '5S-FE', 'chassis_code' => 'SXV20/SXV20W', 'year_from' => 1996, 'year_to' => 2001,
            'notes' => 'Application documentée, marché Japon (JP) : Camry Gracia, châssis SXV20/SXV20W, moteur 5S-FE.']);
        // Capteur MAP : sans code moteur, bornes seules.
        $map = $this->part(['year_from' => 2013, 'year_to' => 2018]);

        // Véhicule du garage typique : un seul caractère du VIN en code moteur.
        $vin = ['year' => 2010, 'engine_code' => '7'];
        $this->assertSame('candidate', $this->assess($diesel, $vin)['status']);
        $this->assertSame('confirmed', $this->assess($diesel, ['year' => 2010, 'engine_code' => '1KD-FTV'])['status']);
        $this->assertSame('excluded', $this->assess($diesel, ['year' => 2010, 'engine_code' => '2KD-FTV'])['status']);

        // Sans châssis ni marché, la ligne 10001 reste confirmée comme avant.
        $this->assertSame('confirmed', $this->assess($jdm, ['year' => 1999, 'engine_code' => '5S-FE'])['status']);
        $this->assertSame('confirmed', $this->assess($jdm, ['year' => 1999, 'engine_code' => '5S-FE', 'chassis_code' => 'MCV20', 'specification_market' => 'USA'])['status']);

        $this->assertSame('confirmed', $this->assess($map, ['year' => 2015])['status']);
        $this->assertSame('excluded', $this->assess($map, ['year' => 2012])['status']);
        $this->assertSame('candidate', $this->assess($map, ['year' => null])['status']);
    }

    // ── Écriture de la preuve ──────────────────────────────────────────

    public function test_la_preuve_d_une_compatibilite_se_saisit_et_se_conserve(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $ligne = ['vehicle_model_id' => $this->model->id, 'engine_code' => '1MZ-FE'];
        $corps = fn (array $f) => [
            'sku' => '3E-'.bin2hex(random_bytes(4)), 'name' => 'Pièce 3E',
            'part_category_id' => PartCategory::firstOrCreate(['slug' => 'categorie-3e'], ['name' => 'Catégorie 3E'])->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000, 'fitments' => [$f],
        ];

        $this->postJson('/api/parts', $corps($ligne + ['evidence_level' => 'rumeur']))
            ->assertUnprocessable()->assertJsonValidationErrors('fitments.0.evidence_level');

        $id = $this->postJson('/api/parts', $corps($ligne + [
            'evidence_level' => 'manufacturer_epc', 'evidence_sources' => ['https://exemple.test/epc'],
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/parts/{$id}", $corps($ligne) + ['sku' => Part::find($id)->sku])->assertOk();
        $this->getJson("/api/parts/{$id}")->assertOk()
            ->assertJsonPath('data.fitments.0.evidence_level', 'manufacturer_epc')
            ->assertJsonPath('data.fitments.0.evidence_sources', ['https://exemple.test/epc']);
    }
}
