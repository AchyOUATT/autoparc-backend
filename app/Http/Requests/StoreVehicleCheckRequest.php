<?php

namespace App\Http\Requests;

use App\Enums\CheckReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'envoi d'un controle avant voyage.
 *
 * Deux regles valent d'etre expliquees.
 *
 * `distinct` sur les codes : la table interdit deux reponses pour un meme point
 * dans un meme passage. Sans cette regle, un envoi rejoue apres une coupure —
 * le cas normal, pas le cas rare — remonterait une violation de contrainte et
 * l'utilisateur verrait une erreur 500 pour un controle correctement rempli.
 *
 * `performed_at` accepte le passe mais jamais le futur : un controle peut avoir
 * ete rempli hier soir sans reseau et parti ce matin. Trente jours de retard
 * suffisent largement, et au-dela la date est plus probablement fausse que
 * tardive.
 */
class StoreVehicleCheckRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Cinq mille kilometres : bien au-dela de toute route nationale, et
            // assez bas pour attraper un kilometrage saisi dans le mauvais champ.
            'trip_distance_km' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'mileage_km'       => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'reason'           => ['nullable', Rule::enum(CheckReason::class)],
            'note'             => ['nullable', 'string', 'max:2000'],
            'client_reference' => ['nullable', 'uuid'],
            'performed_at'     => ['nullable', 'date', 'before_or_equal:now', 'after_or_equal:-30 days'],

            'answers'             => ['required', 'array', 'min:1', 'max:60'],
            'answers.*.item_code' => ['required', 'string', 'distinct', 'exists:vehicle_check_items,code'],
            'answers.*.status'    => ['required', 'in:ok,watch,bad'],
            'answers.*.note'      => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'answers.required'          => 'Un contrôle sans aucun point vérifié ne serait pas un contrôle.',
            'answers.*.item_code.distinct' => 'Le même point de contrôle a été envoyé deux fois.',
            'answers.*.status.in'       => 'Un point se répond par « rien à signaler », « à surveiller » ou « défaut ».',
        ];
    }
}
