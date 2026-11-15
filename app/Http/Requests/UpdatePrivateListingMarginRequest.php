<?php

namespace App\Http\Requests;

use App\Support\MargeAnnonceParticuliere;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePrivateListingMarginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth, staff et capability:manage-private-listing-margin sur la route.
    }

    public function rules(): array
    {
        return [
            'taux' => [
                'required',
                'numeric',
                'min:0',
                'max:'.MargeAnnonceParticuliere::TAUX_MAX,
                function (string $attribute, mixed $value, $fail): void {
                    $nombre = (float) $value;
                    if (abs($nombre * 10 - round($nombre * 10)) > 0.0001) {
                        $fail(__('Le taux peut avoir au plus une décimale.'));
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'taux.required' => __('Le taux des frais AutoParc est obligatoire.'),
            'taux.numeric' => __('Le taux des frais AutoParc doit être un nombre.'),
            'taux.min' => __('Le taux des frais AutoParc ne peut pas être négatif.'),
            'taux.max' => __('Le taux des frais AutoParc ne peut pas dépasser :max %.'),
        ];
    }
}
