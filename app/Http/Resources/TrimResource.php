<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'rank' => $this->rank,
            'description' => $this->description,
            'default_engine_type_id' => $this->default_engine_type_id,
            'default_drivetrain_id' => $this->default_drivetrain_id,
            'applications' => TrimApplicationResource::collection($this->whenLoaded('applications')),
        ];
    }
}
