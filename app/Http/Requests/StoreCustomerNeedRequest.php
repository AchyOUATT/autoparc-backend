<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerNeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'type' n'est pas accepté depuis le formulaire client :
            // le contrôleur le force à 'vehicle' systématiquement.
            'firebase_uid'     => ['nullable', 'string', 'max:128'],

            'description'      => ['required', 'string', 'max:1000'],
            'budget_max'       => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'size:3'],

            // Critères structurés véhicule (tous optionnels).
            'brand_id'         => ['nullable', 'exists:brands,id'],
            'vehicle_model_id' => ['nullable', 'exists:vehicle_models,id'],
            'vehicle_type'     => ['nullable', Rule::in(['passenger', 'utility', 'heavy'])],
            'body_style'       => ['nullable', Rule::in([
                'sedan', 'hatchback', 'suv', 'estate', 'coupe', 'convertible',
                'pickup', 'van', 'minibus', 'bus', 'truck', 'other',
            ])],
            'year_min'         => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'year_max'         => ['nullable', 'integer', 'min:1950', 'max:'.now()->year, 'gte:year_min'],
        ];
    }
}
