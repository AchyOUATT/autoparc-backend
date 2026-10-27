<?php

namespace Tests\Feature;

use App\Models\OwnedVehicle;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\PrivateVehicleListing;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeFirebaseAuthService;
use Tests\TestCase;

class PrivateVehicleSalesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Brand $brand;
    private VehicleModel $vehicleModel;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('private_sales.enabled', true);
        config()->set('media.disk', 'public');
        Storage::fake('public');
        $this->seller = User::factory()->create(['role' => 'client', 'firebase_uid' => 'seller']);
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->vehicleModel = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla']);
        $this->client();
    }

    private function client(?User $user = null, ?string $phone = '+22670000000'): void
    {
        $user ??= $this->seller;
        $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService([
            'uid' => $user->firebase_uid, 'email' => $user->email, 'name' => $user->name, 'phone_number' => $phone,
        ]));
        $this->withHeader('Authorization', 'Bearer fake-firebase-token');
    }

    private function fields(array $extra = []): array
    {
        return array_replace([
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->vehicleModel->id,
            'year' => 2020, 'mileage_km' => 80000,
            'price' => 4500000, 'city' => 'Ouagadougou',
            'description' => 'Véhicule entretenu, disponible pour une visite.',
            'seller_name' => 'Vendeur particulier', 'whatsapp_enabled' => true,
        ], $extra);
    }

    private function listing(string $status = 'draft', ?User $seller = null): PrivateVehicleListing
    {
        $seller ??= $this->seller;
        $listing = PrivateVehicleListing::create($this->fields() + [
            'title' => 'Toyota Corolla',
            'user_id' => $seller->id, 'active_user_id' => $seller->id, 'status' => $status,
            'verified_phone' => '+22670000000', 'phone_verified_at' => now(),
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $listing->photos()->create(['disk' => 'public', 'path' => 'private-listings/'.$listing->id.'/example.jpg']);

        return $listing;
    }

    private function anonymous(): void
    {
        $this->flushHeaders();
        auth()->forgetGuards();
    }

    public function test_complete_client_to_moderator_to_public_flow(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->post('/api/my/private-listings/'.$id.'/photos', ['photos' => [UploadedFile::fake()->image('voiture.jpg', 800, 600)]], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/my/private-listings/'.$id.'/submit', ['owner_confirmed' => true, 'contact_consent' => true])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.phone', '+22670000000');
        $this->getJson('/api/private-listings/'.$id)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/private-listing-reviews/'.$id.'/approve', ['revision' => PrivateVehicleListing::findOrFail($id)->revision])->assertOk();
        $this->anonymous();
        $this->getJson('/api/private-listings/'.$id)->assertOk()->assertJsonPath('data.seller_type', 'private')
            ->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.owned_vehicle_id')
            ->assertJsonMissingPath('data.reviewed_by')->assertJsonMissingPath('data.review_reason');
        $this->get('/annonces/'.$id)->assertOk()->assertSee('Toyota Corolla')->assertSee('Particulier');
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('partner_accounts', 0);
    }

    public function test_module_switch_closes_every_entry_point(): void
    {
        $listing = $this->listing('published');
        config()->set('private_sales.enabled', false);
        $this->getJson('/api/private-listings')->assertNotFound();
        $this->getJson('/api/my/private-listings')->assertNotFound();
        $this->postJson('/api/my/private-listings', $this->fields())->assertNotFound();
        $this->get('/annonces/'.$listing->id)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/private-listing-reviews')->assertNotFound();
    }

    public function test_authentication_is_required_and_staff_cannot_become_a_private_seller(): void
    {
        $this->flushHeaders();
        $this->postJson('/api/my/private-listings', $this->fields())->assertUnauthorized();
        $staff = User::factory()->create(['role' => 'admin', 'firebase_uid' => 'staff']);
        $this->client($staff);
        $this->postJson('/api/my/private-listings', $this->fields())->assertForbidden();
        $this->assertDatabaseCount('private_vehicle_listings', 0);
    }

    public function test_another_persons_garage_cannot_be_used_as_source(): void
    {
        $garage = OwnedVehicle::create([
            'user_id' => User::factory()->create(['role' => 'client'])->id,
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->vehicleModel->id,
            'manufacturing_year' => 2020,
        ]);
        $this->postJson('/api/my/private-listings', $this->fields(['owned_vehicle_id' => $garage->id]))->assertNotFound();
        $this->assertDatabaseCount('private_vehicle_listings', 0);
    }

    public function test_garage_remains_private_and_listing_is_an_independent_snapshot(): void
    {
        $garage = OwnedVehicle::create([
            'user_id' => $this->seller->id, 'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->vehicleModel->id, 'manufacturing_year' => 2020,
            'vin' => 'PRIVATE-VIN', 'plate_number' => 'PRIVATE-PLATE',
        ]);
        $id = $this->postJson('/api/my/private-listings', $this->fields(['owned_vehicle_id' => $garage->id]))->assertCreated()->json('data.id');
        $listing = PrivateVehicleListing::findOrFail($id);
        $listing->update(['status' => 'published', 'published_at' => now(), 'review_reason' => 'PRIVATE-REASON']);
        $garage->update(['nickname' => 'PRIVATE-NICKNAME', 'mileage_km' => 100000]);
        $this->anonymous();
        $result = $this->getJson('/api/private-listings/'.$id)->assertOk()->assertJsonPath('data.mileage_km', 80000)->getContent();
        foreach (['PRIVATE-VIN', 'PRIVATE-PLATE', 'PRIVATE-REASON', 'PRIVATE-NICKNAME'] as $secret) {
            $this->assertStringNotContainsString($secret, $result);
        }
        $this->get('/annonces/'.$id)->assertOk()->assertDontSee('PRIVATE-VIN')->assertDontSee('PRIVATE-PLATE');
    }

    public function test_server_owned_fields_cannot_be_forged(): void
    {
        $this->postJson('/api/my/private-listings', $this->fields([
            'status' => 'published', 'verified_phone' => '+22671111111', 'user_id' => 999,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['status', 'verified_phone', 'user_id']);
    }

    public function test_one_current_listing_and_withdrawal_frees_the_slot(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $this->postJson('/api/my/private-listings', $this->fields())->assertUnprocessable()->assertJsonValidationErrors('listing');
        $this->postJson('/api/my/private-listings/'.$id.'/withdraw')->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->postJson('/api/my/private-listings', $this->fields())->assertCreated();
        $this->assertDatabaseCount('private_vehicle_listings', 2);
    }

    public function test_empty_reserved_fields_cannot_disable_the_one_listing_rule(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields([
            'active_user_id' => null, 'user_id' => null, 'status' => null,
            'verified_phone' => null, 'currency' => '', 'revision' => null,
        ]))->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision', 1)->json('data.id');
        $this->assertDatabaseHas('private_vehicle_listings', [
            'id' => $id, 'active_user_id' => $this->seller->id,
            'user_id' => $this->seller->id, 'currency' => 'XOF',
        ]);
        $this->postJson('/api/my/private-listings', $this->fields())->assertUnprocessable();
    }

    public function test_only_token_phone_proof_counts_and_consents_are_required(): void
    {
        $listing = $this->listing();
        $this->seller->update(['phone' => '+22670000000']);
        $this->client(phone: null);
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', ['owner_confirmed' => true, 'contact_consent' => true, 'phone' => '+22670000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->client();
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', ['owner_confirmed' => false])->assertUnprocessable();
        $this->assertSame('draft', $listing->fresh()->status);
    }

    public function test_photo_is_required_before_submission(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $this->postJson('/api/my/private-listings/'.$id.'/submit', ['owner_confirmed' => true, 'contact_consent' => true])->assertUnprocessable();
    }

    public function test_cross_owner_read_edit_action_and_photo_access_are_denied(): void
    {
        $other = User::factory()->create(['role' => 'client', 'firebase_uid' => 'other']);
        $listing = $this->listing(seller: $other);
        $path = '/api/my/private-listings/'.$listing->id;
        $this->getJson($path)->assertNotFound();
        $this->putJson($path, $this->fields())->assertNotFound();
        $this->postJson($path.'/withdraw')->assertNotFound();
        $this->deleteJson($path.'/photos/'.$listing->photos->first()->id)->assertNotFound();
        $this->assertSame('draft', $listing->fresh()->status);
        $this->assertSame(1, $listing->photos()->count());
    }

    public function test_all_unpublished_states_are_absent_from_public_api_and_share_pages(): void
    {
        $listing = $this->listing();
        $this->anonymous();
        foreach (['draft', 'pending', 'rejected', 'withdrawn', 'sold'] as $status) {
            $listing->update(['status' => $status]);
            $this->getJson('/api/private-listings')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/private-listings/'.$listing->id)->assertNotFound();
            $this->get('/annonces/'.$listing->id)->assertNotFound();
        }
    }

    public function test_editing_a_published_listing_requires_review_again(): void
    {
        $listing = $this->listing('published');
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields(['price' => 4700000]))
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.published_at', null);
        $this->getJson('/api/private-listings/'.$listing->id)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', ['revision' => $listing->fresh()->revision])->assertUnprocessable();
    }

    public function test_photo_changes_require_review_and_cannot_delete_another_listings_image(): void
    {
        $listing = $this->listing('published');
        $other = $this->listing(seller: User::factory()->create(['role' => 'client']));
        $this->deleteJson('/api/my/private-listings/'.$listing->id.'/photos/'.$other->photos->first()->id)->assertNotFound();
        $this->post('/api/my/private-listings/'.$listing->id.'/photos', ['photos' => [UploadedFile::fake()->image('nouvelle.png', 300, 200)]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->getJson('/api/private-listings/'.$listing->id)->assertNotFound();
        $newPhoto = $listing->photos()->reorder()->latest('id')->first();
        Storage::disk('public')->assertExists($newPhoto->path);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($newPhoto->path))['mime']);
    }

    public function test_only_catalog_staff_can_moderate_and_reason_is_visible_only_to_seller(): void
    {
        $listing = $this->listing('pending');
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $this->getJson('/api/private-listing-reviews')->assertForbidden();
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/reject', ['revision' => $listing->revision, 'reason' => 'Photos trop sombres.'])->assertOk();
        $this->anonymous(); $this->client();
        $this->getJson('/api/my/private-listings/'.$listing->id)->assertOk()->assertJsonPath('data.review_reason', 'Photos trop sombres.');
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', ['owner_confirmed' => true, 'contact_consent' => true])->assertOk();
    }

    public function test_sold_removes_the_public_listing_and_releases_the_slot(): void
    {
        $listing = $this->listing('published');
        $this->postJson('/api/my/private-listings/'.$listing->id.'/sold')->assertOk()->assertJsonPath('data.status', 'sold');
        $this->getJson('/api/private-listings/'.$listing->id)->assertNotFound();
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields())->assertUnprocessable();
        $this->postJson('/api/my/private-listings', $this->fields())->assertCreated();
    }

    public function test_disabled_sellers_disappear_and_account_deletion_removes_listings_and_photo_records(): void
    {
        $listing = $this->listing('published');
        $this->seller->update(['is_active' => false]);
        $this->anonymous();
        $this->getJson('/api/private-listings/'.$listing->id)->assertNotFound();
        $this->seller->update(['is_active' => true]); $this->client();
        $this->deleteJson('/api/my/account')->assertOk();
        $this->assertDatabaseCount('private_vehicle_listings', 0);
        $this->assertDatabaseCount('private_listing_photos', 0);
    }

    public function test_share_page_escapes_user_supplied_html(): void
    {
        $listing = $this->listing('published');
        $listing->update(['title' => '<script>alert(1)</script>']);
        $this->get('/annonces/'.$listing->id)->assertOk()->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_structured_fields_produce_a_public_snapshot_from_catalog_values(): void
    {
        $this->vehicleModel->update(['generation' => 'E210']);
        $engine = EngineType::create(['code' => 'hybrid', 'label' => 'Hybride']);
        $drive = Drivetrain::create(['code' => 'FWD', 'label' => 'Traction']);
        $color = Color::create(['name' => 'Blanc', 'hex_code' => '#FFFFFF']);
        $trim = Trim::create(['vehicle_model_id' => $this->vehicleModel->id, 'name' => 'Limited']);
        $id = $this->postJson('/api/my/private-listings', $this->fields([
            'trim_id' => $trim->id, 'engine_type_id' => $engine->id,
            'drivetrain_id' => $drive->id, 'color_id' => $color->id, 'transmission' => 'cvt',
        ]))->assertCreated()->assertJsonPath('data.title', 'Toyota Corolla E210 Limited')
            ->assertJsonPath('data.vehicle_identity.engine_type', 'Hybride')->assertJsonPath('data.vehicle_identity.color', 'Blanc')
            ->assertJsonPath('data.engine_type_id', $engine->id)->assertJsonPath('data.fuel_type', 'hybrid')
            ->assertJsonPath('data.transmission', 'cvt')->json('data.id');
        PrivateVehicleListing::findOrFail($id)->update(['status' => 'published', 'published_at' => now()]);
        $this->brand->update(['name' => 'Marque renommée']);
        $this->vehicleModel->update(['name' => 'Modèle renommé']);
        $engine->update(['label' => 'Type renommé']);
        $this->anonymous();
        $this->getJson('/api/private-listings/'.$id)->assertOk()->assertJsonPath('data.title', 'Toyota Corolla E210 Limited')
            ->assertJsonPath('data.vehicle_identity.brand', 'Toyota')->assertJsonPath('data.vehicle_identity.engine_type', 'Hybride');
    }

    public function test_title_and_identity_sent_by_the_client_are_never_authoritative(): void
    {
        $this->postJson('/api/my/private-listings', $this->fields([
            'title' => 'Autre voiture', 'vehicle_identity' => ['brand' => 'Faussée'], 'fuel_type' => 'electric',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['title', 'vehicle_identity', 'fuel_type']);
        $this->postJson('/api/my/private-listings', $this->fields([
            'title' => null, 'vehicle_identity' => null, 'fuel_type' => null,
        ]))->assertCreated()->assertJsonPath('data.title', 'Toyota Corolla')->assertJsonPath('data.fuel_type', null);
    }

    public function test_model_and_trim_must_belong_to_the_selected_identity(): void
    {
        $otherBrand = Brand::create(['name' => 'Honda', 'slug' => 'honda']);
        $otherModel = VehicleModel::create(['brand_id' => $otherBrand->id, 'name' => 'Civic', 'slug' => 'civic']);
        $otherTrim = Trim::create(['vehicle_model_id' => $otherModel->id, 'name' => 'Sport']);
        $this->postJson('/api/my/private-listings', $this->fields(['vehicle_model_id' => $otherModel->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_model_id');
        $this->postJson('/api/my/private-listings', $this->fields(['trim_id' => $otherTrim->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('trim_id');
        $this->postJson('/api/my/private-listings', $this->fields(['color_id' => [123]]))
            ->assertUnprocessable()->assertJsonValidationErrors('color_id');
        $this->assertDatabaseCount('private_vehicle_listings', 0);
    }

    public function test_free_model_is_supported_without_catalog_trim_and_cannot_conflict_with_a_catalog_model(): void
    {
        $trim = Trim::create(['vehicle_model_id' => $this->vehicleModel->id, 'name' => 'Limited']);
        $this->postJson('/api/my/private-listings', $this->fields(['model_libre' => 'CX-9']))
            ->assertUnprocessable()->assertJsonValidationErrors('model_libre');
        $this->postJson('/api/my/private-listings', $this->fields(['vehicle_model_id' => null, 'model_libre' => 'CX-9', 'trim_id' => $trim->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('trim_id');
        $this->postJson('/api/my/private-listings', $this->fields(['vehicle_model_id' => null, 'model_libre' => 'Crown importée']))
            ->assertCreated()->assertJsonPath('data.title', 'Toyota Crown importée')->assertJsonPath('data.vehicle_model_id', null);
    }

    public function test_inactive_brand_and_model_cannot_be_newly_selected_but_existing_selection_can_be_retained(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $this->brand->update(['is_active' => false]);
        $this->vehicleModel->update(['is_active' => false]);
        $this->putJson('/api/my/private-listings/'.$id, $this->fields(['price' => 4600000]))
            ->assertOk()->assertJsonPath('data.title', 'Toyota Corolla');
        $otherBrand = Brand::create(['name' => 'Honda', 'slug' => 'honda', 'is_active' => false]);
        $otherModel = VehicleModel::create(['brand_id' => $otherBrand->id, 'name' => 'Civic', 'slug' => 'civic', 'is_active' => false]);
        $this->putJson('/api/my/private-listings/'.$id, $this->fields(['brand_id' => $otherBrand->id, 'vehicle_model_id' => $otherModel->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['brand_id', 'vehicle_model_id']);
        $this->postJson('/api/my/private-listings/'.$id.'/withdraw')->assertOk();
        $this->postJson('/api/my/private-listings', $this->fields())
            ->assertUnprocessable()->assertJsonValidationErrors(['brand_id', 'vehicle_model_id']);
    }

    public function test_optional_references_clear_on_update_and_trim_defaults_are_never_assumed(): void
    {
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $trim = Trim::create(['vehicle_model_id' => $this->vehicleModel->id, 'name' => 'Limited', 'default_engine_type_id' => $engine->id]);
        $id = $this->postJson('/api/my/private-listings', $this->fields(['trim_id' => $trim->id]))
            ->assertCreated()->assertJsonPath('data.engine_type_id', null)->assertJsonPath('data.fuel_type', null)->json('data.id');
        $this->putJson('/api/my/private-listings/'.$id, $this->fields())
            ->assertOk()->assertJsonPath('data.trim_id', null)->assertJsonPath('data.title', 'Toyota Corolla');
    }

    public function test_legacy_local_announcements_remain_readable_but_an_edit_requires_selection(): void
    {
        $listing = $this->listing('published');
        $listing->update(['brand_id' => null, 'vehicle_model_id' => null, 'vehicle_identity' => null]);
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()->assertJsonPath('data.title', 'Toyota Corolla');
        $payload = $this->fields();
        unset($payload['brand_id'], $payload['vehicle_model_id']);
        $this->putJson('/api/my/private-listings/'.$listing->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['brand_id', 'vehicle_model_id']);
    }

    public function test_unvalidated_garage_fields_cannot_enter_identity_checks_or_the_public_snapshot(): void
    {
        $this->postJson('/api/my/private-listings', $this->fields([
            'motorisation_id' => ['unexpected' => 123],
            'vin' => 'PRIVATE-VIN', 'plate_number' => 'PRIVATE-PLATE', 'engine_code' => 'PRIVATE-ENGINE',
        ]))->assertCreated()->assertJsonPath('data.title', 'Toyota Corolla')
            ->assertJsonMissingPath('data.vin')->assertJsonMissingPath('data.plate_number')
            ->assertJsonMissingPath('data.engine_code')->assertJsonMissingPath('data.motorisation_id');
        $this->assertStringNotContainsString('PRIVATE-', json_encode(PrivateVehicleListing::firstOrFail()->vehicle_identity));
    }
}
