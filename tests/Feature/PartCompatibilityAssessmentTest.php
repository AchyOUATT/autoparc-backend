<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\VehicleModel;
use App\Services\PartCompatibilityAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartCompatibilityAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $model;

    private EngineType $petrol;

    private Drivetrain $awd;

    protected function setUp(): void
    {
        parent::setUp();

        $brand = Brand::create(['name' => 'Marque fictive', 'slug' => 'marque-fictive']);
        $this->model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'Modèle fictif',
            'generation' => 'G1',
            'production_start' => 2010,
            'production_end' => 2020,
        ]);
        $this->petrol = EngineType::create(['code' => 'test-petrol', 'label' => 'Essence de test']);
        $this->awd = Drivetrain::create(['code' => 'TEST-AWD', 'label' => 'Intégrale de test']);
    }

    private function part(array $fitment = []): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'categorie-fictive'], ['name' => 'Catégorie fictive']);
        $part = Part::create([
            'name' => 'Pièce fictive',
            'part_category_id' => $category->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 1000,
        ]);
        $part->fitments()->create([
            'vehicle_model_id' => $this->model->id,
            'source' => FitmentSource::Declared,
            ...$fitment,
        ]);

        return $part;
    }

    private function assess(Part $part, array $criteria = []): array
    {
        return app(PartCompatibilityAssessment::class)->assess($part, [
            'vehicle_model_id' => $this->model->id,
            'year' => 2015,
            ...$criteria,
        ]);
    }

    public function test_essence_seule_ne_confirme_pas_deux_pieces_specifiques_a_des_moteurs_differents(): void
    {
        foreach (['TEST-A', 'TEST-B'] as $code) {
            $part = $this->part(['engine_code' => $code, 'engine_type_id' => $this->petrol->id]);
            $assessment = $this->assess($part, ['engine_type_id' => $this->petrol->id]);

            $this->assertSame('candidate', $assessment['status']);
            $this->assertFalse($assessment['compatible']);
            $this->assertFalse($assessment['compatibility_ready']);
            $this->assertSame(['engine_code'], $assessment['missing_criteria']);
            $this->assertSame('missing_vehicle_criteria', $assessment['reason']);
        }
    }

    public function test_le_code_complet_normalise_confirme_une_reference_et_ecarte_une_autre(): void
    {
        $part = $this->part(['engine_code' => 'TEST-A']);
        $exact = $this->assess($part, ['engine_code' => ' test-a ']);
        $other = $this->assess($part, ['engine_code' => 'TEST-B']);

        $this->assertSame('confirmed', $exact['status']);
        $this->assertTrue($exact['compatible']);
        $this->assertTrue($exact['compatibility_ready']);
        $this->assertSame([], $exact['missing_criteria']);
        $this->assertSame('matching_declared_fitment', $exact['reason']);
        $this->assertSame('unknown', $other['status']);
        $this->assertFalse($other['compatible']);
        $this->assertSame('no_matching_fitment', $other['reason']);
    }

    public function test_un_caractere_du_vin_ne_devient_pas_un_code_moteur_resolu(): void
    {
        $part = $this->part(['engine_code' => 'TEST-A']);
        $assessment = $this->assess($part, ['engine_code' => 'A']);

        $this->assertSame('candidate', $assessment['status']);
        $this->assertSame(['engine_code'], $assessment['missing_criteria']);
    }

    public function test_la_motricite_manquante_est_demandee_et_une_autre_motricite_ecarte_la_ligne(): void
    {
        $part = $this->part(['drivetrain_id' => $this->awd->id]);
        $other = Drivetrain::create(['code' => 'TEST-FWD', 'label' => 'Traction de test']);

        $this->assertSame(['drivetrain_id'], $this->assess($part)['missing_criteria']);
        $this->assertSame('unknown', $this->assess($part, ['drivetrain_id' => $other->id])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['drivetrain_id' => $this->awd->id])['status']);
    }

    public function test_les_valeurs_par_defaut_de_la_finition_ne_remplacent_pas_les_criteres_explicites(): void
    {
        $trim = Trim::create([
            'vehicle_model_id' => $this->model->id,
            'name' => 'Finition fictive',
            'default_engine_type_id' => $this->petrol->id,
            'default_drivetrain_id' => $this->awd->id,
        ]);
        $part = $this->part([
            'trim_id' => $trim->id,
            'engine_type_id' => $this->petrol->id,
            'drivetrain_id' => $this->awd->id,
        ]);
        $assessment = $this->assess($part, ['trim_id' => $trim->id]);

        $this->assertSame('candidate', $assessment['status']);
        $this->assertSame(['engine_type_id', 'drivetrain_id'], $assessment['missing_criteria']);
    }

    public function test_une_ligne_generee_ne_confirme_jamais_meme_si_les_criteres_sont_complets(): void
    {
        $part = $this->part(['source' => FitmentSource::Generated, 'engine_code' => 'TEST-A']);
        $assessment = $this->assess($part, ['engine_code' => 'TEST-A']);

        $this->assertSame('candidate', $assessment['status']);
        $this->assertFalse($assessment['compatible']);
        $this->assertFalse($assessment['compatibility_ready']);
        $this->assertSame([], $assessment['missing_criteria']);
        $this->assertSame('generated_fitment', $assessment['reason']);
    }

    public function test_une_ligne_universelle_declaree_suffit_sans_moteur_ni_annee(): void
    {
        $assessment = $this->assess($this->part(), ['year' => null]);

        $this->assertSame('confirmed', $assessment['status']);
        $this->assertSame([], $assessment['missing_criteria']);
    }

    public function test_une_confirmation_declaree_prevaut_sur_les_candidates_generees(): void
    {
        $part = $this->part(['source' => FitmentSource::Generated, 'engine_code' => 'TEST-A']);
        $part->fitments()->create([
            'vehicle_model_id' => $this->model->id,
            'engine_code' => 'TEST-A',
            'source' => FitmentSource::Declared,
        ]);

        $this->assertSame('confirmed', $this->assess($part, ['engine_code' => 'TEST-A'])['status']);
    }

    public function test_les_bornes_annees_sont_inclusives_et_une_annee_absente_reste_a_verifier(): void
    {
        $part = $this->part(['year_from' => 2014, 'year_to' => 2016]);

        $this->assertSame('confirmed', $this->assess($part, ['year' => 2014])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['year' => 2016])['status']);
        $this->assertSame('unknown', $this->assess($part, ['year' => 2013])['status']);
        $this->assertSame('unknown', $this->assess($part, ['year' => 2017])['status']);
        $missing = $this->assess($part, ['year' => null]);
        $this->assertSame('candidate', $missing['status']);
        $this->assertSame(['year'], $missing['missing_criteria']);
    }

    public function test_le_chemin_oem_applique_les_memes_contraintes_sans_certification_implicite(): void
    {
        $part = $this->part(['engine_code' => 'OTHER-TEST']);
        $oem = OemNumber::create(['number' => 'OEM-FICTIF', 'brand_id' => $this->model->brand_id]);
        $part->oemNumbers()->attach($oem);
        $oem->fitments()->create([
            'vehicle_model_id' => $this->model->id,
            'engine_code' => 'TEST-A',
            'year_from' => 2014,
            'year_to' => 2016,
        ]);

        $candidate = $this->assess($part);
        $confirmed = $this->assess($part, ['engine_code' => 'TEST-A']);

        $this->assertSame('candidate', $candidate['status']);
        $this->assertSame(['engine_code'], $candidate['missing_criteria']);
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame('matching_oem_fitment', $confirmed['reason']);
    }

    public function test_sans_modele_normalise_la_reponse_est_inconnue_et_non_incompatible(): void
    {
        $assessment = $this->assess($this->part(), ['vehicle_model_id' => null]);

        $this->assertSame('unknown', $assessment['status']);
        $this->assertSame(['vehicle_model_id'], $assessment['missing_criteria']);
        $this->assertFalse($assessment['compatible']);
        $this->assertFalse($assessment['compatibility_ready']);
        $this->assertSame('missing_vehicle_model', $assessment['reason']);
    }

    public function test_labsence_de_ligne_ou_un_autre_modele_ne_prouve_pas_une_incompatibilite(): void
    {
        $part = $this->part();
        $otherModel = VehicleModel::create(['brand_id' => $this->model->brand_id, 'name' => 'Autre modèle fictif']);
        $assessment = $this->assess($part, ['vehicle_model_id' => $otherModel->id]);

        $this->assertSame('unknown', $assessment['status']);
        $this->assertSame('no_matching_fitment', $assessment['reason']);
        $this->assertSame([], $assessment['missing_criteria']);
        $part->fitments()->delete();
        $this->assertSame('unknown', $this->assess($part->fresh())['status']);
    }

    public function test_les_criteres_manquants_sont_reunis_sans_doublon_entre_candidates(): void
    {
        $part = $this->part(['engine_code' => 'TEST-A', 'year_from' => 2010]);
        $part->fitments()->create([
            'vehicle_model_id' => $this->model->id,
            'engine_code' => 'TEST-B',
            'drivetrain_id' => $this->awd->id,
            'source' => FitmentSource::Declared,
        ]);
        $assessment = $this->assess($part, ['year' => null]);

        $this->assertSame('candidate', $assessment['status']);
        $this->assertEqualsCanonicalizing(['year', 'engine_code', 'drivetrain_id'], $assessment['missing_criteria']);
    }

    public function test_les_relations_prechargees_ne_produisent_pas_de_requetes_par_evaluation(): void
    {
        $part = $this->part(['engine_code' => 'TEST-A']);
        $part->load(['fitments', 'oemNumbers.fitments']);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertSame('confirmed', $this->assess($part, ['engine_code' => 'TEST-A'])['status']);
        $this->assertSame(0, $queries);
    }
}
