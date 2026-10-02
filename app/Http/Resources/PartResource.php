<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
// PartnerResource est dans le même namespace, résolu automatiquement par Laravel

class PartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'sku'         => $this->sku,
            'name'        => $this->name,
            'description' => $this->description,
            'type'        => $this->type->value,
            'condition'   => $this->condition->value,
            'category'        => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ]),
            'category_id'     => $this->part_category_id,
            'manufacturer'    => $this->whenLoaded('manufacturer', fn () => $this->manufacturer?->name),
            'manufacturer_id' => $this->manufacturer_id,
            'manufacturer_reference' => $this->manufacturer_reference,
            'oem_numbers'  => OemNumberResource::collection($this->whenLoaded('oemNumbers')),
            'donor_vehicle' => $this->whenLoaded('donorVehicle', fn () => $this->donorVehicle?->reference),

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
                'storage_location' => $this->storage_location,
                'location'        => $this->whenLoaded('location', fn () => $this->location ? [
                    'id'   => $this->location->id,
                    'name' => $this->location->name,
                    'city' => $this->location->city,
                ] : null),
                'location_id'     => $this->location_id,
            ],

            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id'       => $m->id,
                'url'      => $m->url,
                'is_cover' => $m->is_cover,
                'position' => $m->position,
            ])),
            'warranty_months' => $this->warranty_months,
            'weight_kg'       => $this->weight_kg,
            'dimensions'      => $this->dimensions,
            'is_active'       => $this->is_active,
            // Les identifiants comptent autant que les libelles : l'ecran
            // d'edition relit cette liste et la renvoie telle quelle. Rendre
            // « Toyota Corolla » et « 1.4 D-4D » sans les identifiants rendait
            // l'aller-retour impossible — l'ecriture, elle, attend trim_id et
            // engine_type_id. Toute cle absente ici est une donnee perdue au
            // premier enregistrement.
            'fitments'        => $this->whenLoaded('fitments', fn () => $this->fitments->map(fn ($f) => [
                'id'               => $f->id,
                'vehicle_model_id' => $f->vehicle_model_id,
                'vehicle_model'    => $f->vehicleModel?->full_name,
                'brand_id'         => $f->vehicleModel?->brand_id,
                'trim_id'          => $f->trim_id,
                'trim'             => $f->trim?->name,
                'engine_type_id'   => $f->engine_type_id,
                'engine_type'      => $f->engineType?->label,
                'drivetrain_id'    => $f->drivetrain_id,
                'engine_code'      => $f->engine_code,
                'year_from'        => $f->year_from,
                'year_to'          => $f->year_to,
                'position'         => $f->position,
                'notes'            => $f->notes,
                'source'           => $f->source?->value,
            ])),
            'partners'        => PartnerResource::collection($this->whenLoaded('partners')),
        ];
    }
}
