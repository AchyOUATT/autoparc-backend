<?php

namespace App\Http\Requests;

use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleDealType;
use App\Enums\VehicleStatus;
use App\Support\EngineCode;
use App\Support\VehicleClassification;
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

    /**
     * Le code moteur est mis en majuscules avant validation.
     *
     * Meme raison que dans `StoreOwnedVehicleRequest` et
     * `CompatiblePartsCriteriaRequest` : `CompatibilityService` compare par
     * EGALITE EXACTE et le referentiel stocke « 2AR-FE », pas « 2ar-fe ». Un
     * vehicule du catalogue saisi en minuscules ne rendrait que les
     * compatibilites sans code moteur, et le vendeur conclurait qu'il n'y en a
     * pas.
     *
     * LES TROIS PORTES D'ENTREE NORMALISENT PAREIL. Elles ne le faisaient pas :
     * seule la route publique de recherche avait ete traitee, ce qui laissait le
     * defaut entier des deux cotes de la SAISIE.
     *
     * La regle vit maintenant dans `App\Support\EngineCode::normalize()`, et
     * non recopiee a trois endroits : le jour ou l'on voudra rendre la colonne
     * insensible a la casse en base, c'est la qu'il faudra revenir. L'appel est
     * garde par un test de type, sans quoi un `engine_code` envoye comme
     * TABLEAU arriverait a `trim()` et deviendrait la chaine « Array ».
     *
     * `UpdateVehicleRequest` herite de cette classe.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('engine_code') && (is_string($this->input('engine_code')) || $this->input('engine_code') === null)) {
            $this->merge(['engine_code' => EngineCode::normalize($this->input('engine_code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reference'           => ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'reference')],
            'vin'                 => ['nullable', 'string', 'size:17', Rule::unique('vehicles', 'vin')],
            // Envoye, il prime. Absent, il est DEDUIT de `plate_number` :
            // une plaque vaut immatriculation. Ce champ etait accepte ici
            // puis ecrase par le controleur, si bien qu'un vehicule
            // immatricule dont on n'avait pas la plaque sous la main entrait
            // au catalogue du cote import. Voir VehicleController::store().
            'registration_status' => ['nullable', Rule::in(RegistrationStatus::values())],
            'plate_number'        => ['nullable', 'string', 'max:30'],
            'vehicle_type'        => ['nullable', Rule::in(['passenger', 'utility', 'heavy'])],
            'body_style'          => ['nullable', Rule::in([
                'sedan', 'hatchback', 'suv', 'estate', 'coupe', 'convertible',
                'pickup', 'van', 'minibus', 'bus', 'truck', 'other',
            ])],

            'brand_id'           => ['required', 'integer', 'exists:brands,id'],
            'vehicle_model_id'   => ['required', 'integer', 'exists:vehicle_models,id'],
            'trim_id'            => ['nullable', 'integer', 'exists:trims,id'],
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

            // location_id etait `fillable` sur le modele et rendu par la
            // ressource, mais absent d'ici. Le controleur partant de
            // validated(), la cle etait retiree avant create() : le lieu
            // choisi dans le formulaire n'arrivait jamais en base, sans
            // erreur ni champ refuse. Mesure : un POST portant location_id
            // rendait 201 et un vehicule a location_id null.
            'location_id'  => ['nullable', 'exists:locations,id'],
            'partner_id'   => ['nullable', 'exists:partners,id'],
            'condition'    => ['nullable', Rule::in(VehicleCondition::values())],
            'status'       => ['nullable', Rule::in(VehicleStatus::values())],
            'availability' => ['nullable', Rule::in(VehicleAvailability::values())],

            'purchase_price'     => ['nullable', 'numeric', 'min:0'],
            'sale_price'         => ['nullable', 'numeric', 'min:0', 'required_if:availability,sale,both'],
            'rental_daily_rate'  => ['nullable', 'numeric', 'min:0'],
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
            $vehicle = $this->route('vehicle');

            VehicleClassification::validate(
                $validator,
                $this->only(['brand_id', 'vehicle_model_id', 'trim_id']),
                $vehicle,
            );

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // En modification, un champ omis conserve sa valeur ; un null
            // explicite retire le tarif. On controle donc la fiche resultante.
            $availability = $this->input('availability', $vehicle?->availability?->value);

            if (! in_array($availability, ['rent', 'both'], true)) {
                return;
            }

            $dailyRate = $this->input('rental_daily_rate', $vehicle?->rental_daily_rate);
            $monthlyRate = $this->input('rental_monthly_rate', $vehicle?->rental_monthly_rate);

            if ($dailyRate === null && $monthlyRate === null) {
                $message = 'Renseignez un prix journalier ou un forfait mensuel pour proposer ce véhicule à la location.';
                $validator->errors()->add('rental_daily_rate', $message);
                $validator->errors()->add('rental_monthly_rate', $message);
            }
        });
    }
}
