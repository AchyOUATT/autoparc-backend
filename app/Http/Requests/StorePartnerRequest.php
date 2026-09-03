<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware 'staff' garantit l'accès
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone'        => ['required', 'string', 'max:30'],
            'whatsapp'     => ['nullable', 'string', 'max:30'],
            'email'        => ['nullable', 'email', 'max:120'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'is_active'    => ['sometimes', 'boolean'],
        ];
    }
}
