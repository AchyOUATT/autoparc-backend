<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:160'],
            'contact_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => [
                'bail', 'required', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->exists()) {
                        $fail('Cette adresse email est deja utilisee.');
                    }
                },
            ],
            'password' => [
                'bail', 'required', 'string', 'confirmed', 'max:72',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // bcrypt limite les octets, pas les caracteres Unicode.
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('Le mot de passe ne doit pas depasser 72 octets.');
                    }
                    if (is_string($value) && str_contains($value, "\0")) {
                        $fail('Le mot de passe contient un caractere non accepte.');
                    }
                },
                Password::min(12)->letters()->numbers(),
            ],
            'device_name' => ['nullable', 'string', 'max:100'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'is_active' => ['prohibited'],
            'user_id' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
        ];
    }
}
