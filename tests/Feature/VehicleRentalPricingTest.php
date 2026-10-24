<?php

namespace Tests\Feature;

use App\Enums\VehicleStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\EngineType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VehicleRentalPricingTest extends TestCase
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
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id,
            'manufacturing_year' => 2020,
        ];

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public static function validCreationPrices(): array
    {
        return [
            'journalier seul' => [['rental_daily_rate' => 25_000]],
            'mensuel seul' => [['rental_monthly_rate' => 500_000]],
            'journalier explicitement vide' => [['rental_daily_rate' => null, 'rental_monthly_rate' => 500_000]],
            'deux tarifs' => [['rental_daily_rate' => 25_000, 'rental_monthly_rate' => 500_000]],
            'vente et location mensuelle' => [[
                'availability' => 'both', 'sale_price' => 8_000_000, 'rental_monthly_rate' => 500_000,
            ]],
            'zero reste un tarif autorise' => [['rental_monthly_rate' => 0]],
            'vente sans location' => [['availability' => 'sale', 'sale_price' => 8_000_000]],
        ];
    }

    #[DataProvider('validCreationPrices')]
    public function test_vehicle_registration_accepts_daily_or_monthly_pricing(array $prices): void
    {
        $data = array_replace($this->identity, ['availability' => 'rent'], $prices);

        $response = $this->postJson('/api/vehicles', $data)->assertCreated();
        $id = $response->json('data.id');

        $this->assertDatabaseHas('vehicles', ['id' => $id] + $data);

        $public = $this->getJson('/api/catalog/vehicles/'.$id)->assertOk();

        foreach (['rental_daily_rate', 'rental_monthly_rate'] as $field) {
            $value = $prices[$field] ?? null;
            $serialized = $value === null ? null : number_format($value, 2, '.', '');
            $response->assertJsonPath('data.commercial.'.$field, $serialized);
            $public->assertJsonPath('data.commercial.'.$field, $serialized);
        }
    }

    public static function invalidCreationPrices(): array
    {
        return [
            'aucun tarif' => [[], ['rental_daily_rate', 'rental_monthly_rate']],
            'deux tarifs vides' => [[
                'rental_daily_rate' => null, 'rental_monthly_rate' => null,
            ], ['rental_daily_rate', 'rental_monthly_rate']],
            'hebdomadaire seul' => [['rental_weekly_rate' => 150_000], ['rental_daily_rate', 'rental_monthly_rate']],
            'mensuel negatif' => [['rental_monthly_rate' => -1], ['rental_monthly_rate']],
            'mensuel non numerique' => [['rental_monthly_rate' => ['incorrect']], ['rental_monthly_rate']],
            'journalier invalide meme avec mensuel' => [[
                'rental_daily_rate' => -1, 'rental_monthly_rate' => 500_000,
            ], ['rental_daily_rate']],
            'vente et location sans prix de vente' => [[
                'availability' => 'both', 'rental_monthly_rate' => 500_000,
            ], ['sale_price']],
        ];
    }

    #[DataProvider('invalidCreationPrices')]
    public function test_invalid_rental_prices_do_not_create_a_vehicle(array $prices, array $errors): void
    {
        $this->postJson('/api/vehicles', array_replace($this->identity, ['availability' => 'rent'], $prices))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('vehicles', 0);
    }

    private function vehicle(array $prices): Vehicle
    {
        return Vehicle::create(array_replace($this->identity, [
            'reference' => 'RENTAL-TEST', 'availability' => 'rent',
        ], $prices));
    }

    public static function validPriceChanges(): array
    {
        return [
            'journalier existant omis' => [
                ['rental_daily_rate' => 25_000], ['availability' => 'rent'],
            ],
            'mensuel existant omis' => [
                ['rental_monthly_rate' => 500_000], ['description' => 'Location longue durée'],
            ],
            'journalier remplace par mensuel' => [
                ['rental_daily_rate' => 25_000], ['rental_daily_rate' => null, 'rental_monthly_rate' => 500_000],
            ],
            'mensuel remplace par journalier' => [
                ['rental_monthly_rate' => 500_000], ['rental_monthly_rate' => null, 'rental_daily_rate' => 25_000],
            ],
            'mensuel conserve apres retrait journalier' => [
                ['rental_daily_rate' => 25_000, 'rental_monthly_rate' => 500_000], ['rental_daily_rate' => null],
            ],
            'vente devient location mensuelle' => [
                ['availability' => 'sale', 'sale_price' => 8_000_000],
                ['availability' => 'rent', 'rental_monthly_rate' => 500_000],
            ],
            'location devient vente sans tarifs locatifs' => [
                ['rental_monthly_rate' => 500_000],
                ['availability' => 'sale', 'sale_price' => 8_000_000, 'rental_monthly_rate' => null],
            ],
            'retrait de la location' => [
                ['rental_monthly_rate' => 500_000], ['availability' => 'none', 'rental_monthly_rate' => null],
            ],
        ];
    }

    #[DataProvider('validPriceChanges')]
    public function test_updates_validate_prices_retained_in_the_resulting_vehicle(array $prices, array $changes): void
    {
        $vehicle = $this->vehicle($prices);

        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + $changes)->assertOk();

        $expected = array_replace($prices, $changes);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id] + $expected);
    }

    public static function invalidPriceChanges(): array
    {
        return [
            'retrait du dernier journalier sans disponibilite envoyee' => [
                ['rental_daily_rate' => 25_000], ['rental_daily_rate' => null],
            ],
            'retrait du dernier mensuel' => [
                ['rental_monthly_rate' => 500_000], ['availability' => 'rent', 'rental_monthly_rate' => null],
            ],
            'retrait de tous les tarifs malgre hebdomadaire' => [
                ['rental_daily_rate' => 25_000, 'rental_monthly_rate' => 500_000, 'rental_weekly_rate' => 150_000],
                ['rental_daily_rate' => null, 'rental_monthly_rate' => null],
            ],
            'vente devient location sans tarif' => [
                ['availability' => 'sale', 'sale_price' => 8_000_000], ['availability' => 'rent'],
            ],
            'vente et location perd le dernier tarif' => [
                ['availability' => 'both', 'sale_price' => 8_000_000, 'rental_monthly_rate' => 500_000],
                ['rental_monthly_rate' => null],
            ],
        ];
    }

    #[DataProvider('invalidPriceChanges')]
    public function test_updates_cannot_leave_a_rental_vehicle_without_daily_or_monthly_price(array $prices, array $changes): void
    {
        $vehicle = $this->vehicle($prices);
        $before = $vehicle->fresh()->getAttributes();

        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->identity + $changes)
            ->assertUnprocessable()->assertJsonValidationErrors(['rental_daily_rate', 'rental_monthly_rate']);

        $this->assertSame($before, $vehicle->fresh()->getAttributes());
    }

    private function reservationPayload(Vehicle $vehicle): array
    {
        $customer = Customer::factory()->individual()->create([
            'driving_licence_number' => 'PERMIS-TEST',
            'driving_licence_expiry' => now()->addYear(),
            'is_blacklisted' => false,
        ]);

        return [
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
            'start_at' => '2026-11-01 10:00:00',
            'expected_return_at' => '2026-11-03 10:00:00',
        ];
    }

    public function test_monthly_only_vehicle_requires_an_explicit_daily_rate_for_daily_contracts(): void
    {
        $vehicle = $this->vehicle(['rental_monthly_rate' => 500_000]);
        $before = $vehicle->fresh()->getAttributes();

        $this->postJson('/api/rentals', $this->reservationPayload($vehicle))
            ->assertUnprocessable()->assertJsonValidationErrors('daily_rate');

        $this->assertDatabaseCount('rentals', 0);
        $this->assertSame($before, $vehicle->fresh()->getAttributes());
    }

    public static function dailyReservationRates(): array
    {
        return [
            'tarif journalier du vehicule' => [['rental_daily_rate' => 25_000], []],
            'journalier explicite pour vehicule mensuel' => [['rental_monthly_rate' => 500_000], ['daily_rate' => 25_000]],
        ];
    }

    #[DataProvider('dailyReservationRates')]
    public function test_daily_contracts_keep_their_existing_calculation(array $prices, array $overrides): void
    {
        $vehicle = $this->vehicle($prices);
        $data = $this->reservationPayload($vehicle) + $overrides;

        $this->postJson('/api/rentals', $data)->assertCreated()
            ->assertJsonPath('data.period.billed_days', 2)
            ->assertJsonPath('data.billing.daily_rate', '25000.00')
            ->assertJsonPath('data.billing.total_amount', '50000.00');

        $this->assertSame(VehicleStatus::Reserved, $vehicle->fresh()->status);
    }
}
