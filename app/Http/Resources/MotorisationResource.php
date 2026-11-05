<?php

namespace App\Http\Resources;

use App\Models\Motorisation;
use App\Services\ConsumptionReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MotorisationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $references = app(ConsumptionReference::class);
        $context = $request->attributes->get('consumption_reference_context', []);
        $match = $references->assess($this->resource, $context);

        return array_merge([
            'id' => $this->id,
            'label' => $this->libelle,
            'fuel' => Motorisation::libelleCarburant($this->fuel_code),
            'fuel_code' => $this->fuel_code,
            'engine_l' => $this->engine_l === null ? null : (float) $this->engine_l,
            'cylinders' => $this->cylinders,
            'consumption' => $references->values($this->resource, $match),
            'match' => $match,
            'recommendation' => $request->attributes->get('consumption_reference_recommendations', [])[$this->id] ?? null,
            'source' => [
                'code' => $this->source,
                'label' => $this->libelle_source,
                'cycle' => $this->libelle_cycle,
                'cycle_code' => $this->cycle,
            ],
        ], $references->metadata($this->resource, $context));
    }
}
