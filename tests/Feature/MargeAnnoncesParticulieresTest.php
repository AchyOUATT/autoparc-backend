<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\PrivateVehicleListing;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\FirebaseAuthService;
use App\Support\MargeAnnonceParticuliere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeFirebaseAuthService;
use Tests\TestCase;

class MargeAnnoncesParticulieresTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Brand $brand;

    private VehicleModel $vehicleModel;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('private_sales.enabled', true);
        $this->seller = User::factory()->create(['role' => 'client', 'firebase_uid' => 'seller-margin', 'is_active' => true]);
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota-margin']);
        $this->vehicleModel = VehicleModel::create([
            'brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla-margin',
        ]);
    }

    public function test_le_taux_public_vaut_zero_par_defaut_sans_authentification(): void
    {
        $this->getJson('/api/app/private-listing-margin')
            ->assertOk()
            ->assertExactJson(['taux' => 0])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_seul_l_admin_peut_lire_et_modifier_le_taux(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrateur']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/settings/private-listing-margin')
            ->assertOk()
            ->assertJsonPath('taux', 0)
            ->assertJsonPath('derniere_modification', null);

        $this->putJson('/api/settings/private-listing-margin', ['taux' => 10])
            ->assertOk()
            ->assertJsonPath('taux', 10)
            ->assertJsonPath('derniere_modification.taux_precedent', null)
            ->assertJsonPath('derniere_modification.taux', 10)
            ->assertJsonPath('derniere_modification.modifie_par.id', $admin->id)
            ->assertJsonPath('derniere_modification.modifie_par.name', 'Administrateur');
        $this->assertNotNull($this->getJson('/api/settings/private-listing-margin')->json('derniere_modification.modifie_le'));

        $this->assertDatabaseHas('app_settings', [
            'key' => MargeAnnonceParticuliere::SETTING_KEY, 'value' => '10',
        ]);
        $this->assertDatabaseCount('app_setting_changes', 1);
        $this->assertDatabaseHas('app_setting_changes', [
            'key' => MargeAnnonceParticuliere::SETTING_KEY,
            'old_value' => null,
            'new_value' => '10',
            'user_id' => $admin->id,
        ]);

        $this->putJson('/api/settings/private-listing-margin', ['taux' => 7.5])
            ->assertOk()
            ->assertJsonPath('taux', 7.5)
            ->assertJsonPath('derniere_modification.taux_precedent', 10)
            ->assertJsonPath('derniere_modification.taux', 7.5);
        $this->assertDatabaseCount('app_setting_changes', 2);

        $this->asPublic();
        $this->getJson('/api/app/private-listing-margin')->assertOk()->assertJsonPath('taux', 7.5);
    }

    public function test_un_manager_et_les_autres_roles_recoivent_403(): void
    {
        foreach ([...UserRole::staffValues(), 'client', 'partner'] as $role) {
            if ($role === 'admin') {
                continue;
            }
            $user = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($user);
            $this->getJson('/api/settings/private-listing-margin')
                ->assertForbidden()
                ->assertJsonPath('code', 'permission_denied');
            $this->putJson('/api/settings/private-listing-margin', ['taux' => 10])
                ->assertForbidden()
                ->assertJsonPath('code', 'permission_denied');
            $this->asPublic();
        }

        $this->getJson('/api/settings/private-listing-margin')->assertUnauthorized();
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 10])->assertUnauthorized();
    }

    public function test_le_taux_est_borne_et_n_accepte_qu_une_decimale(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/settings/private-listing-margin', ['taux' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('taux');
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 50.1])
            ->assertUnprocessable()->assertJsonValidationErrors('taux');
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 7.55])
            ->assertUnprocessable()->assertJsonValidationErrors('taux');
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('taux');
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 0])->assertOk()->assertJsonPath('taux', 0);
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 50])->assertOk()->assertJsonPath('taux', 50);
        $this->putJson('/api/settings/private-listing-margin', ['taux' => 7.5])->assertOk()->assertJsonPath('taux', 7.5);
    }

    public function test_une_creation_applique_le_taux_et_fige_le_detail(): void
    {
        $this->definirTaux(10);
        $this->actingAsSeller();
        $id = $this->postJson('/api/my/private-listings', $this->fields())
            ->assertCreated()
            ->assertJsonPath('data.price', 4_950_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 4_500_000)
            ->assertJsonPath('data.private_pricing.taux', 10)
            ->assertJsonPath('data.private_pricing.total', 4_950_000)
            ->json('data.id');
        $this->assertNotNull(PrivateVehicleListing::findOrFail($id)->private_pricing['applique_le']);

        $this->asPublic();
        $this->getJson('/api/private-listings/'.$id)->assertNotFound();
        $this->publier($id);
        $this->asPublic();
        $this->getJson('/api/private-listings/'.$id)
            ->assertOk()
            ->assertJsonPath('data.price', 4_950_000)
            ->assertJsonMissingPath('data.private_pricing')
            ->assertJsonMissingPath('data.prix_propose');
        $this->get('/annonces/'.$id)->assertOk()->assertSee('4 950 000', false);

        $this->getJson('/api/catalog/vehicle-listings?include_private=1&price_max=4900000')
            ->assertOk()
            ->assertJsonMissingPath('data.0');
        $this->getJson('/api/catalog/vehicle-listings?include_private=1&price_min=4950000&price_max=4950000&sort_by=sale_price')
            ->assertOk()
            ->assertJsonPath('data.0.private_listing.price', 4_950_000)
            ->assertJsonMissingPath('data.0.private_listing.private_pricing');
    }

    public function test_une_creation_au_taux_zero_conserve_le_prix_et_enregistre_le_detail(): void
    {
        $this->actingAsSeller();
        $this->postJson('/api/my/private-listings', $this->fields())
            ->assertCreated()
            ->assertJsonPath('data.price', 4_500_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 4_500_000)
            ->assertJsonPath('data.private_pricing.taux', 0)
            ->assertJsonPath('data.private_pricing.total', 4_500_000);
    }

    public function test_le_client_ne_peut_pas_imposer_le_prix_public_ni_le_detail(): void
    {
        $this->definirTaux(10);
        $this->actingAsSeller();
        $this->postJson('/api/my/private-listings', $this->fields([
            'private_pricing' => ['prix_propose' => 1, 'taux' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors('private_pricing');
    }

    public function test_une_edition_sans_changement_de_prix_conserve_le_calcul_fige(): void
    {
        $this->definirTaux(10);
        $this->actingAsSeller();
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $applique = PrivateVehicleListing::findOrFail($id)->private_pricing['applique_le'];

        $this->definirTaux(20);
        $this->actingAsSeller();
        $this->putJson('/api/my/private-listings/'.$id, $this->fields(['city' => 'Bobo-Dioulasso', 'price' => 4_500_000]))
            ->assertOk()
            ->assertJsonPath('data.price', 4_950_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 4_500_000)
            ->assertJsonPath('data.private_pricing.taux', 10)
            ->assertJsonPath('data.city', 'Bobo-Dioulasso');
        $this->assertSame($applique, PrivateVehicleListing::findOrFail($id)->private_pricing['applique_le']);
    }

    public function test_un_changement_de_prix_recalcule_au_taux_du_jour(): void
    {
        $this->definirTaux(10);
        $this->actingAsSeller();
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');

        $this->definirTaux(20);
        $this->actingAsSeller();
        $this->putJson('/api/my/private-listings/'.$id, $this->fields(['price' => 5_000_000]))
            ->assertOk()
            ->assertJsonPath('data.price', 6_000_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 5_000_000)
            ->assertJsonPath('data.private_pricing.taux', 20);
    }

    public function test_un_ancien_client_qui_renvoie_le_prix_public_ne_subit_pas_une_seconde_majoration(): void
    {
        $this->definirTaux(10);
        $this->actingAsSeller();
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $this->assertSame(4_950_000, (int) PrivateVehicleListing::findOrFail($id)->price);

        $this->definirTaux(20);
        $this->actingAsSeller();
        $this->putJson('/api/my/private-listings/'.$id, $this->fields(['price' => 4_950_000, 'city' => 'Koudougou']))
            ->assertOk()
            ->assertJsonPath('data.price', 4_950_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 4_500_000)
            ->assertJsonPath('data.private_pricing.taux', 10)
            ->assertJsonPath('data.city', 'Koudougou');
    }

    public function test_une_annonce_historique_sans_detail_garde_son_prix_si_on_le_renvoie_tel_quel(): void
    {
        $listing = $this->annonceHistorique(4_500_000);
        $this->definirTaux(10);
        $this->actingAsSeller();
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields(['price' => 4_500_000, 'city' => 'Ouahigouya']))
            ->assertOk()
            ->assertJsonPath('data.price', 4_500_000)
            ->assertJsonPath('data.private_pricing', null)
            ->assertJsonPath('data.city', 'Ouahigouya');
        $this->assertNull($listing->fresh()->private_pricing);
    }

    public function test_une_annonce_historique_prend_le_taux_du_jour_si_son_prix_change(): void
    {
        $listing = $this->annonceHistorique(4_500_000);
        $this->definirTaux(10);
        $this->actingAsSeller();
        $this->putJson('/api/my/private-listings/'.$listing->id, $this->fields(['price' => 4_000_000]))
            ->assertOk()
            ->assertJsonPath('data.price', 4_400_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 4_000_000)
            ->assertJsonPath('data.private_pricing.taux', 10);
    }

    public function test_arrondi_au_franc_le_plus_proche(): void
    {
        $this->assertSame(4_950_000, MargeAnnonceParticuliere::majorer(4_500_000, 10));
        $this->assertSame(1_075, MargeAnnonceParticuliere::majorer(1_000, 7.5));
        $this->assertSame(1_001, MargeAnnonceParticuliere::majorer(1_000, 0.1));
        $this->assertSame(17, MargeAnnonceParticuliere::majorer(15, 10));
        $this->assertSame(1_000, MargeAnnonceParticuliere::majorer(1_000, 0));
    }

    public function test_le_detail_n_apparait_que_pour_le_vendeur_et_le_moderateur(): void
    {
        $this->definirTaux(10);
        $this->actingAsSeller();
        $id = $this->postJson('/api/my/private-listings', $this->fields())->assertCreated()->json('data.id');
        $this->publier($id);

        $this->actingAsSeller();
        $this->getJson('/api/my/private-listings/'.$id)
            ->assertOk()
            ->assertJsonPath('data.price', 4_950_000)
            ->assertJsonPath('data.private_pricing.prix_propose', 4_500_000);

        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/private-listing-reviews')->assertOk();
        $this->asPublic();
        $this->getJson('/api/private-listings/'.$id)
            ->assertOk()
            ->assertJsonPath('data.price', 4_950_000)
            ->assertJsonMissingPath('data.private_pricing');
    }

    private function fields(array $extra = []): array
    {
        return array_replace([
            'fault_declaration_state' => 'none',
            'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->vehicleModel->id,
            'year' => 2020,
            'mileage_km' => 80000,
            'price' => 4_500_000,
            'city' => 'Ouagadougou',
            'description' => 'Véhicule entretenu, disponible pour une visite.',
            'seller_name' => 'Vendeur particulier',
            'whatsapp_enabled' => true,
            'phone' => '+22670000000',
        ], $extra);
    }

    private function annonceHistorique(int $prix): PrivateVehicleListing
    {
        return PrivateVehicleListing::create($this->fields(['price' => $prix]) + [
            'title' => 'Toyota Corolla',
            'user_id' => $this->seller->id,
            'active_user_id' => $this->seller->id,
            'status' => 'draft',
            'verified_phone' => '+22670000000',
            'phone_verified_at' => now(),
        ]);
    }

    private function definirTaux(float $taux): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->putJson('/api/settings/private-listing-margin', ['taux' => $taux])->assertOk();
        $this->asPublic();
    }

    private function actingAsSeller(): void
    {
        $this->asPublic();
        $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService([
            'uid' => $this->seller->firebase_uid,
            'email' => $this->seller->email,
            'name' => $this->seller->name,
            'phone_number' => '+22670000000',
        ]));
        $this->withHeader('Authorization', 'Bearer fake-firebase-token');
    }

    private function publier(int $id): void
    {
        $listing = PrivateVehicleListing::findOrFail($id);
        $listing->photos()->create(['disk' => 'public', 'path' => 'private-listings/'.$id.'/example.jpg']);
        $listing->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'verified_phone' => '+22670000000',
            'phone_verified_at' => now(),
        ])->save();
    }

    private function asPublic(): void
    {
        Auth::forgetGuards();
        $this->defaultHeaders = [];
        $this->flushHeaders();
    }
}
