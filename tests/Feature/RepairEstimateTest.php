<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PrivateVehicleListing;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\RepairEstimates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepairEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Brand $brand;

    private VehicleModel $model;

    private EngineType $engine;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('private_sales.enabled', true);
        config(['equipment.documents_disk' => 'local', 'media.disk' => 'public']);
        Storage::fake('local');
        Storage::fake('public');
        $this->owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'estimate-owner']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota-estimate']);
        $this->model = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla-estimate']);
        $this->engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
    }

    private function privatePayload(array $extra = []): array
    {
        return array_replace([
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'year' => 2020, 'mileage_km' => 50000, 'price' => 3000000, 'city' => 'Ouagadougou',
            'description' => 'Description publique du véhicule particulier.', 'seller_name' => 'Vendeur',
            'phone' => '+22670000001', 'whatsapp_enabled' => false,
        ], $extra);
    }

    private function fault(array $extra = []): array
    {
        return array_replace([
            'category' => 'bodywork', 'title' => 'Pare-chocs arrière rayé',
            'description' => 'Reprise esthétique facultative.', 'severity' => 'minor', 'status' => 'declared',
        ], $extra);
    }

    private function estimate(array $extra = []): array
    {
        return array_replace([
            'cost_min' => 35000, 'cost_max' => 50000, 'source' => 'workshop',
            'estimated_at' => now()->toDateString(),
        ], $extra);
    }

    public function test_un_chiffrage_complet_est_rendu_et_un_ancien_client_le_conserve(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault([
                'repair_necessity' => 'necessary',
                'repair_estimate' => $this->estimate(),
            ])],
        ]))->assertCreated()
            ->assertJsonPath('data.fault_declaration.faults.0.repair_necessity', 'necessary')
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.cost_min', 35000)
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.cost_max', 50000)
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.currency', 'XOF')
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.source', 'workshop')
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.is_stale', false)
            ->assertJsonPath('data.price', 3000000)
            ->json('data.id');

        $faultId = PrivateVehicleListing::findOrFail($id)->faults->first()->id;
        $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload([
            'price' => 2800000,
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault([
                'id' => $faultId,
                'description' => 'Rayure un peu plus profonde qu’annoncé.',
            ])],
        ]))->assertOk()
            ->assertJsonPath('data.fault_declaration.faults.0.description', 'Rayure un peu plus profonde qu’annoncé.')
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.cost_min', 35000)
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.source', 'workshop')
            ->assertJsonPath('data.price', 2800000);
    }

    public function test_une_valeur_nulle_explicite_efface_le_chiffrage(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['repair_estimate' => $this->estimate()])],
        ]))->assertCreated()->json('data.id');
        $faultId = PrivateVehicleListing::findOrFail($id)->faults->first()->id;
        $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['id' => $faultId, 'repair_estimate' => null])],
        ]))->assertOk()->assertJsonPath('data.fault_declaration.faults.0.repair_estimate', null);
    }

    public function test_un_chiffrage_incomplet_une_fourchette_inversee_ou_une_date_future_sont_refuses(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload())->assertCreated()->json('data.id');
        foreach ([
            ['repair_estimate' => ['cost_min' => 35000]],
            ['repair_estimate' => $this->estimate(['cost_min' => 80000, 'cost_max' => 50000])],
            ['repair_estimate' => $this->estimate(['estimated_at' => '2099-01-01'])],
            ['repair_estimate' => $this->estimate(['source' => 'autoparc'])],
            ['repair_estimate' => $this->estimate(['cost_min' => -1])],
        ] as $invalid) {
            $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload([
                'fault_declaration_state' => 'has_faults',
                'faults' => [$this->fault($invalid)],
            ]))->assertUnprocessable();
            $this->assertDatabaseCount('private_listing_faults', 0);
        }
    }

    public function test_le_budget_public_n_additionne_que_les_travaux_necessaires_ouverts_et_visibles(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [
                $this->fault([
                    'title' => 'Freins à reprendre',
                    'repair_necessity' => 'necessary',
                    'repair_estimate' => $this->estimate(['cost_min' => 45000, 'cost_max' => 65000]),
                ]),
                $this->fault([
                    'title' => 'Bruit moteur',
                    'category' => 'engine',
                    'repair_necessity' => 'necessary',
                ]),
                $this->fault([
                    'title' => 'Peinture à rafraîchir',
                    'repair_necessity' => 'optional',
                    'repair_estimate' => $this->estimate(['cost_min' => 20000, 'cost_max' => 30000]),
                ]),
            ],
        ]))->assertCreated()
            ->assertJsonPath('data.fault_declaration.repair_budget.status', 'partial')
            ->assertJsonPath('data.fault_declaration.repair_budget.cost_min', 45000)
            ->assertJsonPath('data.fault_declaration.repair_budget.cost_max', 65000)
            ->assertJsonPath('data.fault_declaration.repair_budget.estimated_count', 1)
            ->assertJsonPath('data.fault_declaration.repair_budget.unestimated_count', 1)
            ->assertJsonPath('data.fault_declaration.repair_budget.optional.0.title', 'Peinture à rafraîchir')
            ->json('data.id');

        $listing = PrivateVehicleListing::findOrFail($id);
        $listing->refresh()->forceFill([
            'status' => 'published', 'published_at' => now(),
            'verified_phone' => '+22670000001', 'phone_verified_at' => now(),
        ])->save();

        $this->getJson('/api/private-listings/'.$id)->assertOk()
            ->assertJsonPath('data.price', 3000000)
            ->assertJsonPath('data.fault_declaration.repair_budget.status', 'partial')
            ->assertJsonPath('data.fault_declaration.repair_budget.cost_min', 45000)
            ->assertJsonPath('data.fault_declaration.faults.1.repair_estimate', null);

        $necessary = $listing->faults()->where('title', 'Freins à reprendre')->first();
        $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => $listing->faults->map(fn ($fault) => $this->fault([
                'id' => $fault->id,
                'title' => $fault->title,
                'category' => $fault->category->value,
                'status' => $fault->id === $necessary->id ? 'repaired' : $fault->status->value,
                'repair_necessity' => $fault->repair_necessity,
                'repair_estimate' => $fault->repair_cost_min === null ? null : [
                    'cost_min' => $fault->repair_cost_min,
                    'cost_max' => $fault->repair_cost_max,
                    'source' => $fault->repair_estimate_source,
                    'estimated_at' => $fault->repair_estimated_at->format('Y-m-d'),
                ],
            ]))->all(),
        ]))->assertOk()->assertJsonPath('data.fault_declaration.repair_budget.status', 'to_estimate')
            ->assertJsonPath('data.fault_declaration.repair_budget.cost_min', null);
    }

    public function test_une_estimation_de_plus_de_six_mois_est_marquee_ancienne(): void
    {
        $this->assertTrue(RepairEstimates::isStale('2026-05-14', CarbonImmutable::create(2026, 11, 14)));
        $this->assertFalse(RepairEstimates::isStale('2026-05-15', CarbonImmutable::create(2026, 11, 14)));

        $this->actingAsClient($this->owner);
        $this->postJson('/api/my/private-listings', $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['repair_estimate' => $this->estimate([
                'estimated_at' => now()->subMonthsNoOverflow(6)->toDateString(),
            ])])],
        ]))->assertCreated()->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.is_stale', true);
    }

    public function test_le_devis_prive_n_apparait_jamais_au_public_et_la_copie_masquee_peut_etre_publiee(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['repair_estimate' => $this->estimate()])],
        ]))->assertCreated()->json('data.id');
        $listing = PrivateVehicleListing::findOrFail($id);
        $faultId = $listing->faults->first()->id;

        $this->post('/api/my/private-listings/'.$id.'/faults/'.$faultId.'/repair-quote', [
            'document' => UploadedFile::fake()->create('atelier-confidentiel.pdf', 40, 'application/pdf'),
        ], ['Authorization' => 'Bearer jeton-de-test', 'Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.private_quote.original_name', 'atelier-confidentiel.pdf')
            ->assertJsonMissingPath('data.private_quote.path')
            ->assertJsonMissingPath('data.private_quote.disk');

        $listing->refresh()->forceFill([
            'status' => 'published', 'published_at' => now(),
            'verified_phone' => '+22670000001', 'phone_verified_at' => now(),
        ])->save();

        $public = $this->getJson('/api/private-listings/'.$id)->assertOk();
        $json = json_encode($public->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('atelier-confidentiel', $json);
        $this->assertStringNotContainsString('fault-repair-quotes/private', $json);
        $public->assertJsonPath('data.fault_declaration.faults.0.published_quote', null)
            ->assertJsonMissingPath('data.fault_declaration.faults.0.private_quote');

        $this->defaultHeaders = [];
        $this->getJson('/api/my/private-listings/'.$id.'/faults/'.$faultId.'/repair-quote')->assertUnauthorized();
        $this->actingAsClient($this->owner)
            ->get('/api/my/private-listings/'.$id.'/faults/'.$faultId.'/repair-quote')
            ->assertOk();

        // An old client that only updates the description must not drop the quote or the range.
        $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload([
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault([
                'id' => $faultId,
                'description' => 'Le devis de l’atelier a été déposé.',
            ])],
        ]))->assertOk()
            ->assertJsonPath('data.fault_declaration.faults.0.description', 'Le devis de l’atelier a été déposé.')
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.cost_min', 35000)
            ->assertJsonPath('data.fault_declaration.faults.0.private_quote.original_name', 'atelier-confidentiel.pdf');

        $listing->refresh()->forceFill([
            'status' => 'published', 'published_at' => now(),
            'verified_phone' => '+22670000001', 'phone_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($this->manager);
        $this->post('/api/private-listing-reviews/'.$id.'/faults/'.$faultId.'/repair-quote/publish', [
            'document' => UploadedFile::fake()->image('devis-masque.jpg', 400, 400),
        ])->assertCreated();

        Auth::forgetGuards();
        $this->defaultHeaders = [];
        $published = $this->getJson('/api/private-listings/'.$id)->assertOk()
            ->assertJsonPath('data.fault_declaration.faults.0.published_quote.mime_type', 'image/jpeg');
        $this->assertNotEmpty($published->json('data.fault_declaration.faults.0.published_quote.url'));
        $this->assertStringNotContainsString('atelier-confidentiel', json_encode($published->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_le_personnel_peut_attribuer_un_chiffrage_autoparc_sans_en_faire_un_controle(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->postJson('/api/vehicles', [
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->engine->id, 'manufacturing_year' => 2020,
            'registration_status' => 'registered', 'availability' => 'sale', 'sale_price' => 3000000,
            'fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault([
                'repair_necessity' => 'necessary',
                'repair_estimate' => $this->estimate(['source' => 'autoparc']),
            ])],
        ])->assertCreated()
            ->assertJsonPath('data.fault_declaration.faults.0.repair_estimate.source', 'autoparc')
            ->json('data.id');

        $created = $this->getJson('/api/vehicles/'.$id)->assertOk();
        $this->assertEquals(3000000, (float) $created->json('data.commercial.sale_price'));
        $declaration = $created->json('data.fault_declaration');
        $this->assertSame('autoparc', $declaration['faults'][0]['repair_estimate']['source']);
        $this->assertArrayNotHasKey('path', $declaration['faults'][0]['private_quote'] ?? []);
        $this->assertStringNotContainsString('estimated_repair_cost', json_encode($declaration));
    }
}
