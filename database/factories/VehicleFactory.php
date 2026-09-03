<?php

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Trim;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        // Pioche une marque + modèle + finition cohérents
        $trim  = Trim::with('vehicleModel.brand')->inRandomOrder()->first();
        $model = $trim?->vehicleModel;
        $brand = $model?->brand;

        // Fallbacks si la table est vide
        if (! $trim || ! $model || ! $brand) {
            $brand = Brand::inRandomOrder()->firstOrFail();
            $model = VehicleModel::where('brand_id', $brand->id)->inRandomOrder()->firstOrFail();
            $trim  = null;
        }

        $condition    = $this->faker->randomElement(['used', 'used', 'used', 'new', 'used', 'damaged']);
        $availability = $this->faker->randomElement(['sale', 'sale', 'rent', 'both', 'sale']);
        $year         = $this->faker->numberBetween(
            max($model->production_start ?? 2005, 2003),
            min($model->production_end   ?? 2024, 2024)
        );

        $engineType = $trim?->defaultEngineType
            ?? EngineType::inRandomOrder()->first();
        $drivetrain = $trim?->defaultDrivetrain
            ?? Drivetrain::inRandomOrder()->first();
        $color      = Color::inRandomOrder()->first();

        // Prix cohérents avec le marché UEMOA (XOF)
        $basePrice   = $this->xofPrice($condition, $year);
        $salePrice   = in_array($availability, ['sale', 'both']) ? $basePrice : null;
        $dailyRate   = in_array($availability, ['rent', 'both'])
            ? $this->faker->randomElement([25_000, 30_000, 35_000, 40_000, 50_000, 60_000, 75_000, 100_000, 125_000, 150_000])
            : null;

        $transmission = $trim
            ? $this->faker->randomElement([
                Transmission::Automatic->value,
                Transmission::Manual->value,
                Transmission::Cvt->value,
            ])
            : Transmission::Automatic->value;

        return [
            'reference'              => 'VH-' . strtoupper(Str::random(8)),
            'vin'                    => null,
            'registration_status'    => $this->faker->randomElement([
                RegistrationStatus::Unregistered->value,
                RegistrationStatus::Unregistered->value,
                RegistrationStatus::Registered->value,
            ]),
            'brand_id'               => $brand->id,
            'vehicle_model_id'       => $model->id,
            'trim_id'                => $trim?->id,
            'engine_type_id'         => $engineType->id,
            'drivetrain_id'          => $drivetrain?->id,
            'color_id'               => $color?->id,
            'manufacturing_year'     => $year,
            'transmission'           => $transmission,
            'gear_count'             => $transmission === Transmission::Manual->value ? $this->faker->randomElement([5, 6]) : null,
            'engine_displacement_cc' => $trim ? null : $this->faker->randomElement([1000, 1200, 1500, 1600, 1800, 2000, 2400, 3000]),
            'power_hp'               => $trim ? null : $this->faker->randomElement([75, 90, 110, 130, 150, 170, 200, 250]),
            'power_kw'               => null,
            'torque_nm'              => null,
            'cylinders'              => $trim ? null : $this->faker->randomElement([3, 4, 6]),
            'seats'                  => $this->faker->randomElement([5, 5, 5, 7, 7, 2]),
            'doors'                  => $this->faker->randomElement([4, 4, 5, 2]),
            'engine_code'            => null,
            'consumption_urban'      => null,
            'consumption_extra_urban'=> null,
            'consumption_combined'   => null,
            'electric_consumption_kwh'=> null,
            'battery_capacity_kwh'   => null,
            'electric_range_km'      => null,
            'co2_g_km'               => null,
            'fuel_tank_liters'       => null,
            'body_style'             => $this->bodyStyleFromModel($model),
            'condition'              => $condition,
            'status'                 => VehicleStatus::InStock->value,
            'availability'           => $availability,
            'purchase_price'         => (int) ($basePrice * 0.82),
            'sale_price'             => $salePrice,
            'rental_daily_rate'      => $dailyRate,
            'rental_weekly_rate'     => $dailyRate ? (int) ($dailyRate * 6) : null,
            'rental_monthly_rate'    => $dailyRate ? (int) ($dailyRate * 22) : null,
            'rental_deposit'         => $dailyRate ? (int) ($dailyRate * 5) : null,
            'rental_mileage_limit_per_day' => $dailyRate ? $this->faker->randomElement([150, 200, 250, 300]) : null,
            'currency'               => 'XOF',
            'price_negotiable'       => true,
            'site'                   => $this->faker->randomElement(['Ouagadougou', 'Bobo-Dioulasso', 'Koudougou', null]),
            'description'            => null,
            'created_by'             => null,
            'published_at'           => now()->subDays($this->faker->numberBetween(0, 90)),
        ];
    }

    // ── États cohérents ────────────────────────────────────────────────────

    public function forSale(): static
    {
        return $this->state(['availability' => VehicleAvailability::Sale->value, 'rental_daily_rate' => null]);
    }

    public function forRent(): static
    {
        return $this->state(fn(array $a) => [
            'availability'        => VehicleAvailability::Rent->value,
            'sale_price'          => null,
            'rental_daily_rate'   => $a['rental_daily_rate'] ?? 50_000,
        ]);
    }

    public function newVehicle(): static
    {
        return $this->state([
            'condition'           => VehicleCondition::New->value,
            'registration_status' => RegistrationStatus::Unregistered->value,
        ]);
    }

    public function imported(): static
    {
        return $this->state(['registration_status' => RegistrationStatus::Unregistered->value]);
    }

    public function registered(): static
    {
        return $this->state(['registration_status' => RegistrationStatus::Registered->value]);
    }

    // ── Aide carrosserie ──────────────────────────────────────────────────

    private function bodyStyleFromModel(?\App\Models\VehicleModel $model): ?string
    {
        $bt = strtolower($model?->body_type ?? '');
        return match (true) {
            $bt === 'berline'                          => 'sedan',
            in_array($bt, ['citadine', 'compacte'])    => 'hatchback',
            $bt === 'suv'                              => 'suv',
            $bt === 'pick-up'                          => 'pickup',
            in_array($bt, ['utilitaire', 'monospace']) => 'van',
            $bt === 'break'                            => 'estate',
            str_contains($bt, 'tout')                  => 'suv',
            default                                    => null,
        };
    }

    // ── Aide prix XOF ─────────────────────────────────────────────────────

    private function xofPrice(string $condition, int $year): int
    {
        $age = 2025 - $year;

        $base = match (true) {
            $condition === 'new'      => $this->faker->numberBetween(9_000_000, 35_000_000),
            $age <= 3                 => $this->faker->numberBetween(5_000_000, 15_000_000),
            $age <= 7                 => $this->faker->numberBetween(3_000_000, 9_000_000),
            $age <= 12                => $this->faker->numberBetween(1_500_000, 5_000_000),
            $condition === 'damaged'  => $this->faker->numberBetween(300_000, 1_500_000),
            default                   => $this->faker->numberBetween(800_000, 3_000_000),
        };

        // Arrondi au 50 000 le plus proche — réaliste marché local
        return (int) (round($base / 50_000) * 50_000);
    }
}
