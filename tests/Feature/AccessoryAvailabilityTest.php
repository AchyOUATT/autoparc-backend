<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessoryAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function stockRoles(): array
    {
        return [['admin'], ['manager'], ['warehouse']];
    }

    #[DataProvider('stockRoles')]
    public function test_un_accessoire_indisponible_reste_visible_au_personnel_et_peut_etre_reactive(string $role): void
    {
        $accessory = Accessory::factory()->create(['is_available' => true, 'stock_quantity' => 5]);
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->patchJson("/api/accessories/{$accessory->id}/availability")
            ->assertOk()->assertJsonPath('is_available', false);

        $this->getJson('/api/accessories')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accessory->id)
            ->assertJsonPath('data.0.stock.is_available', false)
            ->assertJsonPath('data.0.stock.quantity', 5);
        $this->getJson('/api/catalog/accessories')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/a/{$accessory->id}")->assertNotFound();
        $this->assertDatabaseHas('accessories', [
            'id' => $accessory->id, 'deleted_at' => null, 'is_active' => true,
            'is_available' => false, 'stock_quantity' => 5,
        ]);

        $this->patchJson("/api/accessories/{$accessory->id}/availability")
            ->assertOk()->assertJsonPath('is_available', true);
        $this->getJson('/api/accessories')->assertOk()
            ->assertJsonPath('data.0.id', $accessory->id)
            ->assertJsonPath('data.0.stock.is_available', true);
        $this->getJson('/api/catalog/accessories')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $accessory->id);
    }

    public function test_le_catalogue_public_ne_permet_pas_d_inclure_les_indisponibles(): void
    {
        $available = Accessory::factory()->create(['is_available' => true]);
        Accessory::factory()->create(['is_available' => false]);
        Accessory::factory()->create(['is_active' => false, 'is_available' => true]);

        $this->getJson('/api/catalog/accessories?include_unavailable=1&active_only=0')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $available->id);
        $this->getJson('/api/accessories')->assertUnauthorized();
    }

    public static function nonStaffRoles(): array
    {
        return [['client'], ['partner']];
    }

    #[DataProvider('nonStaffRoles')]
    public function test_un_compte_non_staff_ne_peut_ni_lister_ni_basculer_le_stock(string $role): void
    {
        $accessory = Accessory::factory()->create(['is_available' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson('/api/accessories')->assertForbidden();
        $this->patchJson("/api/accessories/{$accessory->id}/availability")->assertForbidden();
        $this->getJson('/api/catalog/accessories')->assertOk()->assertJsonCount(0, 'data');
        $this->assertFalse($accessory->fresh()->is_available);
    }

    public function test_la_liste_staff_garde_les_photos_et_le_filtre_de_compatibilite_des_indisponibles(): void
    {
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $accessory = Accessory::factory()->create(['is_available' => false]);
        $accessory->fitments()->create(['vehicle_model_id' => $model->id]);
        $photo = $accessory->media()->create([
            'collection' => 'gallery', 'path' => 'photos/tapis.jpg',
            'disk' => 'public', 'is_cover' => true, 'position' => 0,
        ]);
        Accessory::factory()->create(['is_available' => true]);
        $inactive = Accessory::factory()->create(['is_active' => false, 'is_available' => false]);
        $inactive->fitments()->create(['vehicle_model_id' => $model->id]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson("/api/accessories?vehicle_model_id={$model->id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accessory->id)
            ->assertJsonPath('data.0.stock.is_available', false)
            ->assertJsonPath('data.0.media.0.id', $photo->id)
            ->assertJsonPath('data.0.media.0.url', $photo->url)
            ->assertJsonPath('data.0.compatibility.vehicles.0', 'Corolla');
    }

    public function test_le_personnel_peut_consulter_la_fiche_d_un_brouillon_partenaire_indisponible(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create([
            'user_id' => $partner->id, 'company_name' => 'Garage du partenaire',
            'contact_name' => 'Contact', 'phone' => $this->numeroDePartenaire($partner->id),
            'status' => 'approved',
        ]);
        $accessory = Accessory::factory()->create([
            'owner_partner_account_id' => $account->id, 'partner_published_at' => null,
            'is_available' => false, 'stock_quantity' => 5,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/accessories')->assertOk()
            ->assertJsonPath('data.0.id', $accessory->id);
        $this->getJson("/api/accessories/{$accessory->id}")->assertOk()
            ->assertJsonPath('data.id', $accessory->id)
            ->assertJsonPath('data.stock.is_available', false)
            ->assertJsonPath('data.stock.quantity', 5)
            ->assertJsonPath('data.seller.id', $account->id)
            ->assertJsonPath('data.seller.company_name', 'Garage du partenaire')
            ->assertJsonPath('data.owner_partner_account_id', $account->id);
        $this->getJson("/api/catalog/accessories/{$accessory->id}")->assertNotFound();
    }
}
