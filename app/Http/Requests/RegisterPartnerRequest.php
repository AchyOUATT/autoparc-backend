<?php

namespace App\Http\Requests;

use App\Models\PartnerAccount;
use App\Models\User;
use App\Support\NumeroDeTelephone;
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
            // Facultatif : un revendeur particulier n'a pas de raison sociale,
            // et l'exiger le faisait inventer un nom ou abandonner l'inscription.
            // Le nom PUBLIC se replie sur `contact_name` — voir
            // `PartnerListingPresentation::seller()`, qui tient la garantie que
            // l'API ne rend jamais null a l'application deja publiee.
            'company_name' => ['nullable', 'string', 'max:160'],
            'contact_name' => ['required', 'string', 'max:120'],

            // ── LE NUMERO EST L'IDENTIFIANT ────────────────────────────────
            //
            // Le courriel etant facultatif, c'est le numero qui dit de quel
            // compte il s'agit a la connexion. Il ne peut donc plus servir a
            // deux comptes — et la comparaison porte sur la forme NORMALISEE,
            // sans quoi « +226 70 00 00 01 » et « +22670000001 » creeraient
            // deux comptes sur une seule ligne.
            'phone' => [
                'bail', 'required', 'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $cle = NumeroDeTelephone::cle(is_string($value) ? $value : null);

                    if ($cle === null) {
                        $fail('Indiquez un numero de telephone.');

                        return;
                    }
                    if (PartnerAccount::query()->where('phone_key', $cle)->exists()) {
                        $fail('Ce numero est deja utilise par un compte partenaire.');
                    }
                },
            ],
            'whatsapp' => ['nullable', 'string', 'max:40'],

            // ── LE COURRIEL EST FACULTATIF ─────────────────────────────────
            //
            // Mesure faite : aucun courriel n'est envoye nulle part, toutes
            // les notifications passent par `AppNotification`. L'adresse ne
            // servait donc qu'a se connecter, et le numero le fait desormais.
            //
            // Elle reste UNIQUE quand elle est donnee : deux comptes ne
            // peuvent pas la partager, sinon la connexion par courriel
            // redeviendrait ambigue.
            'email' => [
                'bail', 'nullable', 'string', 'email', 'max:255',
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
