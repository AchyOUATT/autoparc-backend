<?php

namespace Tests\Feature;

use App\Enums\Transmission;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\ConsumptionReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransmissionModesTest extends TestCase
{
    use RefreshDatabase;

    public static function modes(): array
    {
        return [
            ['manual', 'Manuelle'],
            ['automatic', 'Automatique'],
            ['cvt', 'Variation continue (CVT)'],
            ['e_cvt', 'Hybride e-CVT'],
            ['automated_manual', 'Robotisée (AMT)'],
            ['dual_clutch', 'Double embrayage (DCT)'],
            ['single_speed', 'Rapport unique'],
        ];
    }

    private function vehicleFields(): array
    {
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);

        return [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2020,
            'availability' => 'sale', 'sale_price' => 4500000,
        ];
    }

    #[DataProvider('modes')]
    public function test_le_personnel_peut_enregistrer_et_afficher_chaque_type_de_boite(string $code, string $label): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $fields = $this->vehicleFields() + ['transmission' => $code];
        $id = $this->postJson('/api/vehicles', $fields)
            ->assertCreated()->assertJsonPath('data.technical.transmission', $code)->json('data.id');

        $this->assertSame($code, Vehicle::findOrFail($id)->transmission->value);
        $this->putJson('/api/vehicles/'.$id, $fields)
            ->assertOk()->assertJsonPath('data.technical.transmission', $code);
        $this->get('/v/'.$id)->assertOk()->assertSee($label);
    }

    public function test_les_references_exposent_les_sept_types_sans_modes_commerciaux(): void
    {
        $this->getJson('/api/catalog/references')->assertOk()
            ->assertJsonPath('enums.transmission', Transmission::values())
            ->assertJsonCount(7, 'enums.transmission');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/vehicles', $this->vehicleFields() + ['transmission' => 'tiptronic'])
            ->assertUnprocessable()->assertJsonValidationErrors('transmission');
        $this->assertDatabaseCount('vehicles', 0);
    }

    public static function newModes(): array
    {
        return [['automated_manual'], ['e_cvt']];
    }

    #[DataProvider('newModes')]
    public function test_un_particulier_peut_enregistrer_et_modifier_un_nouveau_type(string $code): void
    {
        config()->set('private_sales.enabled', true);
        $fields = $this->vehicleFields();
        $this->actingAsClient();
        $fields = [
            'brand_id' => $fields['brand_id'], 'vehicle_model_id' => $fields['vehicle_model_id'],
            'year' => 2020, 'mileage_km' => 80000, 'price' => 4500000,
            'city' => 'Ouagadougou', 'description' => 'Véhicule entretenu, disponible pour une visite.',
            'seller_name' => 'Vendeur', 'phone' => '+22670000000',
            'whatsapp_enabled' => true, 'transmission' => $code,
        ];
        $id = $this->postJson('/api/my/private-listings', $fields)->assertCreated()
            ->assertJsonPath('data.transmission', $code)->json('data.id');
        $this->putJson('/api/my/private-listings/'.$id, $fields)->assertOk()
            ->assertJsonPath('data.transmission', $code);
        $this->putJson('/api/my/private-listings/'.$id, array_replace($fields, ['transmission' => 'sequential']))
            ->assertUnprocessable()->assertJsonValidationErrors('transmission');
        $this->assertDatabaseHas('private_vehicle_listings', ['id' => $id, 'transmission' => $code]);
    }

    #[DataProvider('newModes')]
    public function test_les_nouveaux_types_excluent_une_reference_manuelle_sans_inventer_la_famille_de_boite(string $code): void
    {
        $references = app(ConsumptionReference::class);
        $context = ['transmission' => $code];
        $this->assertContains('transmission_mismatch', $references->contradictions(
            new Motorisation(['transmission_code' => 'M6']), $context,
        ));
        // Les codes sources ne prouvent pas une famille précise, notamment
        // AV pour CVT et e-CVT ; une identité inconnue reste inconnue.
        foreach (['AM6', 'AV', 'UNSPEC', null] as $sourceCode) {
            $this->assertSame([], $references->contradictions(
                new Motorisation(['transmission_code' => $sourceCode]), $context,
            ));
        }
    }
}
