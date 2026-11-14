<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\PrivateVehicleListing;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GarageTwoWheelerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Brand, 1: VehicleModel} */
    private function motoRefs(): array
    {
        $brand = Brand::create(['name' => 'Yamaha', 'slug' => 'yamaha-garage', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'YBR125E',
            'slug' => 'ybr125e-garage',
            'is_active' => true,
            'default_vehicle_type' => 'two_wheeler',
        ]);

        return [$brand, $model];
    }

    public function test_un_client_enregistre_une_moto_avec_son_cadre_et_sa_configuration(): void
    {
        [$brand, $model] = $this->motoRefs();
        $configuration = [
            'body_type' => 'motorcycle',
            'stroke' => 'four_stroke',
            'cooling' => 'air',
            'starter' => 'electric',
            'drive' => 'chain',
            'transmission' => 'manual',
        ];

        $id = $this->actingAsClient(uid: 'proprio-moto')
            ->postJson('/api/my/vehicles', [
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'manufacturing_year' => 2024,
                'vehicle_type' => 'two_wheeler',
                'vin' => 'YAMAHAFRAME01',
                'two_wheeler_configuration' => $configuration,
                'nickname' => 'Ma YBR',
            ])
            ->assertCreated()
            ->assertJsonPath('data.vehicle_type', 'two_wheeler')
            ->assertJsonPath('data.vin', 'YAMAHAFRAME01')
            ->assertJsonPath('data.nickname', 'Ma YBR')
            ->assertJsonPath('data.two_wheeler_configuration.body_type', 'motorcycle')
            ->assertJsonPath('data.two_wheeler_configuration_status', 'declared')
            ->json('data.id');

        $this->actingAsClient(uid: 'proprio-moto')
            ->getJson('/api/my/vehicles/'.$id)
            ->assertOk()
            ->assertJsonPath('data.vehicle_type', 'two_wheeler')
            ->assertJsonPath('data.two_wheeler_configuration.drive', 'chain');
    }

    public function test_un_cadre_court_est_refuse_sur_une_voiture(): void
    {
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota-vin', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'Corolla',
            'slug' => 'corolla-vin',
            'is_active' => true,
        ]);

        $this->actingAsClient(uid: 'proprio-voiture')
            ->postJson('/api/my/vehicles', [
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'manufacturing_year' => 2020,
                'vehicle_type' => 'passenger',
                'vin' => 'YAMAHAFRAME01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vin');
    }

    public function test_un_tricycle_et_une_cle_inconnue_sont_refuses(): void
    {
        [$brand, $model] = $this->motoRefs();

        $this->actingAsClient(uid: 'proprio-refus')
            ->postJson('/api/my/vehicles', [
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'manufacturing_year' => 2024,
                'vehicle_type' => 'two_wheeler',
                'two_wheeler_configuration' => ['body_type' => 'tricycle'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('two_wheeler_configuration.body_type');

        $this->actingAsClient(uid: 'proprio-refus')
            ->postJson('/api/my/vehicles', [
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'manufacturing_year' => 2024,
                'vehicle_type' => 'two_wheeler',
                'two_wheeler_configuration' => ['sidecar' => 'yes'],
            ])
            ->assertUnprocessable();
    }

    public function test_une_mise_a_jour_conserve_les_cles_omises(): void
    {
        [$brand, $model] = $this->motoRefs();
        $user = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio-maj']);
        $vehicle = OwnedVehicle::create([
            'user_id' => $user->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'manufacturing_year' => 2024,
            'vehicle_type' => 'two_wheeler',
            'two_wheeler_configuration' => [
                'body_type' => 'motorcycle',
                'stroke' => 'four_stroke',
                'drive' => 'chain',
            ],
        ]);

        $this->actingAsClient($user)
            ->putJson('/api/my/vehicles/'.$vehicle->id, [
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'manufacturing_year' => 2024,
                'vehicle_type' => 'two_wheeler',
                'two_wheeler_configuration' => ['starter' => 'kick'],
            ])
            ->assertOk()
            ->assertJsonPath('data.two_wheeler_configuration.body_type', 'motorcycle')
            ->assertJsonPath('data.two_wheeler_configuration.stroke', 'four_stroke')
            ->assertJsonPath('data.two_wheeler_configuration.starter', 'kick')
            ->assertJsonPath('data.two_wheeler_configuration.drive', 'chain');
    }

    public function test_une_annonce_creee_depuis_le_garage_reprend_le_type_et_la_configuration(): void
    {
        config()->set('private_sales.enabled', true);
        [$brand, $model] = $this->motoRefs();
        $user = User::factory()->create(['role' => 'client', 'firebase_uid' => 'vendeur-moto']);
        $vehicle = OwnedVehicle::create([
            'user_id' => $user->id,
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'manufacturing_year' => 2024,
            'vehicle_type' => 'two_wheeler',
            'two_wheeler_configuration' => [
                'body_type' => 'motorcycle',
                'drive' => 'chain',
            ],
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/my/private-listings', [
                'fault_declaration_state' => 'none',
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'year' => 2024,
                'mileage_km' => 12000,
                'price' => 850000,
                'city' => 'Ouagadougou',
                'description' => 'Yamaha YBR125E entretenue, prête à rouler en ville.',
                'seller_name' => 'Vendeur particulier',
                'whatsapp_enabled' => true,
                'phone' => '+22670000000',
                'owned_vehicle_id' => $vehicle->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.vehicle_type', 'two_wheeler')
            ->assertJsonPath('data.two_wheeler_configuration.body_type', 'motorcycle')
            ->assertJsonPath('data.owned_vehicle_id', $vehicle->id);

        $this->assertDatabaseHas('private_vehicle_listings', [
            'owned_vehicle_id' => $vehicle->id,
            'vehicle_type' => 'two_wheeler',
        ]);
        $this->assertNotNull(PrivateVehicleListing::first()->two_wheeler_configuration);
    }
}
