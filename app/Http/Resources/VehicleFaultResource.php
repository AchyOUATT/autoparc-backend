<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleFaultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'code'                    => $this->code,
            'category'                => $this->category->value,
            'title'                   => $this->title,
            'description'             => $this->description,
            'severity'                => $this->severity->value,
            'severity_weight'         => $this->severity->weight(),
            'status'                  => $this->status->value,
            'is_open'                 => $this->status->isOpen(),
            'affects_drivability'     => $this->affects_drivability,
            'is_safety_critical'      => $this->is_safety_critical,
            'disclosed_to_buyer'      => $this->disclosed_to_buyer,
            'detected_at'             => $this->detected_at?->toDateString(),
            'mileage_at_detection_km' => $this->mileage_at_detection_km,
            'estimated_repair_cost'   => $this->estimated_repair_cost,
            'actual_repair_cost'      => $this->actual_repair_cost,
            'repaired_at'             => $this->repaired_at?->toDateString(),
            'required_parts'          => PartResource::collection($this->whenLoaded('parts')),
        ];
    }
}
