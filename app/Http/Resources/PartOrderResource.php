<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'status'         => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'customer'       => new CustomerResource($this->whenLoaded('customer')),
            'vehicle'        => $this->whenLoaded('vehicle', fn () => $this->vehicle?->reference),
            'amounts'        => [
                'subtotal'     => $this->subtotal,
                'discount'     => $this->discount,
                'tax_amount'   => $this->tax_amount,
                'total_amount' => $this->total_amount,
                'currency'     => $this->currency,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id'            => $i->id,
                'part_id'       => $i->part_id,
                'designation'   => $i->designation,
                'oem_reference' => $i->oem_reference,
                'quantity'      => $i->quantity,
                'unit_price'    => $i->unit_price,
                'discount'      => $i->discount,
                'line_total'    => $i->line_total,
            ])),
            'ordered_at'   => $this->ordered_at?->toDateString(),
            'delivered_at' => $this->delivered_at?->toDateString(),
        ];
    }
}
