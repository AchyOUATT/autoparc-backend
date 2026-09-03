<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\VehicleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $vehicle  = Vehicle::where('availability', 'sale')
            ->orWhere('availability', 'both')
            ->inRandomOrder()
            ->first();

        $customer = Customer::inRandomOrder()->first();
        $salePrice = $vehicle?->sale_price ?? $this->faker->numberBetween(2_000_000, 15_000_000);

        $discount  = $this->faker->randomElement([0, 0, 0, 50_000, 100_000, 200_000, 500_000]);
        $taxAmount = 0; // souvent non facturé sur le marché informel
        $regFees   = $this->faker->randomElement([0, 50_000, 75_000, 100_000, 150_000]);
        $total     = $salePrice - $discount + $taxAmount + $regFees;

        $soldAt       = $this->faker->dateTimeBetween('-12 months', 'now');
        $paymentStatus = $this->faker->randomElement([
            PaymentStatus::Paid->value,
            PaymentStatus::Paid->value,
            PaymentStatus::Paid->value,
            PaymentStatus::Partial->value,
            PaymentStatus::Pending->value,
        ]);

        return [
            'reference'        => 'VTE-' . strtoupper(Str::random(8)),
            'customer_id'      => $customer?->id ?? Customer::factory()->create()->id,
            'vehicle_id'       => $vehicle?->id  ?? Vehicle::factory()->forSale()->create()->id,
            'sold_by'          => null,
            'agreed_price'     => $salePrice,
            'discount'         => $discount,
            'tax_amount'       => $taxAmount,
            'registration_fees'=> $regFees,
            'total_amount'     => $total,
            'currency'         => 'XOF',
            'payment_status'   => $paymentStatus,
            'faults_disclosed' => $this->faker->boolean(80),
            'sold_at'          => $soldAt->format('Y-m-d'),
            'delivery_date'    => $this->faker->optional(0.6)->dateTimeBetween($soldAt, '+30 days')?->format('Y-m-d'),
            'notes'            => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(['payment_status' => PaymentStatus::Paid->value]);
    }

    public function pending(): static
    {
        return $this->state(['payment_status' => PaymentStatus::Pending->value]);
    }
}
