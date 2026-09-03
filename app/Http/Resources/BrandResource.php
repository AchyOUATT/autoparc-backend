<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'logo_path'  => $this->logo_path,
            'country'    => $this->whenLoaded('country', fn () => [
                'id'   => $this->country->id,
                'name' => $this->country->name,
            ]),
            'models_count' => $this->whenCounted('vehicleModels'),
            'models'       => VehicleModelResource::collection($this->whenLoaded('vehicleModels')),
        ];
    }
}
