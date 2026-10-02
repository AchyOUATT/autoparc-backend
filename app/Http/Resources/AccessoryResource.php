<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class AccessoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'sku'         => $this->sku,
            'name'        => $this->name,
            'description' => $this->description,

            'category' => [
                'value' => $this->category->value,
                'label' => $this->category->label(),
            ],

            'manufacturer'    => $this->whenLoaded('manufacturer', fn () => $this->manufacturer?->name),
            'manufacturer_id' => $this->manufacturer_id,
            'weight_kg'       => $this->weight_kg,
            'dimensions'      => $this->dimensions,
            'warranty_months' => $this->warranty_months,

            'pricing' => [
                'selling_price'       => $this->selling_price,
                'currency'            => $this->currency,
                'vat_rate'            => $this->vat_rate,
                'price_including_vat' => $this->price_including_vat,
            ],

            'stock' => [
                'is_available'    => $this->is_available,
                'quantity'        => $this->stock_quantity,
                'alert_threshold' => $this->stock_alert_threshold,
                'is_low'          => $this->isLowStock(),
                'storage_location'=> $this->storage_location,
                'location_id'     => $this->location_id,
                'location'        => $this->whenLoaded('location', fn () => $this->location ? [
                    'id'   => $this->location->id,
                    'name' => $this->location->name,
                    'city' => $this->location->city,
                ] : null),
            ],

            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id'       => $m->id,
                'url'      => $m->url,
                'is_cover' => $m->is_cover,
                'position' => $m->position,
            ])),
            'is_active' => $this->is_active,

            // Compatibilités : chargées uniquement sur le détail
            // Voir PartResource : les identifiants sont ce qui permet a
            // l'ecran d'edition de relire la liste et de la renvoyer sans
            // perte. Un accessoire n'a ni code moteur ni position.
            'fitments' => $this->whenLoaded('fitments', fn () => $this->fitments->map(fn ($f) => [
                'id'               => $f->id,
                'vehicle_model_id' => $f->vehicle_model_id,
                'vehicle_model'    => $f->vehicleModel?->full_name ?? null,
                'brand_id'         => $f->vehicleModel?->brand_id,
                'trim_id'          => $f->trim_id,
                'trim'             => $f->trim?->name,
                'engine_type_id'   => $f->engine_type_id,
                'drivetrain_id'    => $f->drivetrain_id,
                'year_from'        => $f->year_from,
                'year_to'          => $f->year_to,
                'notes'            => $f->notes,
                'source'           => $f->source?->value,
            ])),
            'partners' => PartnerResource::collection($this->whenLoaded('partners')),
        ];
    }
}
