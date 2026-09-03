<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessoryOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'status'         => $this->status?->value,
            'payment_status' => $this->payment_status?->value,

            'customer' => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer->id,
                'name' => $this->customer->name,
            ]),

            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle
                ? ['id' => $this->vehicle->id, 'reference' => $this->vehicle->reference]
                : null
            ),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'           => $item->id,
                'accessory_id' => $item->accessory_id,
                'sku'          => $item->accessory?->sku,
                'designation'  => $item->designation,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->unit_price,
                'discount'     => $item->discount,
                'line_total'   => $item->line_total,
            ])),

            'totals' => [
                'subtotal'     => $this->subtotal,
                'discount'     => $this->discount,
                'tax_amount'   => $this->tax_amount,
                'total_amount' => $this->total_amount,
                'currency'     => $this->currency,
            ],

            'ordered_at'   => $this->ordered_at?->toDateString(),
            'delivered_at' => $this->delivered_at?->toDateString(),
            'notes'        => $this->notes,
        ];
    }
}
