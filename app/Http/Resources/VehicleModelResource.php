<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleModelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'generation' => $this->generation,
            'body_type'  => $this->body_type,
            'segment'    => $this->segment,
            // Rendu explicitement : la route des modeles filtre desormais les
            // lignes desactivees, mais d'autres consommateurs (administration,
            // `active_only=0`) recoivent l'inventaire complet et doivent
            // pouvoir distinguer les deux. Sans ce champ, un client ne peut
            // pas savoir si une ligne est proposable.
            'is_active'  => (bool) $this->is_active,
            'production' => [
                'from' => $this->production_start,
                'to'   => $this->production_end,
            ],
            'brand' => $this->whenLoaded('brand', fn () => [
                'id'   => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'trims' => TrimResource::collection($this->whenLoaded('trims')),
        ];
    }
}
