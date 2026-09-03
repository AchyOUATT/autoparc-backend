<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OemNumberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'number'            => $this->number,
            'normalized_number' => $this->normalized_number,
            'label'             => $this->label,
            'brand'             => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'is_superseded'     => $this->is_superseded,
            'superseded_by'     => $this->whenLoaded('supersededBy', fn () => $this->supersededBy?->number),
            'is_primary'        => $this->whenPivotLoaded('oem_number_part', fn () => (bool) $this->pivot->is_primary),
            'fitments'          => $this->whenLoaded('fitments', fn () => $this->fitments->map(fn ($f) => [
                'vehicle_model_id' => $f->vehicle_model_id,
                'vehicle_model'    => $f->vehicleModel?->full_name,
                'trim'             => $f->trim?->name,
                'engine_type'      => $f->engineType?->label,
                'engine_code'      => $f->engine_code,
                'year_from'        => $f->year_from,
                'year_to'          => $f->year_to,
                'position'         => $f->position,
            ])),
        ];
    }
}
