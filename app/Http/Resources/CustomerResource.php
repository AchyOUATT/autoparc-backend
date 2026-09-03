<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'code'         => $this->code,
            'type'         => $this->type->value,
            'display_name' => $this->display_name,
            'first_name'   => $this->first_name,
            'last_name'    => $this->last_name,
            'company_name' => $this->company_name,
            'tax_id'       => $this->tax_id,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'address'      => $this->address,
            'city'         => $this->city,
            'country'      => $this->whenLoaded('country', fn () => $this->country?->name),
            'driving_licence' => [
                'number' => $this->driving_licence_number,
                'expiry' => $this->driving_licence_expiry?->toDateString(),
                'valid'  => $this->canRent(),
            ],
            'is_blacklisted' => $this->is_blacklisted,
            'sales_count'    => $this->whenCounted('sales'),
            'rentals_count'  => $this->whenCounted('rentals'),
        ];
    }
}
