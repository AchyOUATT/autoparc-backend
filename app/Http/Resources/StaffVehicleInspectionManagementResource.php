<?php

namespace App\Http\Resources;

use App\Services\StaffVehicleInspections;
use Illuminate\Http\Request;

class StaffVehicleInspectionManagementResource extends StaffVehicleInspectionResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'state' => $this->status === 'active' && (
                ($this->valid_until !== null && ! $this->valid_until->isFuture())
                || ($this->resource->inspectionTarget !== null && ! StaffVehicleInspections::isCurrent($this->resource, $this->resource->inspectionTarget))
            ) ? 'outdated' : $this->status,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'withdrawal_reason' => $this->withdrawal_reason,
            'withdrawn_by' => $this->whenLoaded('withdrawnBy', fn () => $this->withdrawnBy ? ['id' => $this->withdrawnBy->id, 'name' => $this->withdrawnBy->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
