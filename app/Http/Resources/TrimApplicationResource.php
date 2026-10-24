<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrimApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trim_id' => $this->trim_id,
            'market' => $this->market,
            'model_year_from' => $this->model_year_from,
            'model_year_to' => $this->model_year_to,
            'sources' => $this->sources,
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
