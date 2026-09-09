<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnedVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'designation'      => $this->designation,
            'nickname'         => $this->nickname,
            'brand_id'         => $this->brand_id,
            'vehicle_model_id' => $this->vehicle_model_id,
            'vin'          => $this->vin,
            'engine_code'  => $this->engine_code,
            'plate_number' => $this->plate_number,
            'mileage_km'  => $this->mileage_km,
            'year'        => $this->manufacturing_year,

            // Echeances d'entretien. Les champs bruts servent au formulaire,
            // `deadlines` a l'affichage : c'est la meme liste, calculee au meme
            // endroit que celle utilisee par la tache de rappel.
            'technical_inspection_expiry' => $this->technical_inspection_expiry?->toDateString(),
            'insurance_expiry'            => $this->insurance_expiry?->toDateString(),
            'last_service_date'           => $this->last_service_date?->toDateString(),
            'last_service_mileage_km'     => $this->last_service_mileage_km,
            'service_interval_km'         => $this->service_interval_km,
            'deadlines'                   => $this->deadlines(),

            'identity' => [
                'brand'      => $this->whenLoaded('brand', fn () => $this->brand->name),
                'model'      => $this->whenLoaded('vehicleModel', fn () => $this->vehicleModel->name),
                'trim'       => $this->whenLoaded('trim', fn () => $this->trim?->name),
                'engine_type' => $this->whenLoaded('engineType', fn () => $this->engineType?->label),
                'drivetrain' => $this->whenLoaded('drivetrain', fn () => $this->drivetrain?->code),
                'color'      => $this->whenLoaded('color', fn () => $this->color?->name),
            ],

            // Indique si le calcul de compatibilite dispose d'une motorisation
            // (saisie ou deduite de la finition) pour etre precis.
            'compatibility_ready' => $this->hasPreciseEngineData(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
