<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Feature;
use App\Models\PartnerAccount;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Contract used by the dedicated, reduced rental registration form. */
class RentalVehicleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->identity = [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2020,
        ];

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_minimal_rental_registration_does_not_require_sale_or_import_information(): void
    {
        $response = $this->postJson('/api/vehicles', $this->identity + [
            'availability' => 'rent', 'rental_monthly_rate' => 450_000,
        ])->assertCreated()
            ->assertJsonPath('data.commercial.availability', 'rent')
            ->assertJsonPath('data.commercial.sale_price', null)
            ->assertJsonPath('data.commercial.rental_monthly_rate', '450000.00')
            ->assertJsonPath('data.technical.mileage_km', null);

        $vehicle = Vehicle::findOrFail($response->json('data.id'));
        $this->assertNull($vehicle->vin);
        $this->assertNull($vehicle->purchase_price);
        $this->assertNull($vehicle->trim_id);
        $this->assertNull($vehicle->registrationDetail);
        $this->assertNull($vehicle->importDetail);
        $this->assertSame('unregistered', $vehicle->registration_status->value);
        $this->assertSame('in_stock', $vehicle->status->value);
        $this->assertSame('unknown', $vehicle->fault_declaration_state);
    }

    public function test_reduced_rental_edit_preserves_fields_that_are_not_in_the_rental_form(): void
    {
        $trim = Trim::create([
            'vehicle_model_id' => $this->identity['vehicle_model_id'], 'name' => 'LE',
        ]);
        $vehicle = Vehicle::create($this->identity + [
            'reference' => 'RENTAL-EXISTING', 'registration_status' => 'registered',
            'availability' => 'rent', 'rental_daily_rate' => 20_000,
            'rental_weekly_rate' => 120_000, 'rental_mileage_limit_per_day' => 150,
            'purchase_price' => 3_000_000, 'sale_price' => 5_000_000,
            'trim_id' => $trim->id, 'vin' => 'JTDBR32E530123456',
            'status' => 'rented', 'condition' => 'used',
            'engine_code' => '1ZZ-FE', 'power_hp' => 130,
            'engine_displacement_cc' => 1794, 'gear_count' => 5,
            'doors' => 4, 'site' => 'Agence centrale',
            'fault_declaration_state' => 'has_faults', 'fault_declared_at' => now(),
        ]);
        $vehicle->registrationDetail()->create([
            'plate_number' => 'BF-1234', 'mileage_km' => 65_000,
            'insurance_company' => 'Assureur', 'insurance_expiry' => now()->addYear(),
        ]);
        $vehicle->faults()->create([
            'category' => 'bodywork', 'title' => 'Rayure déclarée',
            'description' => 'Déclaration existante hors du formulaire simplifié.',
            'severity' => 'minor', 'status' => 'declared',
        ]);
        $feature = Feature::create(['name' => 'Climatisation', 'category' => 'comfort']);
        $vehicle->features()->attach($feature);

        $before = $vehicle->fresh()->getAttributes();
        $registration = $this->rows('vehicle_registration_details', 'vehicle_id', $vehicle->id);
        $faults = $this->rows('vehicle_faults', 'vehicle_id', $vehicle->id);
        $features = $vehicle->features()->pluck('features.id')->all();

        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + [
            'availability' => 'rent', 'rental_daily_rate' => 25_000,
            'rental_monthly_rate' => 450_000, 'description' => 'Location courte ou longue durée.',
        ])->assertOk();

        $after = $vehicle->fresh()->getAttributes();
        foreach (['rental_daily_rate', 'rental_monthly_rate', 'description', 'updated_at'] as $changed) {
            unset($before[$changed], $after[$changed]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($registration, $this->rows('vehicle_registration_details', 'vehicle_id', $vehicle->id));
        $this->assertSame($faults, $this->rows('vehicle_faults', 'vehicle_id', $vehicle->id));
        $this->assertSame($features, $vehicle->features()->pluck('features.id')->all());
    }

    public function test_rental_price_edit_preserves_an_existing_mixed_sale_offer(): void
    {
        $vehicle = Vehicle::create($this->identity + [
            'reference' => 'RENTAL-MIXED', 'availability' => 'both',
            'sale_price' => 5_000_000, 'purchase_price' => 3_000_000,
            'rental_daily_rate' => 20_000, 'deal_type' => 'good_deal',
        ]);

        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + [
            'rental_daily_rate' => 25_000,
        ])->assertOk()
            ->assertJsonPath('data.commercial.availability', 'both')
            ->assertJsonPath('data.commercial.sale_price', '5000000.00');

        $this->assertSame('both', $vehicle->fresh()->availability->value);
        $this->assertSame('5000000.00', $vehicle->fresh()->sale_price);
        $this->assertSame('3000000.00', $vehicle->fresh()->purchase_price);
        $this->assertSame('good_deal', $vehicle->fresh()->deal_type->value);
    }

    public function test_partner_can_register_a_minimal_rental_without_staff_location_fields(): void
    {
        config()->set('partenaires.marge_pourcentage', 10);
        $user = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Loueur',
            'contact_name' => 'Responsable', 'phone' => '+22670000001', 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/partner/vehicles', $this->identity + [
            'availability' => 'rent', 'rental_daily_rate' => 20_000,
            'rental_deposit' => 50_000, 'transmission' => 'automatic', 'seats' => 5,
        ])->assertCreated();

        $vehicle = Vehicle::findOrFail($response->json('data.id'));
        $this->assertSame($account->id, $vehicle->owner_partner_account_id);
        $this->assertSame('rent', $vehicle->availability->value);
        $this->assertSame('22000.00', $vehicle->rental_daily_rate);
        $this->assertSame('50000.00', $vehicle->rental_deposit);
        $this->assertNull($vehicle->sale_price);
        $this->assertNull($vehicle->partner_published_at);
        $this->assertNull($vehicle->location_id);

        $this->postJson('/api/partner/vehicles', $this->identity + [
            'availability' => 'rent', 'rental_daily_rate' => 20_000, 'location_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    private function rows(string $table, string $key, int $id): array
    {
        return DB::table($table)->where($key, $id)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
    }
}
