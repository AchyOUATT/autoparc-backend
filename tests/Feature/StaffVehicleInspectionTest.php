<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\PrivateVehicleListing;
use App\Models\StaffVehicleInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\StaffVehicleInspections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffVehicleInspectionTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;
    private VehicleModel $model;
    private EngineType $engine;
    private User $mechanic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
        config()->set('private_sales.enabled', true);
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->model = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla']);
        $this->engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->mechanic = User::factory()->create(['name' => 'INSPECTOR-PRIVATE-NAME', 'role' => 'mechanic']);
    }

    private function vehicle(array $extra = []): Vehicle
    {
        return Vehicle::create(array_replace([
            'reference' => 'INSPECT-'.(Vehicle::withTrashed()->count() + 1),
            'registration_status' => 'registered', 'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id, 'engine_type_id' => $this->engine->id,
            'manufacturing_year' => 2020, 'condition' => 'used', 'status' => 'in_stock',
            'availability' => 'sale', 'sale_price' => 2_000_000, 'published_at' => now(),
        ], $extra));
    }

    private function listing(array $extra = [], ?User $seller = null): PrivateVehicleListing
    {
        $seller ??= User::factory()->create(['role' => 'client']);

        return PrivateVehicleListing::create(array_replace([
            'user_id' => $seller->id, 'active_user_id' => $seller->id, 'status' => 'published',
            'published_at' => now(), 'title' => 'Toyota Corolla', 'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id, 'engine_type_id' => $this->engine->id,
            'vehicle_identity' => ['brand' => 'Toyota', 'model' => 'Corolla'],
            'year' => 2020, 'mileage_km' => 50000, 'price' => 3_000_000,
            'city' => 'Ouagadougou', 'description' => 'Voiture de particulier.',
            'seller_name' => 'PRIVATE-SELLER', 'phone' => '+22670000001', 'verified_phone' => '+22670000001',
        ], $extra));
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'inspected_at' => now()->subHour()->toIso8601String(), 'mileage_km' => 50100,
            'summary' => 'Constat limité aux points contrôlés.', 'confirmed' => true,
            'items' => [['label' => 'Freinage', 'status' => 'ok'], ['label' => 'Climatisation', 'status' => 'not_checked']],
        ], $extra);
    }

    private function inspect(Vehicle|PrivateVehicleListing $target, array $extra = []): StaffVehicleInspection
    {
        return app(StaffVehicleInspections::class)->create(
            $target instanceof Vehicle ? 'vehicle' : 'private_listing', $target->id, $this->payload($extra), $this->mechanic,
        );
    }

    private function url(Vehicle|PrivateVehicleListing $target): string
    {
        return '/api/vehicle-inspections/'.($target instanceof Vehicle ? 'vehicle' : 'private_listing').'/'.$target->id;
    }

    private function publicUrl(StaffVehicleInspection $inspection): string
    {
        return '/api/catalog/vehicle-inspections/'.$inspection->id;
    }

    public function test_only_admin_manager_and_mechanic_can_create_and_withdraw_but_all_staff_can_read(): void
    {
        $vehicle = $this->vehicle();
        $this->getJson($this->url($vehicle))->assertUnauthorized();
        $this->postJson($this->url($vehicle), $this->payload())->assertUnauthorized();

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($user);
            $this->getJson($this->url($vehicle))->assertStatus($role->isStaff() ? 200 : 403);
            $response = $this->postJson($this->url($vehicle), $this->payload());
            $allowed = in_array($role, [UserRole::Admin, UserRole::Manager, UserRole::Mechanic], true);
            $response->assertStatus($allowed ? 201 : 403);
            $inspection = $this->inspect($vehicle);
            $this->postJson('/api/vehicle-inspections/'.$inspection->id.'/withdraw', ['reason' => 'Retrait après vérification.'])
                ->assertStatus($allowed ? 200 : 403);
        }
    }

    public function test_result_and_counts_come_from_checked_items_and_confirmation_is_required(): void
    {
        $vehicle = $this->vehicle();
        Sanctum::actingAs($this->mechanic);
        foreach (['ok' => 'clear', 'attention' => 'attention', 'blocking' => 'blocked'] as $status => $result) {
            $response = $this->postJson($this->url($vehicle), $this->payload(['items' => [
                ['label' => 'Moteur', 'status' => $status, 'note' => 'Constat consigné'],
                ['label' => 'Habitacle', 'status' => 'not_checked'],
            ]]))->assertCreated()->assertJsonPath('data.result', $result)->assertJsonPath('data.checked_count', 1)
                ->assertJsonPath('data.attention_count', $status === 'attention' ? 1 : 0)
                ->assertJsonPath('data.blocking_count', $status === 'blocking' ? 1 : 0)
                ->assertJsonPath('data.state', 'active')->assertJsonPath('data.actor.id', $this->mechanic->id)
                ->assertJsonPath('data.valid_until', null);
            $this->getJson('/api/catalog/vehicle-inspections/'.$response->json('data.id'))->assertOk()
                ->assertJsonPath('data.items.0.status', $status);
        }
        foreach ([['confirmed' => false], ['items' => [['label' => 'Moteur', 'status' => 'not_checked']]],
            ['items' => ['engine' => ['label' => 'Moteur', 'status' => 'ok']]],
            ['items' => []], ['inspected_at' => now()->addDay()->toIso8601String()],
            ['valid_until' => now()->subDay()->toIso8601String()]] as $invalid) {
            $this->postJson($this->url($vehicle), $this->payload($invalid))->assertUnprocessable();
        }
    }

    public function test_public_reports_do_not_leak_staff_or_private_seller_information_even_in_staff_session(): void
    {
        $listing = $this->listing();
        $inspection = $this->inspect($listing, ['items' => [['label' => 'Pneus', 'status' => 'attention', 'note' => 'Usure à surveiller.']]]);
        Sanctum::actingAs($this->mechanic);
        $response = $this->getJson($this->publicUrl($inspection))->assertOk()
            ->assertJsonPath('data.source', 'private_listing')->assertJsonPath('data.source_id', $listing->id)
            ->assertJsonPath('data.items.0.note', 'Usure à surveiller.');
        foreach (['actor', 'actor_id', 'state', 'status', 'withdrawal_reason', 'withdrawn_by', 'technical_fingerprint',
            'seller_name', 'phone', 'verified_phone', 'user_id'] as $field) {
            $response->assertJsonMissingPath('data.'.$field);
        }
        $this->assertStringNotContainsString('INSPECTOR-PRIVATE-NAME', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-SELLER', $response->getContent());
        $this->assertStringNotContainsString('+22670000001', $response->getContent());
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()->assertJsonPath('data.contact_via', 'autoparc')
            ->assertJsonPath('data.inspection.id', $inspection->id)->assertJsonMissingPath('data.phone');
    }

    public function test_same_numeric_source_ids_have_independent_active_inspections_on_all_catalogue_paths(): void
    {
        $vehicle = $this->vehicle();
        $listing = $this->listing();
        $this->assertSame($vehicle->id, $listing->id);
        $stock = $this->inspect($vehicle);
        $private = $this->inspect($listing);
        $this->assertNotSame($stock->active_key, $private->active_key);
        $this->getJson('/api/catalog/vehicles')->assertOk()->assertJsonPath('data.0.inspection.id', $stock->id);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection.id', $stock->id);
        $this->getJson('/api/private-listings')->assertOk()->assertJsonPath('data.0.inspection.id', $private->id);
        $items = $this->getJson('/api/catalog/vehicle-listings')->assertOk()->json('data');
        $this->assertSame($stock->id, collect($items)->firstWhere('source', 'vehicle')['vehicle']['inspection']['id']);
        $this->assertSame($private->id, collect($items)->firstWhere('source', 'private_listing')['private_listing']['inspection']['id']);
    }

    public function test_new_report_supersedes_previous_and_withdrawal_preserves_history_and_requires_reason(): void
    {
        $vehicle = $this->vehicle();
        $old = $this->inspect($vehicle);
        $current = $this->inspect($vehicle);
        $this->assertSame('superseded', $old->refresh()->status);
        $this->assertNull($old->active_key);
        $this->getJson($this->publicUrl($old))->assertNotFound();
        Sanctum::actingAs($this->mechanic);
        $this->postJson('/api/vehicle-inspections/'.$current->id.'/withdraw', [])->assertUnprocessable();
        $this->postJson('/api/vehicle-inspections/'.$current->id.'/withdraw', ['reason' => 'Contrôle à reprendre.'])
            ->assertOk()->assertJsonPath('data.state', 'withdrawn')->assertJsonPath('data.withdrawal_reason', 'Contrôle à reprendre.')
            ->assertJsonPath('data.withdrawn_by.id', $this->mechanic->id);
        $this->getJson($this->publicUrl($current))->assertNotFound();
        $this->getJson($this->url($vehicle).'?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.state', 'withdrawn')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection', null);
        $this->assertSame(2, StaffVehicleInspection::count());
        $this->postJson('/api/vehicle-inspections/'.$current->id.'/withdraw', ['reason' => 'Encore'])->assertConflict();
    }

    public function test_expiry_and_fingerprint_mismatch_hide_report_and_badge_without_assumed_validity_period(): void
    {
        $vehicle = $this->vehicle();
        $inspection = $this->inspect($vehicle, ['valid_until' => now()->addHour()]);
        $this->travel(2)->hours();
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection', null);
        Sanctum::actingAs($this->mechanic);
        $this->getJson($this->url($vehicle))->assertOk()->assertJsonPath('data.0.state', 'outdated');
        $inspection = $this->inspect($vehicle);
        $this->travel(3)->years();
        $this->getJson($this->publicUrl($inspection))->assertOk();
        // Out-of-band imports bypass observers; the fingerprint still fails closed.
        DB::table('vehicles')->where('id', $vehicle->id)->update(['manufacturing_year' => 2019]);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.inspection', null);
        $this->getJson($this->url($vehicle))->assertOk()->assertJsonPath('data.0.state', 'outdated');
    }

    public function test_public_report_requires_current_source_visibility_and_enabled_private_module(): void
    {
        $seller = User::factory()->create(['role' => 'client']);
        $listing = $this->listing(seller: $seller);
        $inspection = $this->inspect($listing);
        foreach ([['status' => 'draft'], ['status' => 'withdrawn'], ['status' => 'sold'], ['published_at' => null]] as $hidden) {
            $listing->update($hidden);
            $this->getJson($this->publicUrl($inspection))->assertNotFound();
            $listing->update(['status' => 'published', 'published_at' => now()]);
        }
        $seller->update(['is_active' => false]);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $seller->update(['is_active' => true]);
        config()->set('private_sales.enabled', false);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        Sanctum::actingAs($this->mechanic);
        $this->getJson($this->url($listing))->assertNotFound();
        $this->postJson($this->url($listing), $this->payload())->assertNotFound();
    }

    public function test_partner_draft_and_inactive_partner_hide_public_report_but_partner_cannot_issue_inspections(): void
    {
        $owner = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create(['user_id' => $owner->id, 'company_name' => 'Garage', 'contact_name' => 'Gérant',
            'phone' => $this->numeroDePartenaire($owner->id), 'status' => 'approved']);
        $vehicle = $this->vehicle(['owner_partner_account_id' => $account->id, 'partner_published_at' => now()]);
        $inspection = $this->inspect($vehicle);
        $this->getJson($this->publicUrl($inspection))->assertOk();
        $vehicle->update(['partner_published_at' => null]);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $vehicle->update(['partner_published_at' => now()]);
        $owner->update(['is_active' => false]);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $owner->update(['is_active' => true]);
        Sanctum::actingAs($owner);
        $this->getJson('/api/partner/vehicles')->assertOk()->assertJsonPath('data.0.inspection.id', $inspection->id);
        $this->postJson($this->url($vehicle), $this->payload())->assertForbidden();
    }

    public function test_technical_changes_and_new_faults_withdraw_but_commercial_changes_keep_inspection(): void
    {
        $vehicle = $this->vehicle();
        $inspection = $this->inspect($vehicle);
        $vehicle->update(['sale_price' => 2500000, 'description' => 'Texte corrigé.']);
        $vehicle->media()->create(['disk' => 'public', 'path' => 'vehicles/photo.jpg']);
        $this->getJson($this->publicUrl($inspection))->assertOk();
        $vehicle->update(['manufacturing_year' => 2021]);
        $this->assertSame('withdrawn', $inspection->refresh()->status);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $inspection = $this->inspect($vehicle);
        $fault = $vehicle->faults()->create(['category' => 'braking', 'title' => 'Freinage défaillant', 'status' => 'declared']);
        $this->assertSame('withdrawn', $inspection->refresh()->status);
        $fault->update(['status' => 'repaired']);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $inspection = $this->inspect($vehicle);
        $vehicle->registrationDetail()->create(['mileage_km' => 60000]);
        $this->assertSame('withdrawn', $inspection->refresh()->status);
    }

    public function test_private_price_description_photo_and_revision_are_not_technical_but_mileage_is(): void
    {
        $listing = $this->listing();
        $inspection = $this->inspect($listing);
        $listing->update(['price' => 3500000, 'description' => 'Description corrigée.', 'revision' => 7]);
        $listing->photos()->create(['disk' => 'public', 'path' => 'private-listings/photo.jpg']);
        $this->getJson($this->publicUrl($inspection))->assertOk();
        $listing->update(['mileage_km' => 60000]);
        $this->assertSame('withdrawn', $inspection->refresh()->status);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
    }

    public function test_reports_are_not_spoofable_from_payload_or_automatically_created_by_moderation(): void
    {
        $vehicle = $this->vehicle();
        $listing = $this->listing();
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()->assertJsonPath('data.inspection', null);
        Sanctum::actingAs($this->mechanic);
        foreach (['actor_id' => $this->mechanic->id, 'result' => 'clear', 'source' => 'private_listing',
            'source_id' => $listing->id, 'checked_count' => 100, 'active_key' => 'vehicle:999', 'state' => 'active'] as $field => $value) {
            $this->postJson($this->url($vehicle), $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, StaffVehicleInspection::count());
        $this->putJson($this->url($vehicle), $this->payload())->assertStatus(405);
    }

    public function test_public_share_page_reflects_inspection_and_never_claims_guaranteed_condition(): void
    {
        $listing = $this->listing();
        $this->get('/annonces/'.$listing->id)->assertOk()->assertSee('Le véhicule n’a pas été inspecté par AutoParc.');
        $inspection = $this->inspect($listing, ['items' => [['label' => 'Freinage', 'status' => 'blocking']]]);
        $this->get('/annonces/'.$listing->id)->assertOk()->assertSee('Inspecté par AutoParc')->assertSee('1 anomalie(s) bloquante(s)')
            ->assertSee('ne constitue pas une garantie')->assertDontSee('Le véhicule n’a pas été inspecté')->assertDontSee('PRIVATE-SELLER');
        app(StaffVehicleInspections::class)->withdraw($inspection, 'Retrait');
        $this->get('/annonces/'.$listing->id)->assertOk()->assertSee('Le véhicule n’a pas été inspecté par AutoParc.');
    }

    public function test_inspection_reads_are_batched_for_stock_and_private_cards(): void
    {
        foreach (range(1, 6) as $unused) {
            $this->inspect($this->vehicle());
            $this->inspect($this->listing());
        }
        DB::enableQueryLog();
        foreach (['/api/catalog/vehicles' => 1, '/api/private-listings' => 1, '/api/catalog/vehicle-listings' => 2] as $path => $expected) {
            DB::flushQueryLog();
            $this->getJson($path)->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'staff_vehicle_inspections'));
            $this->assertCount($expected, $queries, $path.' must load inspections in batches, not per card.');
        }
        DB::disableQueryLog();
    }

    public function test_account_deletion_and_force_deletion_preserve_reports_without_blocking_existing_flows(): void
    {
        $vehicle = $this->vehicle();
        $stock = $this->inspect($vehicle);
        $vehicle->forceDelete();
        $stock->refresh();
        $this->assertNull($stock->vehicle_id);
        $this->assertSame('vehicle', $stock->source());
        $this->getJson($this->publicUrl($stock))->assertNotFound();

        $seller = User::factory()->create(['role' => 'client', 'firebase_uid' => 'seller-to-delete']);
        $listing = $this->listing(seller: $seller);
        $private = $this->inspect($listing);
        $this->actingAsClient($seller)->deleteJson('/api/my/account')->assertOk();
        $private->refresh();
        $this->assertNull($private->private_vehicle_listing_id);
        $this->assertSame($listing->id, $private->sourceId());
        $this->assertDatabaseMissing('private_vehicle_listings', ['id' => $listing->id]);
        $this->assertSame(2, StaffVehicleInspection::count());
        $this->getJson($this->publicUrl($private))->assertNotFound();
    }

    public function test_report_content_cannot_be_edited_in_place(): void
    {
        $inspection = $this->inspect($this->vehicle());
        $this->expectException(\LogicException::class);
        $inspection->update(['summary' => 'Changed after signature.']);
    }

    public function test_out_of_band_mileage_change_is_detected_without_per_card_detail_queries(): void
    {
        $vehicle = $this->vehicle();
        $detail = $vehicle->registrationDetail()->create(['mileage_km' => 50000]);
        $inspection = $this->inspect($vehicle);
        $this->getJson($this->publicUrl($inspection))->assertOk();
        DB::table('vehicle_registration_details')->where('id', $detail->id)->update(['mileage_km' => 90000]);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
        $this->getJson('/api/catalog/vehicles')->assertOk()->assertJsonPath('data.0.inspection', null);
        Sanctum::actingAs($this->mechanic);
        $this->getJson($this->url($vehicle))->assertOk()->assertJsonPath('data.0.state', 'outdated');
    }

    public function test_an_orphaned_report_cannot_attach_to_a_reused_numeric_identity(): void
    {
        $vehicle = $this->vehicle();
        $inspection = $this->inspect($vehicle);
        $id = $vehicle->id;
        $vehicle->forceDelete();
        $replacement = $this->vehicle();
        // A restore/import may explicitly reuse an identifier. Its technical identity can even match.
        DB::table('vehicles')->where('id', $replacement->id)->update(['id' => $id]);
        $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->assertJsonPath('data.inspection', null);
        $this->getJson($this->publicUrl($inspection))->assertNotFound();
    }
}
