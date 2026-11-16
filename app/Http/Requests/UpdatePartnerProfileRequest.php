<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePartnerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPartner() === true
            && $this->user()->partnerAccount !== null;
    }

    public function rules(): array
    {
        return [
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'city.max' => __('La ville ne doit pas depasser 100 caracteres.'),
            'address.max' => __('L\'adresse ne doit pas depasser 120 caracteres.'),
        ];
    }
}
