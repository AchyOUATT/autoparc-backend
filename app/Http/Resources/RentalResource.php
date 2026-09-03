<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,
            'status'    => $this->status->value,
            'customer'  => new CustomerResource($this->whenLoaded('customer')),
            'vehicle'   => new VehicleResource($this->whenLoaded('vehicle')),
            'period'    => [
                'start_at'           => $this->start_at?->toIso8601String(),
                'expected_return_at' => $this->expected_return_at?->toIso8601String(),
                'actual_return_at'   => $this->actual_return_at?->toIso8601String(),
                'billed_days'        => $this->billed_days,
                'is_overdue'         => $this->isOverdue(),
            ],
            'with_driver' => $this->with_driver,
            'driver_name' => $this->driver_name,
            'billing'     => [
                'daily_rate'     => $this->daily_rate,
                'deposit_amount' => $this->deposit_amount,
                'extra_charges'  => $this->extra_charges,
                'total_amount'   => $this->total_amount,
                'currency'       => $this->currency,
                'payment_status' => $this->payment_status->value,
            ],
            'mileage' => [
                'start_km' => $this->mileage_start_km,
                'end_km'   => $this->mileage_end_km,
                'done_km'  => $this->mileage_done_km,
                'limit_km' => $this->mileage_limit_km,
            ],
            'pickup_location' => $this->pickup_location,
            'return_location' => $this->return_location,
        ];
    }
}
