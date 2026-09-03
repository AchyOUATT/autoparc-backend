<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Part;
use App\Models\PartOrder;
use App\Models\PartOrderItem;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class CommerceSeeder extends Seeder
{
    public function run(): void
    {
        $customers = Customer::all();
        $vehicles = Vehicle::all();
        $parts = Part::all();
        $staff = User::whereIn('role', ['sales', 'manager'])->get();
        $seller = $staff->first() ?? User::first();

        $paidCase = collect(PaymentStatus::cases())
            ->first(fn ($c) => str_contains(strtolower($c->name), 'paid'))
            ?? PaymentStatus::cases()[0];
        $pendingCase = collect(PaymentStatus::cases())
            ->first(fn ($c) => str_contains(strtolower($c->name), 'pending'))
            ?? PaymentStatus::cases()[0];

        // ---- Ventes ----
        foreach (range(1, 3) as $i) {
            $vehicle = $vehicles->random();
            $price = $vehicle->sale_price ?? 5000000;

            $sale = Sale::create([
                'reference' => 'SALE-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'customer_id' => $customers->random()->id,
                'vehicle_id' => $vehicle->id,
                'sold_by' => $seller?->id,
                'agreed_price' => $price,
                'discount' => 0,
                'tax_amount' => 0,
                'registration_fees' => 50000,
                'total_amount' => $price + 50000,
                'currency' => 'XOF',
                'payment_status' => $i === 1 ? $paidCase->value : $pendingCase->value,
                'faults_disclosed' => true,
                'sold_at' => now()->subDays($i * 5),
            ]);

            if ($i === 1) {
                Payment::create([
                    'payable_type' => Sale::class,
                    'payable_id' => $sale->id,
                    'reference' => 'PAY-SALE-'.$sale->id,
                    'amount' => $sale->total_amount,
                    'currency' => 'XOF',
                    'method' => collect(PaymentMethod::cases())->random()->value,
                    'paid_at' => now()->subDays($i * 5),
                    'received_by' => $seller?->id,
                ]);
            }
        }

        // ---- Locations ----
        $rentalStatuses = \App\Enums\RentalStatus::cases();
        foreach (range(1, 4) as $i) {
            $vehicle = $vehicles->random();
            $dailyRate = $vehicle->rental_daily_rate ?? 20000;
            $days = random_int(2, 7);

            Rental::create([
                'reference' => 'RENT-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'customer_id' => $customers->random()->id,
                'vehicle_id' => $vehicle->id,
                'handled_by' => $seller?->id,
                'status' => collect($rentalStatuses)->random()->value,
                'with_driver' => (bool) random_int(0, 1),
                'start_at' => now()->addDays($i),
                'expected_return_at' => now()->addDays($i + $days),
                'daily_rate' => $dailyRate,
                'billed_days' => $days,
                'deposit_amount' => $vehicle->rental_deposit ?? 150000,
                'total_amount' => $dailyRate * $days,
                'currency' => 'XOF',
                'payment_status' => $pendingCase->value,
                'mileage_limit_km' => 200,
                'pickup_location' => 'Agence Ouaga 2000',
            ]);
        }

        // ---- Commandes de pièces ----
        foreach (range(1, 3) as $i) {
            $order = PartOrder::create([
                'reference' => 'PORD-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'customer_id' => $customers->random()->id,
                'created_by' => $seller?->id,
                'payment_status' => $pendingCase->value,
                'ordered_at' => now()->subDays($i),
            ]);

            $subtotal = 0;
            foreach ($parts->random(min(3, $parts->count())) as $part) {
                $qty = random_int(1, 3);
                $lineTotal = $part->selling_price * $qty;
                $subtotal += $lineTotal;

                PartOrderItem::create([
                    'part_order_id' => $order->id,
                    'part_id' => $part->id,
                    'designation' => $part->name,
                    'oem_reference' => $part->manufacturer_reference,
                    'quantity' => $qty,
                    'unit_price' => $part->selling_price,
                    'discount' => 0,
                    'line_total' => $lineTotal,
                ]);
            }

            $order->update([
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
            ]);
        }
    }
}
