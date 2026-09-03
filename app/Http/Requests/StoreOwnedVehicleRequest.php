<?php

namespace App\Http\Requests;

use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Foundation\Http\FormRequest;

class StoreOwnedVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy 'create' verifiee dans le controller
    }

    public function rules(): array
    {
        return [
            'brand_id'           => ['required', 'exists:brands,id'],
            'vehicle_model_id'   => ['required', 'exists:vehicle_models,id'],
            'trim_id'            => ['nullable', 'exists:trims,id'],
            'engine_type_id'     => ['nullable', 'exists:engine_types,id'],
            'drivetrain_id'      => ['nullable', 'exists:drivetrains,id'],
            'color_id'           => ['nullable', 'exists:colors,id'],

            'manufacturing_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'vin'                => ['nullable', 'string', 'size:17'],
            // Code moteur fourni par le decodage VIN (NHTSA) ou saisi manuellement.
            // Exemples : "1NZ", "K20", "OM651". Max 20 chars, tout format accepte.
            'engine_code'        => ['nullable', 'string', 'max:20'],
            'plate_number'       => ['nullable', 'string', 'max:30'],
            'nickname'           => ['nullable', 'string', 'max:80'],
            'mileage_km'         => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled(['brand_id', 'vehicle_model_id'])) {
                $belongs = VehicleModel::where('id', $this->input('vehicle_model_id'))
                    ->where('brand_id', $this->input('brand_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('vehicle_model_id', "Ce modele n'appartient pas a la marque selectionnee.");
                }
            }

            if ($this->filled(['vehicle_model_id', 'trim_id'])) {
                $belongs = Trim::where('id', $this->input('trim_id'))
                    ->where('vehicle_model_id', $this->input('vehicle_model_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('trim_id', "Cette finition n'appartient pas au modele selectionne.");
                }
            }
        });
    }
}
