<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\RentalStatus;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RentalFactory extends Factory
{
    protected $model = Rental::class;

    public function definition(): array
    {
        $vehicle  = Vehicle::where('availability', 'rent')
            ->orWhere('availability', 'both')
            ->inRandomOrder()
            ->first();

        $customer  = Customer::inRandomOrder()->first();
        $dailyRate = $vehicle?->rental_daily_rate ?? $this->faker->randomElement([30_000, 40_000, 50_000, 75_000, 100_000]);

        $status = $this->faker->randomElement([
            RentalStatus::Returned->value,
            RentalStatus::Returned->value,
            RentalStatus::Returned->value,
            RentalStatus::Ongoing->value,
            RentalStatus::Reserved->value,
            RentalStatus::Overdue->value,
            RentalStatus::Cancelled->value,
        ]);

        $billedDays = $this->faker->numberBetween(1, 14);

        // Pour les locations terminées : startAt assez loin dans le passé
        // pour que expectedReturn soit forcément dépassé.
        $isPast = in_array($status, [RentalStatus::Returned->value, RentalStatus::Overdue->value]);
        if ($isPast) {
            $buffer  = $billedDays + 5;          // au minimum 5 jours après la fin
            $startAt = $this->faker->dateTimeBetween('-6 months', "-{$buffer} days");
        } else {
            $startAt = $this->faker->dateTimeBetween('-2 months', 'now');
        }

        $expectedReturn = (clone $startAt)->modify("+{$billedDays} days");

        // actualReturn seulement si la location est finie, et toujours dans le passé
        $actualReturn = $isPast
            ? $this->faker->dateTimeBetween(
                $expectedReturn->format('Y-m-d H:i:s'),
                'now'
            )
            : null;

        $deposit      = $vehicle?->rental_deposit ?? $dailyRate * 3;
        $extraCharges = $status === RentalStatus::Overdue->value
            ? $this->faker->randomElement([25_000, 50_000, 75_000])
            : 0;

        $total = ($dailyRate * $billedDays) + $extraCharges;
        $total = (int) (round($total / 500) * 500);

        $mileageStart = $this->faker->numberBetween(10_000, 200_000);
        $mileageEnd   = $actualReturn
            ? $mileageStart + ($billedDays * $this->faker->numberBetween(50, 250))
            : null;

        $paymentStatus = match ($status) {
            RentalStatus::Cancelled->value => PaymentStatus::Refunded->value,
            RentalStatus::Returned->value  => PaymentStatus::Paid->value,
            default => $this->faker->randomElement([
                PaymentStatus::Pending->value,
                PaymentStatus::Partial->value,
                PaymentStatus::Paid->value,
            ]),
        };

        return [
            'reference'           => 'LOC-' . strtoupper(Str::random(8)),
            'customer_id'         => $customer?->id ?? Customer::factory()->create()->id,
            'vehicle_id'          => $vehicle?->id  ?? Vehicle::factory()->forRent()->create()->id,
            'handled_by'          => null,
            'status'              => $status,
            'with_driver'         => $this->faker->boolean(20),
            'driver_name'         => null,
            'start_at'            => $startAt->format('Y-m-d H:i:s'),
            'expected_return_at'  => $expectedReturn->format('Y-m-d H:i:s'),
            'actual_return_at'    => $actualReturn?->format('Y-m-d H:i:s'),
            'daily_rate'          => $dailyRate,
            'billed_days'         => $billedDays,
            'deposit_amount'      => $deposit,
            'deposit_returned'    => $status === RentalStatus::Returned->value && $this->faker->boolean(85),
            'extra_charges'       => $extraCharges,
            'total_amount'        => $total,
            'currency'            => 'XOF',
            'payment_status'      => $paymentStatus,
            'mileage_start_km'    => $mileageStart,
            'mileage_end_km'      => $mileageEnd,
            'mileage_limit_km'    => $vehicle?->rental_mileage_limit_per_day
                ? $vehicle->rental_mileage_limit_per_day * $billedDays
                : null,
            'extra_km_rate'       => $this->faker->randomElement([null, 200, 300, 500]),
            'fuel_level_start'    => $this->faker->randomElement([25, 50, 75, 100]),
            'fuel_level_end'      => $actualReturn
                ? $this->faker->randomElement([10, 25, 50, 75, 100])
                : null,
            'pickup_location'     => $this->faker->randomElement([
                'Agence Ouagadougou', 'Agence Bobo-Dioulasso', 'Aéroport OUA', null,
            ]),
            'return_location'     => null,
            'checkout_notes'      => null,
            'checkin_notes'       => null,
        ];
    }

    public function ongoing(): static
    {
        return $this->state(['status' => RentalStatus::Ongoing->value, 'actual_return_at' => null]);
    }

    public function returned(): static
    {
        return $this->state([
            'status'         => RentalStatus::Returned->value,
            'payment_status' => PaymentStatus::Paid->value,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(['status' => RentalStatus::Overdue->value]);
    }
}
