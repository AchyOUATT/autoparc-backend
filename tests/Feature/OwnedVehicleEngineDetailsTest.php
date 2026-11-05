<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnedVehicleEngineDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $this->actingAsClient(User::factory()->create(['role' => 'client', 'firebase_uid' => 'engine-details']));

        return ['brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'manufacturing_year' => 2015];
    }

    public function test_details_can_be_saved_read_changed_preserved_and_cleared(): void
    {
        $details = ['engine_displacement_cc' => 1798, 'engine_displacement_l' => 1.8,
            'cylinders' => 4, 'engine_layout' => 'inline', 'power_hp' => 140, 'engine_code' => ' 2zr-fe '];
        $response = $this->postJson('/api/my/vehicles', $this->payload() + $details)->assertCreated()
            ->assertJsonPath('data.engine_displacement_cc', 1798)
            ->assertJsonPath('data.engine_displacement_l', '1.800')
            ->assertJsonPath('data.cylinders', 4)->assertJsonPath('data.engine_layout', 'inline')
            ->assertJsonPath('data.power_hp', 140)->assertJsonPath('data.engine_code', '2ZR-FE');
        $url = '/api/my/vehicles/'.$response->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.engine_displacement_cc', 1798);
        $this->patchJson($url, ['nickname' => 'Ma voiture'])->assertOk()
            ->assertJsonPath('data.cylinders', 4)->assertJsonPath('data.engine_displacement_l', '1.800');
        $this->patchJson($url, ['cylinders' => 6, 'engine_layout' => 'v'])->assertOk()
            ->assertJsonPath('data.cylinders', 6)->assertJsonPath('data.engine_layout', 'v');
        $this->patchJson($url, array_fill_keys(array_keys($details), null))->assertOk()
            ->assertJsonPath('data.engine_displacement_cc', null)->assertJsonPath('data.engine_displacement_l', null)
            ->assertJsonPath('data.cylinders', null)->assertJsonPath('data.engine_layout', null)
            ->assertJsonPath('data.power_hp', null)->assertJsonPath('data.engine_code', null);
    }

    public function test_litres_do_not_invent_exact_cc_or_confirm_compatibility(): void
    {
        $this->postJson('/api/my/vehicles', $this->payload() + [
            'engine_displacement_l' => 1.6, 'cylinders' => 4, 'engine_layout' => 'v', 'power_hp' => 100,
        ])->assertCreated()->assertJsonPath('data.engine_displacement_l', '1.600')
            ->assertJsonPath('data.engine_displacement_cc', null)
            ->assertJsonPath('data.engine_code', null)->assertJsonPath('data.compatibility_ready', false);
    }

    public function test_all_details_are_optional(): void
    {
        $this->postJson('/api/my/vehicles', $this->payload())->assertCreated()
            ->assertJsonPath('data.engine_displacement_cc', null)->assertJsonPath('data.engine_displacement_l', null)
            ->assertJsonPath('data.cylinders', null)->assertJsonPath('data.engine_layout', null)->assertJsonPath('data.power_hp', null);
    }

    public function test_invalid_details_are_rejected_without_partial_updates(): void
    {
        $response = $this->postJson('/api/my/vehicles', $this->payload())->assertCreated();
        $url = '/api/my/vehicles/'.$response->json('data.id');
        foreach ([['engine_displacement_cc', 1.8], ['engine_displacement_cc', 30001],
            ['engine_displacement_l', 0], ['engine_displacement_l', 30.001], ['engine_displacement_l', 1.2345],
            ['cylinders', 0], ['cylinders', 25], ['cylinders', 4.5], ['engine_layout', 'V6'],
            ['power_hp', -1], ['power_hp', 5001], ['power_hp', 120.5]] as [$field, $value]) {
            $this->patchJson($url, [$field => $value, 'nickname' => 'Invalid update'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('data.nickname', null);
    }

    public function test_another_owner_cannot_edit_engine_details(): void
    {
        $response = $this->postJson('/api/my/vehicles', $this->payload())->assertCreated();
        $this->actingAsClient(User::factory()->create(['role' => 'client', 'firebase_uid' => 'other-owner']));
        $this->patchJson('/api/my/vehicles/'.$response->json('data.id'), ['cylinders' => 8])->assertForbidden();
    }
}
