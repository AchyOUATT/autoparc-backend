<?php

namespace App\Http\Requests;

use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleDealType;
use App\Enums\VehicleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    /**
     * Seuls les roles qui tiennent le catalogue peuvent creer ou modifier une
     * fiche. Cette methode renvoyait `true` sans condition : un compte
     * « viewer » disposait des memes droits qu'un administrateur.
     */
    public function authorize(): bool
    {
        return $this->user()?->role?->canManageCatalog() ?? false;
    }

    public function rules(): array
    {
        return [
            'reference'           => ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'reference')],
            'vin'                 => ['nullable', 'string', 'size:17', Rule::unique('vehicles', 'vin')],
            // registration_status est calculé automatiquement : 'registered' si plate_number fourni, sinon 'unregistered'.
            'registration_status' => ['nullable', Rule::in(RegistrationStatus::values())],
            'plate_number'        => ['nullable', 'string', 'max:30'],
            'vehicle_type'        => ['nullable', Rule::in(['passenger', 'utility', 'heavy'])],
            'body_style'          => ['nullable', Rule::in([
                'sedan', 'hatchback', 'suv', 'estate', 'coupe', 'convertible',
                'pickup', 'van', 'minibus', 'bus', 'truck', 'other',
            ])],

            'brand_id'           => ['required', 'exists:brands,id'],
            'vehicle_model_id'   => ['required', 'exists:vehicle_models,id'],
            'trim_id'            => ['nullable', 'exists:trims,id'],
            'engine_type_id'     => ['required', 'exists:engine_types,id'],
            'drivetrain_id'      => ['nullable', 'exists:drivetrains,id'],
            'color_id'           => ['nullable', 'exists:colors,id'],

            'manufacturing_year' => ['required', 'integer', 'min:1950', 'max:'.now()->year],
            'transmission'       => ['nullable', Rule::in(Transmission::values())],
            'gear_count'         => ['nullable', 'integer', 'min:1', 'max:12'],
            'engine_displacement_cc' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'power_hp'           => ['nullable', 'integer', 'min:0', 'max:2000'],
            'power_kw'           => ['nullable', 'integer', 'min:0', 'max:1500'],
            'torque_nm'          => ['nullable', 'integer', 'min:0'],
            'cylinders'          => ['nullable', 'integer', 'min:0', 'max:16'],
            'seats'              => ['nullable', 'integer', 'min:1', 'max:70'],
            'doors'              => ['nullable', 'integer', 'min:0', 'max:8'],
            'engine_code'        => ['nullable', 'string', 'max:50'],

            'consumption_urban'        => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'consumption_extra_urban'  => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'consumption_combined'     => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'electric_consumption_kwh' => ['nullable', 'numeric', 'min:0'],
            'battery_capacity_kwh'     => ['nullable', 'numeric', 'min:0'],
            'electric_range_km'        => ['nullable', 'integer', 'min:0'],
            'co2_g_km'                 => ['nullable', 'integer', 'min:0'],
            'fuel_tank_liters'         => ['nullable', 'integer', 'min:0'],

            'partner_id'   => ['nullable', 'exists:partners,id'],
            'condition'    => ['nullable', Rule::in(VehicleCondition::values())],
            'status'       => ['nullable', Rule::in(VehicleStatus::values())],
            'availability' => ['nullable', Rule::in(VehicleAvailability::values())],

            'purchase_price'     => ['nullable', 'numeric', 'min:0'],
            'sale_price'         => ['nullable', 'numeric', 'min:0', 'required_if:availability,sale,both'],
            'rental_daily_rate'  => ['nullable', 'numeric', 'min:0', 'required_if:availability,rent,both'],
            'rental_weekly_rate' => ['nullable', 'numeric', 'min:0'],
            'rental_monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'rental_deposit'     => ['nullable', 'numeric', 'min:0'],
            'rental_mileage_limit_per_day' => ['nullable', 'integer', 'min:0'],
            'currency'           => ['nullable', 'string', 'size:3'],
            'price_negotiable'   => ['boolean'],
            // Mise en avant commerciale ; null = pas de promotion.
            'deal_type'          => ['nullable', Rule::in(VehicleDealType::values())],
            'site'               => ['nullable', 'string', 'max:120'],
            'description'        => ['nullable', 'string'],
            'features'           => ['array'],
            'features.*'         => ['exists:features,id'],

            // ---- Bloc import (optionnel) -----------------------------------
            'import'                       => ['nullable', 'array'],
            'import.origin_country_id'     => ['nullable', 'exists:countries,id'],
            'import.purchase_country_id'   => ['nullable', 'exists:countries,id'],
            'import.supplier_name'         => ['nullable', 'string', 'max:150'],
            'import.auction_lot_no'         => ['nullable', 'string', 'max:60'],
            'import.port_of_loading'       => ['nullable', 'string', 'max:120'],
            'import.port_of_entry'         => ['nullable', 'string', 'max:120'],
            'import.bill_of_lading_no'     => ['nullable', 'string', 'max:80'],
            'import.container_no'          => ['nullable', 'string', 'max:40'],
            'import.shipping_date'         => ['nullable', 'date'],
            'import.arrival_date'          => ['nullable', 'date', 'after_or_equal:import.shipping_date'],
            'import.customs_cleared'       => ['boolean'],
            'import.customs_declaration_no' => ['nullable', 'string', 'max:80'],
            'import.customs_duty_amount'   => ['nullable', 'numeric', 'min:0'],
            'import.freight_cost'          => ['nullable', 'numeric', 'min:0'],
            'import.steering_side'         => ['nullable', Rule::in(['left', 'right'])],
            'import.odometer_at_import_km' => ['nullable', 'integer', 'min:0'],
            'import.foreign_plate'         => ['nullable', 'string', 'max:30'],
            'import.notes'                 => ['nullable', 'string'],

            // ---- Bloc immatriculation (optionnel) -------------------------
            'registration'                          => ['nullable', 'array'],
            'registration.plate_number'             => ['nullable', 'string', 'max:30'],
            'registration.registration_country_id'  => ['nullable', 'exists:countries,id'],
            'registration.registration_certificate_no' => ['nullable', 'string', 'max:60'],
            'registration.first_registration_date'  => ['nullable', 'date', 'before_or_equal:today'],
            'registration.last_transfer_date'       => ['nullable', 'date'],
            'registration.mileage_km'               => ['nullable', 'integer', 'min:0'],
            'registration.previous_owners_count'    => ['nullable', 'integer', 'min:0', 'max:50'],
            'registration.technical_inspection_expiry' => ['nullable', 'date'],
            'registration.insurance_expiry'         => ['nullable', 'date'],
            'registration.insurance_company'        => ['nullable', 'string', 'max:120'],
            'registration.service_book_available'   => ['boolean'],
            'registration.last_service_date'        => ['nullable', 'date'],
            'registration.last_service_mileage_km'  => ['nullable', 'integer', 'min:0'],
            'registration.has_accident_history'     => ['boolean'],
            'registration.notes'                    => ['nullable', 'string'],

            // ---- Pannes declarees a la creation (optionnel) ---------------
            'faults'   => ['array'],
            'faults.*.title'    => ['required_with:faults', 'string', 'max:180'],
            'faults.*.category' => ['required_with:faults', 'string'],
            'faults.*.severity' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Cohérence : le modèle doit appartenir à la marque envoyée.
            if ($this->filled(['brand_id', 'vehicle_model_id'])) {
                $belongs = \App\Models\VehicleModel::where('id', $this->input('vehicle_model_id'))
                    ->where('brand_id', $this->input('brand_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('vehicle_model_id', "Ce modele n'appartient pas a la marque selectionnee.");
                }
            }

            // Cohérence : la finition doit appartenir au modèle.
            if ($this->filled(['vehicle_model_id', 'trim_id'])) {
                $belongs = \App\Models\Trim::where('id', $this->input('trim_id'))
                    ->where('vehicle_model_id', $this->input('vehicle_model_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('trim_id', "Cette finition n'appartient pas au modele selectionne.");
                }
            }
        });
    }
}
