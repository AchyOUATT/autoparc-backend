<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends StoreVehicleRequest
{
    public function rules(): array
    {
        // Le parc conserve ses champs obligatoires. Les dependances omises,
        // comme la finition, sont controlees sur l'etat final du vehicule.
        $rules = parent::rules();
        $id = $this->route('vehicle')?->id ?? $this->route('vehicle');

        $rules['reference'] = ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'reference')->ignore($id)];
        $rules['vin']       = ['nullable', 'string', 'size:17', Rule::unique('vehicles', 'vin')->ignore($id)];
        $rules['registration_status'] = ['sometimes', 'required', Rule::in(\App\Enums\RegistrationStatus::values())];

        return $rules;
    }
}
