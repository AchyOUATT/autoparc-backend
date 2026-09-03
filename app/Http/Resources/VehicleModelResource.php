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
