<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'reference'    => $this->reference,
            'vin'          => $this->vin,
            'designation'  => $this->designation,
            'vehicle_type' => $this->vehicle_type ?? 'passenger',
            'body_style'   => $this->body_style,

            'registration_status' => [
                'value' => $this->registration_status?->value,
                'label' => $this->registration_status?->label(),
            ],

            'identity' => [
                'brand'       => $this->whenLoaded('brand', fn () => $this->brand->name),
                'model'       => $this->whenLoaded('vehicleModel', fn () => $this->vehicleModel->name),
                'generation'  => $this->whenLoaded('vehicleModel', fn () => $this->vehicleModel->generation),
                'trim'        => $this->whenLoaded('trim', fn () => $this->trim?->name),
                'engine_type' => $this->whenLoaded('engineType', fn () => $this->engineType->label),
                'drivetrain'  => $this->whenLoaded('drivetrain', fn () => $this->drivetrain?->code),
                'color'       => $this->whenLoaded('color', fn () => $this->color?->name),
                'year'        => $this->manufacturing_year,
            ],

            'technical' => [
                'transmission'           => $this->transmission?->value,
                'gear_count'             => $this->gear_count,
                'engine_displacement_cc' => $this->engine_displacement_cc,
                'engine_code'            => $this->engine_code,
                'power_hp'               => $this->power_hp,
                'power_kw'               => $this->power_kw,
                'torque_nm'              => $this->torque_nm,
                'cylinders'              => $this->cylinders,
                'seats'                  => $this->seats,
                'doors'                  => $this->doors,
                'mileage_km'             => $this->mileage_km,
            ],

            'consumption' => [
                'urban_l_100km'       => $this->consumption_urban,
                'extra_urban_l_100km' => $this->consumption_extra_urban,
                'combined_l_100km'    => $this->consumption_combined,
                'electric_kwh_100km'  => $this->electric_consumption_kwh,
                'battery_kwh'         => $this->battery_capacity_kwh,
                'electric_range_km'   => $this->electric_range_km,
                'co2_g_km'            => $this->co2_g_km,
                'fuel_tank_liters'    => $this->fuel_tank_liters,
            ],

            'commercial' => [
                'condition'          => $this->condition?->value,
                'status'             => $this->status?->value,
                'status_label'       => $this->status?->label(),
                'availability'       => $this->availability?->value,
                'sale_price'         => $this->sale_price,
                'rental_daily_rate'  => $this->rental_daily_rate,
                'rental_weekly_rate' => $this->rental_weekly_rate,
                'rental_monthly_rate' => $this->rental_monthly_rate,
                'rental_deposit'     => $this->rental_deposit,
                'currency'           => $this->currency,
                'price_negotiable'   => $this->price_negotiable,
                'site'               => $this->site,
                'location'           => $this->whenLoaded('location', fn () => $this->location ? [
                    'id'      => $this->location->id,
                    'name'    => $this->location->name,
                    'city'    => $this->location->city,
                    'address' => $this->location->address,
                    'phone'   => $this->location->phone,
                ] : null),
                'location_id'        => $this->location_id,
                'partner'            => $this->whenLoaded('partner', fn () => $this->partner ? [
                    'id'           => $this->partner->id,
                    'company_name' => $this->partner->company_name,
                    'contact_name' => $this->partner->contact_name,
                    'phone'        => $this->partner->phone,
                    'whatsapp'     => $this->partner->whatsapp,
                ] : null),
                'partner_id'         => $this->partner_id,
            ],

            // Bloc present uniquement pour un vehicule non immatricule
            'import' => $this->whenLoaded('importDetail', fn () => $this->importDetail ? [
                'origin_country'        => $this->importDetail->originCountry?->name,
                'origin_country_id'     => $this->importDetail->origin_country_id,
                'supplier_name'         => $this->importDetail->supplier_name,
                'port_of_loading'       => $this->importDetail->port_of_loading,
                'port_of_entry'         => $this->importDetail->port_of_entry,
                'bill_of_lading_no'     => $this->importDetail->bill_of_lading_no,
                'container_no'          => $this->importDetail->container_no,
                'shipping_date'         => $this->importDetail->shipping_date?->toDateString(),
                'arrival_date'          => $this->importDetail->arrival_date?->toDateString(),
                'customs_cleared'       => $this->importDetail->customs_cleared,
                'customs_declaration_no' => $this->importDetail->customs_declaration_no,
                'customs_duty_amount'   => $this->importDetail->customs_duty_amount,
                'freight_cost'          => $this->importDetail->freight_cost,
                'steering_side'         => $this->importDetail->steering_side,
                'odometer_at_import_km' => $this->importDetail->odometer_at_import_km,
                'foreign_plate'         => $this->importDetail->foreign_plate,
            ] : null),

            // Bloc present uniquement pour un vehicule deja immatricule
            'registration' => $this->whenLoaded('registrationDetail', fn () => $this->registrationDetail ? [
                'plate_number'            => $this->registrationDetail->plate_number,
                'registration_country'    => $this->registrationDetail->registrationCountry?->name,
                'certificate_no'          => $this->registrationDetail->registration_certificate_no,
                'first_registration_date' => $this->registrationDetail->first_registration_date?->toDateString(),
                'mileage_km'              => $this->registrationDetail->mileage_km,
                'previous_owners_count'   => $this->registrationDetail->previous_owners_count,
                'technical_inspection_expiry' => $this->registrationDetail->technical_inspection_expiry?->toDateString(),
                'inspection_expired'      => $this->registrationDetail->isInspectionExpired(),
                'insurance_expiry'        => $this->registrationDetail->insurance_expiry?->toDateString(),
                'service_book_available'  => $this->registrationDetail->service_book_available,
                'has_accident_history'    => $this->registrationDetail->has_accident_history,
            ] : null),

            'faults'       => VehicleFaultResource::collection($this->whenLoaded('faults')),
            'faults_count' => $this->whenCounted('faults'),
            'fault_score'  => $this->when($this->relationLoaded('faults'), fn () => $this->fault_score),

            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($f) => [
                'id'       => $f->id,
                'name'     => $f->name,
                'category' => $f->category,
            ])->values()),
            'media'    => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'id'         => $m->id,
                'url'        => $m->url,
                'collection' => $m->collection,
                'is_cover'   => $m->is_cover,
            ])),

            'description'  => $this->description,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
