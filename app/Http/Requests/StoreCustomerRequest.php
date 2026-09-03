<?php

namespace App\Http\Requests;

use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCompany = in_array($this->input('type'), ['company', 'ngo', 'public'], true);

        return [
            'type'                   => ['required', Rule::in(CustomerType::values())],
            'first_name'             => [Rule::requiredIf(! $isCompany), 'nullable', 'string', 'max:80'],
            'last_name'              => [Rule::requiredIf(! $isCompany), 'nullable', 'string', 'max:80'],
            'company_name'           => [Rule::requiredIf($isCompany), 'nullable', 'string', 'max:150'],
            'tax_id'                 => ['nullable', 'string', 'max:60'],
            'id_document_type'       => ['nullable', 'string', 'max:40'],
            'id_document_number'     => ['nullable', 'string', 'max:60'],
            'driving_licence_number' => ['nullable', 'string', 'max:60'],
            'driving_licence_expiry' => ['nullable', 'date'],
            'phone'                  => ['required', 'string', 'max:30'],
            'phone_alt'              => ['nullable', 'string', 'max:30'],
            'email'                  => ['nullable', 'email', 'max:150'],
            'address'                => ['nullable', 'string', 'max:200'],
            'city'                   => ['nullable', 'string', 'max:80'],
            'country_id'             => ['nullable', 'exists:countries,id'],
            'notes'                  => ['nullable', 'string'],
        ];
    }
}
