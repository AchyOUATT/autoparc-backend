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
            'phone' => '+22670000000',
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
        // L'envoi ne verifie rien : le numero saisi est la, la preuve n'y est
        // pas encore. C'est le moderateur qui appellera.
        $this->postJson('/api/my/private-listings/'.$id.'/submit', ['owner_confirmed' => true, 'contact_consent' => true])
            ->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.phone_claimed', '+22670000000')
            ->assertJsonPath('data.phone', null);
        $this->getJson('/api/private-listings/'.$id)->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/private-listing-reviews/'.$id.'/approve', [
            'revision' => PrivateVehicleListing::findOrFail($id)->revision,
            'phone_verified' => true,
        ])->assertOk()->assertJsonPath('data.phone', '+22670000000');
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

    /**
     * Le formulaire ne peut pas se declarer verifie lui-meme.
     *
     * C'etait la propriete la plus solide du module quand le numero venait
     * d'un jeton Firebase, et la bascule vers l'appel ne doit pas la perdre :
     * le vendeur ecrit `phone`, jamais `verified_phone` ni `phone_verified_at`.
     * Les deux restent dans la liste `prohibited`, et un envoi ne pose aucune
     * preuve — seul `approve()` le fait, apres l'appel.
     *
     * Les consentements restent exiges : sans eux, pas d'envoi.
     */
    public function test_the_form_can_never_declare_its_own_phone_verified(): void
    {
        $listing = $this->listing();
        $listing->forceFill(['verified_phone' => null, 'phone_verified_at' => null])->save();

        // Poser la preuve par le formulaire est refuse, a la creation...
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields([
            'verified_phone' => '+22676000000', 'phone_verified_at' => now()->toIso8601String(),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['verified_phone', 'phone_verified_at']);

        // Les consentements restent exiges, et refuser arrete l'envoi.
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', ['owner_confirmed' => false])
            ->assertUnprocessable();
        $this->assertSame('draft', $listing->fresh()->status);

        // ...et un envoi en regle ne pose aucune preuve de son cote.
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertNull($listing->fresh()->verified_phone);
        $this->assertNull($listing->fresh()->phone_verified_at);
    }

    /**
     * Publier exige que le moderateur affirme avoir appele.
     *
     * La verification par SMS prouvait la possession par cryptographie ;
     * l'appel la prouve par un geste humain, et un geste n'a de valeur que
     * s'il est exige puis consigne. Ce banc tient les deux bouts : sans
     * l'affirmation l'annonce ne se publie pas, et avec elle le numero saisi
     * devient le numero verifie, date et attribue a son moderateur.
     */
    public function test_publishing_demands_the_moderator_assert_the_call(): void
    {
        $listing = $this->listing('pending');
        $listing->forceFill(['verified_phone' => null, 'phone_verified_at' => null])->save();
        $moderateur = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($moderateur);

        // Sans l'affirmation : refus, et l'annonce reste en attente.
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', [
            'revision' => $listing->fresh()->revision,
        ])->assertUnprocessable();
        $this->assertSame('pending', $listing->fresh()->status);
        $this->assertNull($listing->fresh()->phone_verified_at);

        // Une case refusee ne vaut pas mieux qu'une case absente.
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', [
            'revision' => $listing->fresh()->revision, 'phone_verified' => false,
        ])->assertUnprocessable();
        $this->assertSame('pending', $listing->fresh()->status);

        // Avec l'affirmation : le numero saisi devient le numero verifie.
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', [
            'revision' => $listing->fresh()->revision, 'phone_verified' => true,
        ])->assertOk();
        $frais = $listing->fresh();
        $this->assertSame('published', $frais->status);
        $this->assertSame($frais->phone, $frais->verified_phone);
        $this->assertNotNull($frais->phone_verified_at);
        $this->assertSame($moderateur->id, $frais->reviewed_by);
    }

    /**
     * Changer de numero annule l'appel deja passe ; tout le reste le conserve.
     *
     * Sans cela, la verification d'hier couvrirait le numero de ce soir : il
     * suffirait de se faire appeler sur une ligne a soi, puis de remplacer le
     * numero par celui de quelqu'un d'autre. C'est le seul detournement que la
     * bascule du SMS vers l'appel rend possible, et c'est donc le seul garde
     * qu'elle rend indispensable.
     *
     * L'autre moitie compte autant, pour le personnel : une retouche de prix
     * ne doit PAS obliger a rappeler le meme vendeur.
     */
    public function test_changing_the_number_voids_the_call_but_other_edits_keep_it(): void
    {
        $listing = $this->listing('published');
        $this->assertNotNull($listing->phone_verified_at);

        // Une retouche qui ne touche pas au numero : l'appel reste valable.
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields(['price' => 3900000]))->assertOk();
        $apresPrix = $listing->fresh();
        $this->assertNotNull($apresPrix->phone_verified_at, 'Un prix corrige ne doit pas obliger a rappeler le vendeur.');
        $this->assertSame('+22670000000', $apresPrix->verified_phone);

        // Le vendeur renvoie, et le moderateur republie SANS reaffirmer
        // l'appel : c'est toute la raison de distinguer les deux colonnes.
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $this->anonymous();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', [
            'revision' => $listing->fresh()->revision,
        ])->assertOk();
        $this->assertSame('published', $listing->fresh()->status);

        // Changer le numero, en revanche, efface la preuve.
        $this->anonymous();
        $this->client();
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields(['phone' => '+22676543210']))->assertOk();
        $apresNumero = $listing->fresh();
        $this->assertNull($apresNumero->verified_phone, 'Un numero change ne doit pas heriter de l appel precedent.');
        $this->assertNull($apresNumero->phone_verified_at);

        // Et il faut donc un nouvel appel pour republier.
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertOk();
        $this->anonymous();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager', 'firebase_uid' => 'moderateur-2']));
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', [
            'revision' => $listing->fresh()->revision,
        ])->assertUnprocessable();
        $this->assertSame('pending', $listing->fresh()->status);
    }

    /**
     * Un numero qu'on ne peut pas composer ne vaut rien a qui doit appeler.
     *
     * Le controle vit a deux endroits a dessein. `validateFields` le pose sur
     * le formulaire ; `submit()` le repose sur la ligne VERROUILLEE, parce
     * qu'une annonce creee avant cette bascule n'a pas de `phone` du tout — la
     * colonne est nullable — et arriverait sinon en moderation avec un numero
     * vide, impossible a appeler donc impossible a publier.
     */
    public function test_an_undialable_number_is_refused_by_the_form_and_by_the_submission(): void
    {
        // Par le formulaire : le format local ne passe pas.
        $this->postJson('/api/my/private-listings', $this->fields(['phone' => '70000000']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        // Par l'envoi : une annonce anterieure a la bascule n'a aucun numero.
        $listing = $this->listing();
        $listing->forceFill(['phone' => null])->save();
        $this->postJson('/api/my/private-listings/'.$listing->id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
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

    /**
     * Le parcours que l'ecran propose doit aller jusqu'au bout.
     *
     * L'ecran annonce « Photos (x/10) » et envoie une requete par photo. Tant
     * que ces requetes partageaient `throttle:public-write` avec la creation
     * et l'envoi — `ThrottleRequests` construit sa cle sans la route, donc un
     * seul seau de dix par minute — la DOUZIEME requete d'un depot complet
     * tombait en 429, et le refus arrivait des la neuvieme photo.
     *
     * Ce banc est celui qu'aucun test de la branche ne faisait : les autres
     * envoient une photo a la fois et n'atteignent jamais le plafond.
     */
    public function test_a_full_ten_photo_listing_still_reaches_the_submit_route(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');

        for ($i = 1; $i <= 10; $i++) {
            $this->post(
                '/api/my/private-listings/'.$id.'/photos',
                ['photos' => [UploadedFile::fake()->image('photo'.$i.'.jpg', 800, 600)]],
                ['Accept' => 'application/json'],
            )->assertOk();
        }

        $this->postJson('/api/my/private-listings/'.$id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertOk()->assertJsonPath('data.status', 'pending');
    }

    /**
     * Le seau des photos garde un plafond, et c'est le SIEN.
     *
     * Sans ce banc, retirer purement et simplement le limiteur de la route
     * laisserait le banc precedent au vert. Les deux se tiennent : l'un dit
     * que le parcours normal passe, l'autre que le plafond existe toujours.
     *
     * Le limiteur tourne AVANT le controleur, donc une requete refusee pour
     * defaut de validation compte elle aussi — c'est ce qui permet de remplir
     * le seau sans GD et sans ecrire vingt fichiers.
     */
    public function test_the_photo_route_keeps_a_ceiling_of_its_own_without_closing_the_submit_route(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/api/my/private-listings/'.$id.'/photos', [])->assertUnprocessable();
        }
        $this->postJson('/api/my/private-listings/'.$id.'/photos', [])->assertStatus(429);

        // Et le seau plein des photos ne ferme pas l'envoi : ce sont deux
        // seaux distincts. Le 422 vient de l'annonce sans photo, pas du debit.
        $this->postJson('/api/my/private-listings/'.$id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertUnprocessable();
    }

    /**
     * Une photo a canal alpha ne doit pas ressortir en pave noir.
     *
     * `UploadedFile::fake()->image('x.png')` produit un PNG OPAQUE : c'est
     * pour cela qu'aucun banc du depot ne voyait le defaut. Celui-ci fabrique
     * donc la vraie chose — des pixels completement transparents avec du noir
     * stocke dessous, ce que Skia laisse quand image_picker redimensionne un
     * bitmap a canal alpha — et relit le fichier ecrit.
     *
     * @requires extension gd
     */
    public function test_a_transparent_photo_is_flattened_onto_white_instead_of_black(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');

        $this->post(
            '/api/my/private-listings/'.$id.'/photos',
            ['photos' => [UploadedFile::fake()->createWithContent('transparente.png', $this->pngTransparentSurDuNoir())]],
            ['Accept' => 'application/json'],
        )->assertOk();

        $photo = PrivateVehicleListing::findOrFail($id)->photos()->firstOrFail();
        $stocke = imagecreatefromstring(Storage::disk('public')->get($photo->path));
        $coin = imagecolorsforindex($stocke, imagecolorat($stocke, 2, 2));
        $centre = imagecolorsforindex($stocke, imagecolorat($stocke, 20, 20));
        imagedestroy($stocke);

        $this->assertGreaterThan(240, $coin['red'], 'Le transparent doit devenir blanc, pas noir.');
        $this->assertGreaterThan(240, $coin['green']);
        $this->assertGreaterThan(240, $coin['blue']);
        // Et l'image elle-meme survit : le carre opaque est toujours rouge.
        $this->assertGreaterThan(180, $centre['red']);
        $this->assertLessThan(80, $centre['green']);
    }

    /**
     * La page de partage sert la VARIANTE, pas l'original.
     *
     * Le banc existant ne verifiait que `assertSee('Toyota Corolla')`, donc
     * l'en-tete Open Graph n'etait eprouve par rien — et il servait l'original
     * de 2048 px, que WhatsApp refuse : le lien partait sans vignette, c'est-a-
     * dire sans la seule chose qui le distingue d'un lien nu.
     *
     * @requires extension gd
     */
    public function test_the_share_page_serves_the_bounded_preview_and_declares_its_size(): void
    {
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $this->post(
            '/api/my/private-listings/'.$id.'/photos',
            ['photos' => [UploadedFile::fake()->image('voiture.jpg', 2048, 1536)]],
            ['Accept' => 'application/json'],
        )->assertOk();
        $this->postJson('/api/my/private-listings/'.$id.'/submit', [
            'owner_confirmed' => true, 'contact_consent' => true,
        ])->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson('/api/private-listing-reviews/'.$id.'/approve', [
            'revision' => PrivateVehicleListing::findOrFail($id)->revision,
            'phone_verified' => true,
        ])->assertOk();
        $this->anonymous();

        $photo = PrivateVehicleListing::findOrFail($id)->photos()->firstOrFail();
        $this->assertNotNull($photo->preview_path, 'La variante doit etre fabriquee au depot.');
        $this->assertLessThanOrEqual(1200, $photo->preview_width);
        $this->assertLessThanOrEqual(630, $photo->preview_height);

        $page = $this->get('/annonces/'.$id)->assertOk();
        $page->assertSee('property="og:image" content="'.Storage::disk('public')->url($photo->preview_path).'"', false);
        $page->assertSee('property="og:image:width" content="'.$photo->preview_width.'"', false);
        $page->assertSee('property="og:image:height" content="'.$photo->preview_height.'"', false);
        $page->assertSee('name="twitter:card"', false);
        // La mention de confiance : elle est posee sur `phone_verified_at`, et
        // c'est `approve()` qui garantit qu'une annonce publiee le porte.
        $page->assertSee('Téléphone vérifié');
        $page->assertSee('property="og:url" content="'.rtrim((string) config('app.url'), '/').'/annonces/'.$id.'"', false);
        // Et surtout : l'original n'est PAS ce que le robot recoit.
        $page->assertDontSee('property="og:image" content="'.Storage::disk('public')->url($photo->path).'"', false);
    }

    /**
     * Le verrou de version refuse une decision prise sur une version perimee.
     *
     * La garde existait et fonctionnait, mais aucun banc ne la mesurait : on
     * pouvait retirer les deux `abort_unless(..., 409, ...)` du controleur de
     * moderation et la suite restait au vert. Le depot pose pourtant comme
     * regle que chaque garde est eprouvee, et docs/ventes-particuliers.md
     * annonce cette protection au personnel.
     *
     * Les deux chemins qui comptent sont ici : deux moderateurs qui decident
     * sur la meme version, et une edition du vendeur glissee entre l'ouverture
     * de la liste et la decision.
     */
    public function test_a_decision_taken_on_a_stale_revision_is_refused(): void
    {
        $listing = $this->listing('pending');
        $perimee = $listing->revision;
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));

        // Premier moderateur : il publie, et la revision bouge.
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/approve', ['revision' => $perimee])->assertOk();
        $this->assertNotSame($perimee, $listing->fresh()->revision);

        // Second moderateur, meme ecran, meme version : refuse.
        $this->postJson('/api/private-listing-reviews/'.$listing->id.'/reject', [
            'revision' => $perimee, 'reason' => 'Les photos ne montrent pas l’intérieur.',
        ])->assertStatus(409);
        $this->assertSame('published', $listing->fresh()->status);

        // Et l'edition du vendeur, glissee entre l'ouverture de la liste par le
        // moderateur et sa decision. Un AUTRE vendeur : l'index UNIQUE sur
        // `active_user_id` n'accepte qu'une annonce en cours par compte, et
        // c'est voulu.
        $autreVendeur = User::factory()->create(['role' => 'client', 'firebase_uid' => 'autre-vendeur']);
        $autre = $this->listing('pending', $autreVendeur);
        $vue = $autre->revision;

        // Par l'API du proprietaire, et non par le modele : c'est `update` qui
        // appelle `resetReview`, et c'est `resetReview` qui bouge la revision.
        $this->anonymous();
        $this->client($autreVendeur);
        $this->putJson('/api/my/private-listings/'.$autre->id, $this->fields(['city' => 'Bobo-Dioulasso']))->assertOk();
        $this->assertNotSame($vue, $autre->fresh()->revision);

        $this->anonymous();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager', 'firebase_uid' => 'autre-moderateur']));
        $this->postJson('/api/private-listing-reviews/'.$autre->id.'/approve', ['revision' => $vue])->assertStatus(409);
        // Le refus tombe sur la REVISION, avant le controle de statut : rien
        // n'est publie, et l'annonce reste la ou l'edition l'a remise.
        $this->assertSame('draft', $autre->fresh()->status);
    }

    /**
     * La liste publique n'est pas un annuaire de particuliers.
     *
     * `GET private-listings` ne porte aucune authentification et rend vingt
     * fiches par page. Avec le numero dans chacune, un seul appel livrait le
     * nom, la ville et le telephone de vingt vendeurs burkinabe — et les
     * identifiants sont sequentiels. Le numero vit donc sur la FICHE, d'ou l'on
     * appelle, et pas dans la liste, qu'on parcourt.
     *
     * Les quatre lecteurs sont ici, parce que c'est l'ecart entre eux qui est
     * la correction : l'inconnu sur la liste, l'inconnu sur la fiche, le
     * vendeur sur sa propre liste, le moderateur sur la sienne.
     */
    public function test_the_public_list_is_not_a_directory_of_private_sellers(): void
    {
        $listing = $this->listing('published');
        $this->anonymous();

        // L'inconnu qui parcourt la liste n'a pas les numeros.
        $this->getJson('/api/private-listings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $listing->id)
            ->assertJsonPath('data.0.city', 'Ouagadougou')
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonMissingPath('data.0.phone_claimed');

        // Mais celui qui ouvre une annonce peut appeler : c'est tout l'objet
        // de la page, et le bouton disparaitrait sans cela.
        $this->getJson('/api/private-listings/'.$listing->id)
            ->assertOk()->assertJsonPath('data.phone', '+22670000000')
            ->assertJsonMissingPath('data.phone_claimed');

        // Le vendeur voit son propre numero saisi, meme dans une liste.
        $this->client();
        $this->getJson('/api/my/private-listings')
            ->assertOk()->assertJsonPath('data.0.phone_claimed', '+22670000000');

        // Et le moderateur aussi : c'est le numero qu'il doit composer, et
        // c'est pour lui que `phone_claimed` existe — il doit appeler AVANT
        // qu'aucune verification existe, donc quand `phone` est encore vide.
        // Son index ne liste que les annonces en attente, d'ou la seconde.
        $enAttente = $this->listing('pending', User::factory()->create([
            'role' => 'client', 'firebase_uid' => 'vendeur-en-attente',
        ]));
        $enAttente->forceFill(['verified_phone' => null, 'phone_verified_at' => null])->save();

        $this->anonymous();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/private-listing-reviews')
            ->assertOk()
            ->assertJsonPath('data.0.id', $enAttente->id)
            ->assertJsonPath('data.0.phone_claimed', '+22670000000')
            // Et `phone` est la, VIDE : le moderateur voit donc exactement ce
            // qui est vrai — un numero a appeler, et aucune verification.
            ->assertJsonPath('data.0.phone', null);
    }

    /**
     * La page de partage n'entre pas dans un moteur de recherche.
     *
     * C'est la seule partie irreversible : une annonce retiree rend 404, mais
     * un cache de moteur ne se reprend pas, et le numero d'un particulier
     * resterait trouvable apres le retrait.
     *
     * La balise est dans la VUE et non dans robots.txt : un `Disallow` serait
     * respecte par `facebookexternalhit` et tuerait la vignette WhatsApp, donc
     * la raison d'etre de la page. Ce banc tient les deux cotes.
     *
     * @requires extension gd
     */
    public function test_the_share_page_refuses_indexing_without_breaking_previews(): void
    {
        $listing = $this->listing('published');

        $page = $this->get('/annonces/'.$listing->id)->assertOk();
        $page->assertSee('name="robots" content="noindex, nofollow"', false);
        // Et l'en-tete d'apercu reste servi : c'est ce que lit le robot de
        // WhatsApp, qui ne regarde pas la balise robots.
        $page->assertSee('property="og:title"', false);

        // robots.txt doit rester permissif, pour la meme raison. On le lit sur
        // le disque et non par une requete : c'est un fichier statique, servi
        // par le serveur web et jamais par Laravel, qui rend donc 404 en test.
        $this->assertStringNotContainsString(
            'Disallow: /',
            (string) file_get_contents(public_path('robots.txt')),
            'Un Disallow serait respecte par facebookexternalhit et tuerait la vignette WhatsApp.',
        );
    }

    /** Des pixels completement transparents, avec du noir dessous, et un carre opaque au centre. */
    private function pngTransparentSurDuNoir(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 15, 15, 24, 24, imagecolorallocatealpha($image, 220, 30, 30, 0));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
