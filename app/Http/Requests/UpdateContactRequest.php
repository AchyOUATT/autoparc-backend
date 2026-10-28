<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth, staff et capability:manage-settings sur la route.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge([
                'phone' => preg_replace('/[\s().-]+/u', '', $this->input('phone')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Le numéro de contact est obligatoire.',
            'phone.regex' => 'Indiquez un numéro international avec + et son indicatif, par exemple +226 75 57 04 84.',
        ];
    }
}
