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
            // Liens qualifies (oem_number_relations) : nature et preuve du
            // REMPLACEMENT, distinctes de la preuve des compatibilites.
            'replaced_by'       => $this->whenLoaded('replacementRelations', fn () => $this->replacementRelations->map(fn ($r) => [
                'number'         => $r->to?->number,
                'kind'           => $r->kind,
                'evidence_level' => $r->evidence_level,
                'sources'        => $r->sources ?? [],
                'verified_on'    => $r->verified_on?->toDateString(),
            ])->values()),
            'replaces'          => $this->whenLoaded('replacedRelations', fn () => $this->replacedRelations->map(fn ($r) => [
                'number'         => $r->from?->number,
                'kind'           => $r->kind,
                'evidence_level' => $r->evidence_level,
                'sources'        => $r->sources ?? [],
                'verified_on'    => $r->verified_on?->toDateString(),
            ])->values()),
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
