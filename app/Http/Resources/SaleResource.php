<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'reference' => $this->reference,
            'customer'  => new CustomerResource($this->whenLoaded('customer')),
            'vehicle'   => new VehicleResource($this->whenLoaded('vehicle')),
            'amounts'   => [
                'agreed_price'      => $this->agreed_price,
                'discount'          => $this->discount,
                'tax_amount'        => $this->tax_amount,
                'registration_fees' => $this->registration_fees,
                'total_amount'      => $this->total_amount,
                'paid_amount'       => $this->paid_amount,
                'balance'           => $this->balance,
                'currency'          => $this->currency,
                'payment_status'    => $this->payment_status->value,
            ],
            'faults_disclosed' => $this->faults_disclosed,
            'sold_at'          => $this->sold_at?->toDateString(),
            'delivery_date'    => $this->delivery_date?->toDateString(),
            'notes'            => $this->notes,
        ];
    }
}
