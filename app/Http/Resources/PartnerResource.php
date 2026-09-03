<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'phone'        => $this->phone,
            'whatsapp'     => $this->whatsapp,
            'email'        => $this->email,
            'notes'        => $this->notes,
            'is_active'    => $this->is_active,
            // Données du pivot (présentes quand chargé via morphToMany)
            'role'         => $this->whenPivotLoaded('partnerables', fn () => $this->pivot->role),
            'pivot_notes'  => $this->whenPivotLoaded('partnerables', fn () => $this->pivot->notes),
            'created_at'   => $this->created_at?->toDateString(),
        ];
    }
}
