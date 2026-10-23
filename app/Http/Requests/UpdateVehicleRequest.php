<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends StoreVehicleRequest
{
    public function rules(): array
    {
        // A PATCH may contain only a price or an independent attribute.
        // Creation requirements must not force historical classification
        // identifiers to be resubmitted on every update.
        $rules = collect(parent::rules())->map(function (array $fieldRules) {
            return collect($fieldRules)
                ->map(fn ($rule) => $rule === 'required' ? 'sometimes' : $rule)
                ->all();
        })->all();

        $id = $this->route('vehicle')?->id ?? $this->route('vehicle');

        $rules['reference'] = ['nullable', 'string', 'max:50', Rule::unique('vehicles', 'reference')->ignore($id)];
        $rules['vin'] = ['nullable', 'string', 'size:17', Rule::unique('vehicles', 'vin')->ignore($id)];
        $rules['registration_status'] = ['sometimes', 'required', Rule::in(\App\Enums\RegistrationStatus::values())];

        return $rules;
    }
}
