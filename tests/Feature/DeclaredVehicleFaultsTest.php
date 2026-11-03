<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\PrivateVehicleListing;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\StaffVehicleInspections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeclaredVehicleFaultsTest extends TestCase
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
        $this->owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'fault-owner']);
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->model = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla']);
        $this->engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
    }

    private function privatePayload(array $extra = []): array
    {
        return array_replace(['brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'year' => 2020, 'mileage_km' => 50000, 'price' => 3000000, 'city' => 'Ouagadougou',
            'description' => 'Description publique du véhicule particulier.', 'seller_name' => 'PRIVATE-SELLER',
            'phone' => '+22670000001', 'whatsapp_enabled' => false], $extra);
    }

    private function stockPayload(array $extra = []): array
    {
        return array_replace(['brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->engine->id, 'manufacturing_year' => 2020,
            'registration_status' => 'registered', 'availability' => 'sale', 'sale_price' => 3000000], $extra);
    }

    private function fault(array $extra = []): array
    {
        return array_replace(['category' => 'engine', 'title' => 'Fuite à examiner', 'description' => 'Constat du vendeur.',
            'severity' => 'moderate', 'status' => 'declared', 'affects_drivability' => false,
            'is_safety_critical' => false], $extra);
    }

    private function privateListing(array $extra = []): PrivateVehicleListing
    {
        return PrivateVehicleListing::create($this->privatePayload() + array_replace([
            'title' => 'Toyota Corolla', 'user_id' => $this->owner->id, 'active_user_id' => $this->owner->id,
            'status' => 'published', 'published_at' => now(), 'verified_phone' => '+22670000001', 'phone_verified_at' => now(),
        ], $extra));
    }

    private function stock(array $extra = []): Vehicle
    {
        return Vehicle::create(array_replace($this->stockPayload(), ['reference' => 'FAULT-'.(Vehicle::count() + 1),
            'condition' => 'used', 'status' => 'in_stock'], $extra));
    }

    private function inspect(Vehicle|PrivateVehicleListing $target)
    {
        return app(StaffVehicleInspections::class)->create($target instanceof Vehicle ? 'vehicle' : 'private_listing', $target->id,
            ['inspected_at' => now()->subHour(), 'mileage_km' => 50000, 'summary' => 'Contrôle consigné.',
                'items' => [['label' => 'Moteur', 'status' => 'ok']]], $this->manager);
    }

    private function partner(): User
    {
        $user = User::factory()->create(['role' => 'partner']);
        PartnerAccount::create(['user_id' => $user->id, 'company_name' => 'Garage', 'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($user->id), 'status' => 'approved']);

        return $user;
    }

    public function test_private_draft_can_be_unknown_but_submission_and_approval_require_consistent_explicit_declaration(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload())->assertCreated()
            ->assertJsonPath('data.fault_declaration.state', 'unknown')->assertJsonPath('data.fault_declaration.declared_at', null)->json('data.id');
        $listing = PrivateVehicleListing::findOrFail($id);
        $listing->photos()->create(['disk' => 'public', 'path' => 'private-listings/photo.jpg']);
        $submit = ['owner_confirmed' => true, 'contact_consent' => true];
        $this->postJson('/api/my/private-listings/'.$id.'/submit', $submit)->assertUnprocessable()->assertJsonValidationErrors('fault_declaration_state');
        $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload(['fault_declaration_state' => 'none', 'faults' => []]))
            ->assertOk()->assertJsonPath('data.fault_declaration.state', 'none');
        $this->postJson('/api/my/private-listings/'.$id.'/submit', $submit)->assertOk();
        DB::table('private_vehicle_listings')->where('id', $id)->update(['fault_declaration_state' => 'unknown']);
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/private-listing-reviews/'.$id.'/approve', ['revision' => $listing->fresh()->revision, 'phone_verified' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('fault_declaration_state');
        DB::table('private_vehicle_listings')->where('id', $id)->update(['fault_declaration_state' => 'none']);
        $this->postJson('/api/private-listing-reviews/'.$id.'/approve', ['revision' => $listing->fresh()->revision, 'phone_verified' => true])->assertOk();
    }

    public function test_private_faults_persist_round_trip_repaired_history_and_omitted_updates_preserve_rows(): void
    {
        $this->actingAsClient($this->owner);
        $id = $this->postJson('/api/my/private-listings', $this->privatePayload(['fault_declaration_state' => 'has_faults', 'faults' => [$this->fault()]]))
            ->assertCreated()->assertJsonPath('data.fault_declaration.faults.0.editable', true)
            ->assertJsonPath('data.fault_declaration.unresolved_count', 1)->json('data.id');
        $faultId = PrivateVehicleListing::findOrFail($id)->faults->first()->id;
        $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload(['price' => 3500000]))->assertOk()
            ->assertJsonPath('data.fault_declaration.faults.0.id', $faultId)->assertJsonPath('data.fault_declaration.state', 'has_faults');
        foreach (['wont_fix' => 1, 'repaired' => 0] as $status => $unresolved) {
            $this->putJson('/api/my/private-listings/'.$id, $this->privatePayload(['fault_declaration_state' => 'has_faults',
                'faults' => [$this->fault(['id' => $faultId, 'status' => $status])]]))->assertOk()
                ->assertJsonPath('data.fault_declaration.faults.0.id', $faultId)
                ->assertJsonPath('data.fault_declaration.faults.0.status', $status)->assertJsonPath('data.fault_declaration.unresolved_count', $unresolved);
        }
        $this->assertDatabaseCount('private_listing_faults', 1);
    }

    public function test_private_input_rejects_inconsistent_states_unauthorized_ids_and_internal_fields_atomically(): void
    {
        $listing = $this->privateListing();
        $this->actingAsClient($this->owner);
        foreach ([['fault_declaration_state' => 'none', 'faults' => [$this->fault()]],
            ['fault_declaration_state' => 'has_faults', 'faults' => []], ['fault_declaration_state' => 'unknown', 'faults' => [$this->fault()]],
            ['faults' => ['engine' => $this->fault()]], ['faults' => [$this->fault(['status' => 'diagnosed'])]],
            ['faults' => [$this->fault(['estimated_repair_cost' => 1])]], ['faults' => [$this->fault(['disclosed_to_buyer' => false])]],
            ['faults' => [$this->fault(['id' => 9999])]], ['fault_declared_at' => now()->toIso8601String()]] as $invalid) {
            $this->putJson('/api/my/private-listings/'.$listing->id, $this->privatePayload($invalid))->assertUnprocessable();
            $this->assertSame('published', $listing->fresh()->status);
            $this->assertDatabaseCount('private_listing_faults', 0);
        }
        $other = User::factory()->create(['role' => 'client']);
        $this->actingAsClient($other)->putJson('/api/my/private-listings/'.$listing->id,
            $this->privatePayload(['fault_declaration_state' => 'none', 'faults' => []]))->assertNotFound();
    }

    public function test_private_fault_edit_resets_review_and_withdraws_inspection_permanently(): void
    {
        $listing = $this->privateListing();
        $inspection = $this->inspect($listing);
        $revision = $listing->revision;
        $this->actingAsClient($this->owner)->putJson('/api/my/private-listings/'.$listing->id, $this->privatePayload([
            'fault_declaration_state' => 'has_faults', 'faults' => [$this->fault()],
        ]))->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.inspection', null);
        $this->assertGreaterThan($revision, $listing->fresh()->revision);
        $this->assertSame('withdrawn', $inspection->fresh()->status);
        $faultId = $listing->fresh()->faults->first()->id;
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->privatePayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['id' => $faultId, 'status' => 'repaired'])]]))->assertOk()->assertJsonPath('data.inspection', null);
        $this->assertSame('withdrawn', $inspection->fresh()->status);
    }

    public function test_stock_mutations_require_manage_faults_and_staff_get_full_workflow_only_on_management_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales']));
        $this->postJson('/api/vehicles', $this->stockPayload(['fault_declaration_state' => 'unknown']))->assertCreated();
        $this->postJson('/api/vehicles', $this->stockPayload(['fault_declaration_state' => 'has_faults', 'faults' => [$this->fault()]]))->assertForbidden();
        $this->assertDatabaseCount('vehicles', 1);
        Sanctum::actingAs($this->manager);
        $id = $this->postJson('/api/vehicles', $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['status' => 'diagnosed'])]]))->assertCreated()
            ->assertJsonPath('data.fault_declaration.faults.0.status', 'diagnosed')->json('data.id');
        $fault = Vehicle::findOrFail($id)->faults->first();
        $this->putJson('/api/vehicles/'.$id, $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['id' => $fault->id, 'status' => 'repaired'])]]))->assertOk()->assertJsonPath('data.fault_declaration.unresolved_count', 0);
        Sanctum::actingAs(User::factory()->create(['role' => 'sales']));
        $this->putJson('/api/vehicles/'.$id, $this->stockPayload())->assertOk();
        $this->putJson('/api/vehicles/'.$id, $this->stockPayload(['fault_declaration_state' => 'none', 'faults' => []]))->assertForbidden();
    }

    public function test_partner_sync_preserves_internal_faults_and_cannot_mutate_another_vehicle_or_staff_diagnosis(): void
    {
        $partner = $this->partner();
        Sanctum::actingAs($partner);
        $id = $this->postJson('/api/partner/vehicles', $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault()]]))->assertCreated()->assertJsonPath('data.fault_declaration.faults.0.editable', true)->json('data.id');
        $vehicle = Vehicle::findOrFail($id);
        $owned = $vehicle->faults->first();
        $internal = $vehicle->faults()->create($this->fault(['title' => 'Diagnostic atelier', 'status' => 'diagnosed']));
        $this->getJson('/api/partner/vehicles/'.$id)->assertOk()->assertJsonPath('data.fault_declaration.faults.1.editable', false);
        $this->putJson('/api/partner/vehicles/'.$id, $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['id' => $owned->id, 'status' => 'repaired'])]]))->assertOk()->assertJsonCount(2, 'data.fault_declaration.faults');
        $this->assertSame('diagnosed', $internal->fresh()->status->value);
        foreach ([['fault_declaration_state' => 'none', 'faults' => []],
            ['fault_declaration_state' => 'has_faults', 'faults' => [$this->fault(['id' => $internal->id])]],
            ['faults' => [$this->fault(['status' => 'diagnosed'])]], ['faults' => [$this->fault(['assigned_to' => $partner->id])]]] as $invalid) {
            $this->putJson('/api/partner/vehicles/'.$id, $this->stockPayload($invalid))->assertUnprocessable();
        }
        $foreign = $this->stock()->faults()->create($this->fault());
        $this->putJson('/api/partner/vehicles/'.$id, $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['id' => $foreign->id])]]))->assertUnprocessable();
    }

    public function test_public_stock_faults_are_allowlisted_and_hidden_faults_never_leak_even_to_authenticated_staff(): void
    {
        $vehicle = $this->stock();
        $visible = $vehicle->faults()->create($this->fault(['title' => 'Défaut public']) + ['estimated_repair_cost' => 123456]);
        $vehicle->faults()->create($this->fault(['title' => 'HIDDEN-DIAGNOSIS', 'disclosed_to_buyer' => false]) + ['actual_repair_cost' => 654321]);
        Sanctum::actingAs($this->manager);
        foreach (['/api/catalog/vehicles/'.$vehicle->id, '/api/catalog/vehicles', '/api/catalog/vehicle-listings'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('Défaut public', $json);
            foreach (['HIDDEN-DIAGNOSIS', '123456', '654321', 'estimated_repair_cost', 'actual_repair_cost', 'required_parts', 'editable'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
        $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk()->assertJsonCount(2, 'data.faults')
            ->assertJsonPath('data.faults.0.estimated_repair_cost', '123456.00');
        $this->getJson('/api/catalog/vehicle-listings?fault_category=braking')->assertOk()->assertJsonCount(0, 'data');
        $visible->update(['disclosed_to_buyer' => false]);
        $this->getJson('/api/catalog/vehicle-listings?fault_category=engine')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.fault_declaration.state', 'has_faults');
    }

    public function test_without_faults_uses_known_declarations_and_counts_wont_fix_as_unresolved_for_both_sources(): void
    {
        $this->stock(['registration_status' => 'unregistered']); // legacy import: no rows is not "none"
        $known = $this->stock();
        $known->forceFill(['fault_declaration_state' => 'none', 'fault_declared_at' => now()])->save();
        $unresolved = $this->stock();
        $unresolved->faults()->create($this->fault(['status' => 'wont_fix']));
        $listing = $this->privateListing(['fault_declaration_state' => 'has_faults']);
        $privateFault = $listing->faults()->create($this->fault(['status' => 'wont_fix']));
        $this->getJson('/api/catalog/vehicle-listings?without_faults=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_id', $known->id);
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()->assertJsonPath('data.fault_declaration.unresolved_count', 1)
            ->assertJsonPath('data.fault_declaration.faults.0.is_open', false)->assertJsonMissingPath('data.fault_declaration.faults.0.editable');
        $privateFault->update(['status' => 'repaired']);
        $this->getJson('/api/catalog/vehicle-listings?without_faults=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/catalog/vehicle-listings?fault_category=engine')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_fault_declarations_are_loaded_in_batches_and_raw_changes_invalidate_inspection_hash(): void
    {
        $vehicle = $this->stock();
        $fault = $vehicle->faults()->create($this->fault());
        $inspection = $this->inspect($vehicle);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspection->id)->assertOk();
        DB::table('vehicle_faults')->where('id', $fault->id)->update(['severity' => 'critical']);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspection->id)->assertNotFound();
        foreach (range(1, 5) as $unused) {
            $this->stock()->faults()->create($this->fault());
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/catalog/vehicles')->assertOk();
        $this->assertCount(1, collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'select * from "vehicle_faults"')));
        DB::disableQueryLog();
    }

    public function test_staff_takeover_makes_partner_fault_readonly_and_price_update_preserves_hidden_diagnosis(): void
    {
        $partner = $this->partner();
        Sanctum::actingAs($partner);
        $id = $this->postJson('/api/partner/vehicles', $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault()]]))->assertCreated()->json('data.id');
        $fault = Vehicle::findOrFail($id)->faults->first();
        Sanctum::actingAs($this->manager);
        $this->putJson('/api/vehicles/'.$id.'/faults/'.$fault->id, $this->fault([
            'status' => 'diagnosed', 'estimated_repair_cost' => 300000, 'disclosed_to_buyer' => false,
        ]))->assertOk();
        Sanctum::actingAs($partner);
        $this->getJson('/api/partner/vehicles/'.$id)->assertOk()->assertJsonPath('data.fault_declaration.faults.0.editable', false);
        $this->putJson('/api/partner/vehicles/'.$id, $this->stockPayload(['sale_price' => 4000000]))->assertOk();
        $this->assertFalse($fault->fresh()->disclosed_to_buyer);
        $this->assertSame('diagnosed', $fault->fresh()->status->value);
        $this->assertSame('300000.00', $fault->fresh()->estimated_repair_cost);
        $this->putJson('/api/partner/vehicles/'.$id, $this->stockPayload(['fault_declaration_state' => 'has_faults',
            'faults' => [$this->fault(['id' => $fault->id])]]))->assertUnprocessable();
        // Returning to a client-valid status does not hand a staff-managed workflow back implicitly.
        $fault->update(['status' => 'repaired', 'disclosed_to_buyer' => true, 'estimated_repair_cost' => null]);
        $this->getJson('/api/partner/vehicles/'.$id)->assertOk()->assertJsonPath('data.fault_declaration.faults.0.editable', false);
    }

    public function test_staff_full_form_preserves_hidden_flags_when_explicit_or_omitted_and_partner_never_sees_internal_costs(): void
    {
        $partner = $this->partner();
        $vehicle = $this->stock(['owner_partner_account_id' => $partner->partnerAccount->id]);
        $fault = $vehicle->faults()->create($this->fault(['status' => 'diagnosed', 'disclosed_to_buyer' => false])
            + ['estimated_repair_cost' => 765432]);
        Sanctum::actingAs($this->manager);
        foreach ([['disclosed_to_buyer' => false], []] as $disclosure) {
            $row = ['id' => $fault->id, 'category' => 'engine', 'title' => 'Diagnostic révisé'] + $disclosure;
            $this->putJson('/api/vehicles/'.$vehicle->id, $this->stockPayload([
                'fault_declaration_state' => 'has_faults', 'faults' => [$row],
            ]))->assertOk()->assertJsonPath('data.fault_declaration.faults.0.disclosed_to_buyer', false)
                ->assertJsonPath('data.fault_declaration.faults.0.status', 'diagnosed');
            $this->assertFalse($fault->fresh()->disclosed_to_buyer);
        }
        Sanctum::actingAs($partner);
        $response = $this->getJson('/api/partner/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.fault_declaration.faults.0.editable', false);
        foreach (['estimated_repair_cost', 'actual_repair_cost', 'required_parts', '765432'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_staff_fault_detection_date_and_mileage_changes_invalidate_inspections_without_public_evidence_leaks(): void
    {
        $vehicle = $this->stock();
        $fault = $vehicle->faults()->create($this->fault());
        Sanctum::actingAs($this->manager);
        foreach (['detected_at' => '2025-01-01', 'mileage_at_detection_km' => 49000] as $field => $value) {
            $inspection = $this->inspect($vehicle);
            $this->putJson('/api/vehicles/'.$vehicle->id.'/faults/'.$fault->id, $this->fault([$field => $value]))->assertOk();
            $this->assertSame('withdrawn', $inspection->fresh()->status);
            $this->getJson('/api/catalog/vehicle-inspections/'.$inspection->id)->assertNotFound();
            $inspection = $this->inspect($vehicle);
            DB::table('vehicle_faults')->where('id', $fault->id)->update([$field => $field === 'detected_at' ? '2025-02-01' : 51000]);
            $this->getJson('/api/catalog/vehicle-inspections/'.$inspection->id)->assertNotFound();
        }
        $response = $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk();
        foreach (['detected_at', 'mileage_at_detection_km'] as $field) {
            $response->assertJsonMissingPath('data.fault_declaration.faults.0.'.$field)->assertJsonMissingPath('data.faults.0.'.$field);
        }
    }
}
