<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklyVehicleRentalPricingTest extends TestCase
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

    private function vehicle(array $prices = []): Vehicle
    {
        return Vehicle::create(array_replace($this->identity, [
            'reference' => 'WEEKLY-'.(Vehicle::count() + 1),
            'availability' => 'rent', 'status' => 'in_stock', 'condition' => 'used',
            'registration_status' => 'registered', 'rental_weekly_rate' => 150_000,
        ], $prices));
    }

    public static function weeklyRates(): array
    {
        return ['weekly' => [150_000], 'zero remains explicit' => [0]];
    }

    #[DataProvider('weeklyRates')]
    public function test_weekly_only_rental_can_be_created_and_read_without_conversion(int $weekly): void
    {
        $response = $this->postJson('/api/vehicles', $this->identity + [
            'availability' => 'rent', 'rental_weekly_rate' => $weekly,
        ])->assertCreated()
            ->assertJsonPath('data.commercial.rental_weekly_rate', number_format($weekly, 2, '.', ''))
            ->assertJsonPath('data.commercial.rental_daily_rate', null)
            ->assertJsonPath('data.commercial.rental_monthly_rate', null)
            ->assertJsonPath('data.commercial.sale_price', null);

        $this->getJson('/api/catalog/vehicles/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.commercial.rental_weekly_rate', number_format($weekly, 2, '.', ''));
    }

    public function test_omitted_weekly_rate_survives_an_update_and_the_last_rate_cannot_be_cleared(): void
    {
        $vehicle = $this->vehicle();
        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + [
            'description' => 'Location à la semaine.',
        ])->assertOk()->assertJsonPath('data.commercial.rental_weekly_rate', '150000.00');

        $before = $vehicle->fresh()->getAttributes();
        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + [
            'rental_weekly_rate' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'rental_daily_rate', 'rental_weekly_rate', 'rental_monthly_rate',
        ]);
        $this->assertSame($before, $vehicle->fresh()->getAttributes());
    }

    public function test_replacing_daily_with_weekly_then_monthly_does_not_create_an_implicit_rate(): void
    {
        $vehicle = $this->vehicle(['rental_daily_rate' => 25_000, 'rental_weekly_rate' => null]);
        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + [
            'rental_daily_rate' => null, 'rental_weekly_rate' => 140_000,
        ])->assertOk()->assertJsonPath('data.commercial.rental_daily_rate', null)
            ->assertJsonPath('data.commercial.rental_weekly_rate', '140000.00')
            ->assertJsonPath('data.commercial.rental_monthly_rate', null);
        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + [
            'rental_weekly_rate' => null, 'rental_monthly_rate' => 500_000,
        ])->assertOk()->assertJsonPath('data.commercial.rental_weekly_rate', null)
            ->assertJsonPath('data.commercial.rental_daily_rate', null)
            ->assertJsonPath('data.commercial.rental_monthly_rate', '500000.00');
    }

    public static function invalidWeeklyRates(): array
    {
        return ['negative' => [-1], 'invalid text' => ['invalid'], 'invalid structure' => [['value' => 150000]]];
    }

    #[DataProvider('invalidWeeklyRates')]
    public function test_invalid_weekly_rate_is_rejected_even_when_a_daily_rate_exists(mixed $weekly): void
    {
        $this->postJson('/api/vehicles', $this->identity + [
            'availability' => 'rent', 'rental_daily_rate' => 25_000,
            'rental_weekly_rate' => $weekly,
        ])->assertUnprocessable()->assertJsonValidationErrors('rental_weekly_rate');
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_partner_weekly_rate_is_marked_up_once_and_keeps_the_proposed_price(): void
    {
        config()->set('partenaires.marge_pourcentage', 10);
        $user = User::factory()->create(['role' => 'partner']);
        PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Location test', 'contact_name' => 'Loueur',
            'phone' => '+22670000001', 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/partner/vehicles', $this->identity + [
            'availability' => 'rent', 'rental_weekly_rate' => 100_000,
        ])->assertCreated()->assertJsonPath('data.commercial.rental_weekly_rate', '110000.00');

        $this->patchJson('/api/partner/vehicles/'.$response->json('data.id'), $this->identity + [
            'rental_weekly_rate' => 125_000,
        ])->assertOk()->assertJsonPath('data.commercial.rental_weekly_rate', '137500.00')
            ->assertJsonPath('data.partner_pricing.prix_proposes.rental_weekly_rate', 125_000)
            ->assertJsonPath('data.partner_pricing.taux_par_champ.rental_weekly_rate', 10)
            ->assertJsonPath('data.commercial.rental_daily_rate', null)
            ->assertJsonPath('data.commercial.rental_monthly_rate', null);
    }

    public function test_weekly_only_shared_page_displays_the_period_instead_of_price_on_request(): void
    {
        $vehicle = $this->vehicle();
        $rate = '150'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'semaine';
        $html = $this->get('/v/'.$vehicle->id)->assertOk()->getContent();
        $this->assertStringContainsString($rate, $html);
        $this->assertStringContainsString('Toyota Corolla 2020 — '.$rate, $html);
        $this->assertStringNotContainsString('Prix sur demande', $html);
        $this->assertStringNotContainsString('product:price:', $html);
    }

    public function test_mixed_shared_page_keeps_sale_first_then_rental_rates_in_day_week_month_order(): void
    {
        $vehicle = $this->vehicle([
            'availability' => 'both', 'sale_price' => 4_500_000,
            'rental_daily_rate' => 25_000, 'rental_monthly_rate' => 500_000,
        ]);
        $html = $this->get('/v/'.$vehicle->id)->assertOk()->getContent();
        $sale = '4'."\u{A0}".'500'."\u{A0}".'000'."\u{A0}".'FCFA';
        $this->assertStringContainsString('Toyota Corolla 2020 — '.$sale, $html);
        $daily = strpos($html, '/'."\u{A0}".'jour');
        $weekly = strpos($html, '/'."\u{A0}".'semaine');
        $monthly = strpos($html, '/'."\u{A0}".'mois');
        $this->assertNotFalse($daily);
        $this->assertNotFalse($weekly);
        $this->assertNotFalse($monthly);
        $this->assertLessThan($weekly, $daily);
        $this->assertLessThan($monthly, $weekly);
    }

    public function test_sale_only_shared_page_does_not_offer_a_residual_weekly_rate(): void
    {
        $html = $this->get('/v/'.$this->vehicle([
            'availability' => 'sale', 'sale_price' => 4_500_000,
        ])->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('semaine', $html);
    }

    public function test_weekly_only_listing_still_requires_a_daily_rate_for_a_daily_contract(): void
    {
        $vehicle = $this->vehicle();
        $customer = Customer::factory()->individual()->create([
            'driving_licence_number' => 'PERMIS-TEST', 'driving_licence_expiry' => now()->addYear(),
            'is_blacklisted' => false,
        ]);
        $before = $vehicle->fresh()->getAttributes();
        $this->postJson('/api/rentals', [
            'vehicle_id' => $vehicle->id, 'customer_id' => $customer->id,
            'start_at' => now()->addDay()->toIso8601String(),
            'expected_return_at' => now()->addDays(8)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('daily_rate');
        $this->assertDatabaseCount('rentals', 0);
        $this->assertSame($before, $vehicle->fresh()->getAttributes());
    }
}
