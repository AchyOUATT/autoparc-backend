<?php

namespace App\Http\Resources;

use App\Services\StaffVehicleInspections;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffVehicleInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Shared public report allowlist. No author identity or seller contact is exposed here.
        return StaffVehicleInspections::summaryFields($this->resource) + [
            'source' => $this->source(), 'source_id' => $this->sourceId(),
            'summary' => $this->summary, 'items' => $this->items,
        ];
    }
}
