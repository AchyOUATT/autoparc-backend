<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\OemNumber;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompatibilityAssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $model;

    private EngineType $petrol;

    private User $owner;

    private OwnedVehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $brand = Brand::create(['name' => 'Marque de test', 'slug' => 'marque-test']);
        $this->model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Modèle de test', 'generation' => 'G1',
        ]);
        $this->petrol = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'assessment-test']);
        $this->vehicle = OwnedVehicle::create([
            'user_id' => $this->owner->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $this->model->id,
            'manufacturing_year' => 2019,
            'engine_type_id' => $this->petrol->id,
        ]);
    }

    private function part(array $fitment = []): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'test-assessment'], ['name' => 'Pièces de test']);
        $part = Part::create([
            'name' => 'Pièce fictive',
            'part_category_id' => $category->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000,
        ]);
        $part->fitments()->create([
            'vehicle_model_id' => $this->model->id,
            'source' => FitmentSource::Declared,
            ...$fitment,
        ]);

        return $part;
    }

    private function publicCriteria(array $extra = []): string
    {
        return '/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $this->model->id,
            'year' => 2019,
            'engine_type_id' => $this->petrol->id,
            ...$extra,
        ]);
    }

    public function test_la_recherche_publique_identifie_les_candidats_sans_les_confirmer(): void
    {
        $this->part(['engine_code' => 'TEST-A']);
        $this->part(['engine_code' => 'TEST-B']);

        $this->getJson($this->publicCriteria())->assertOk()->assertJsonCount(0, 'data');
        $response = $this->getJson($this->publicCriteria(['include_candidates' => 1]))->assertOk()->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $part) {
            $this->assertSame('candidate', $part['compatibility_assessment']['status']);
            $this->assertFalse($part['compatibility_assessment']['compatible']);
            $this->assertSame(['engine_code'], $part['compatibility_assessment']['missing_criteria']);
            $this->assertArrayNotHasKey('fitments', $part, 'Les preuves chargées en lot ne doivent pas gonfler chaque résultat.');
        }
    }

    public function test_un_code_complet_confirme_seulement_la_bonne_reference(): void
    {
        $expected = $this->part(['engine_code' => 'TEST-A']);
        $this->part(['engine_code' => 'TEST-B']);

        $this->getJson($this->publicCriteria(['engine_code' => ' test-a ']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expected->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed')
            ->assertJsonPath('data.0.compatibility_assessment.compatible', true);
    }

    public function test_le_garage_ne_transforme_pas_essence_seule_en_confirmation(): void
    {
        $part = $this->part(['engine_code' => 'TEST-A']);

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/parts/{$part->id}/garage-compatibility")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'candidate')
            ->assertJsonPath('data.0.compatible', false)
            ->assertJsonPath('data.0.compatibility_ready', false)
            ->assertJsonPath('data.0.missing_criteria.0', 'engine_code');
    }

    public function test_une_absence_de_declaration_dans_le_garage_reste_inconnue(): void
    {
        $part = $this->part(['engine_code' => 'TEST-A']);
        $this->vehicle->update(['engine_code' => 'TEST-B']);

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/parts/{$part->id}/garage-compatibility")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'unknown')
            ->assertJsonPath('data.0.compatible', false)
            ->assertJsonPath('data.0.compatibility_ready', false)
            ->assertJsonPath('data.0.reason', 'no_matching_fitment');
    }

    public function test_une_ligne_de_demonstration_ne_confirme_pas_une_compatibilite(): void
    {
        $part = $this->part(['source' => FitmentSource::Generated]);

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/parts/{$part->id}/garage-compatibility")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'candidate')
            ->assertJsonPath('data.0.compatible', false)
            ->assertJsonPath('data.0.reason', 'generated_fitment');
    }

    public function test_un_ancien_caractere_vin_ne_masque_plus_les_candidats_et_reste_stocke(): void
    {
        $this->vehicle->update(['engine_code' => 'K']);
        $this->part(['engine_code' => 'TEST-A']);

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/vehicles/{$this->vehicle->id}/compatible-parts?include_candidates=1")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate');

        $this->getJson("/api/my/vehicles/{$this->vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.engine_code', 'K')
            ->assertJsonPath('data.trim_id', null)
            ->assertJsonPath('data.engine_type_id', $this->petrol->id)
            ->assertJsonPath('data.compatibility_ready', false);
        $this->assertSame('K', $this->vehicle->fresh()->engine_code);
    }

    public function test_un_defaut_de_finition_ne_filtre_pas_une_configuration_non_renseignee(): void
    {
        $trim = Trim::create([
            'vehicle_model_id' => $this->model->id,
            'name' => 'Finition de test',
            'default_engine_type_id' => $this->petrol->id,
        ]);
        $diesel = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        $this->vehicle->update(['trim_id' => $trim->id, 'engine_type_id' => null]);
        $this->part(['engine_type_id' => $diesel->id]);

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/vehicles/{$this->vehicle->id}/compatible-parts?include_candidates=1")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate')
            ->assertJsonPath('data.0.compatibility_assessment.missing_criteria.0', 'engine_type_id');
    }

    public function test_le_parc_recoit_le_meme_niveau_de_preuve_que_le_catalogue(): void
    {
        $vehicle = Vehicle::factory()->create([
            'brand_id' => $this->model->brand_id,
            'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->petrol->id,
            'manufacturing_year' => 2019,
            'trim_id' => null, 'engine_code' => null,
        ]);
        $this->part(['engine_code' => 'TEST-A']);
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/vehicles/{$vehicle->id}/compatible-parts?include_candidates=1")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate');
    }

    public function test_la_recherche_rejette_les_tableaux_et_les_finitions_dun_autre_modele(): void
    {
        $other = VehicleModel::create(['brand_id' => $this->model->brand_id, 'name' => 'Autre modèle']);
        $trim = Trim::create(['vehicle_model_id' => $other->id, 'name' => 'Autre finition']);

        $this->getJson($this->publicCriteria(['trim_id' => $trim->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('trim_id');
        $this->getJson($this->publicCriteria(['engine_code' => ['TEST-A']]))
            ->assertUnprocessable()->assertJsonValidationErrors('engine_code');
        $this->getJson($this->publicCriteria(['vehicle_model_id' => [$this->model->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_model_id');
    }

    public function test_les_anciens_clients_ne_recoivent_que_des_ids_confirmes_et_une_pagination_exacte(): void
    {
        $this->part(['engine_code' => 'TEST-A']);
        $this->part(['source' => FitmentSource::Generated]);
        $confirmed = $this->part();
        $this->part();

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/vehicles/{$this->vehicle->id}/compatible-parts?per_page=1")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $confirmed->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_une_ancienne_contrainte_moteur_vide_est_interpretee_de_meme_dans_la_liste_et_le_verdict(): void
    {
        $part = $this->part(['engine_code' => '   ']);
        $this->getJson($this->publicCriteria())
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');

        $this->actingAsClient($this->owner)
            ->getJson("/api/my/parts/{$part->id}/garage-compatibility")
            ->assertOk()->assertJsonPath('data.0.status', 'confirmed');
    }

    public function test_la_recherche_inverse_oem_normalise_les_anciens_codes_sans_modifier_les_vehicules(): void
    {
        $vehicle = Vehicle::factory()->create([
            'brand_id' => $this->model->brand_id,
            'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->petrol->id,
            'manufacturing_year' => 2019,
            'engine_code' => ' test-a ',
        ]);
        $oem = OemNumber::create(['brand_id' => $this->model->brand_id, 'number' => 'TEST-OEM']);
        $oem->fitments()->create(['vehicle_model_id' => $this->model->id, 'engine_code' => 'TEST-A']);
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/oem/TEST-OEM/vehicles')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vehicle->id);
        $this->assertSame(' test-a ', $vehicle->fresh()->engine_code);
    }
}
