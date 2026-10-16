<?php

namespace App\Http\Requests;

/**
 * Recherche de pieces par criteres vehicule, sans vehicule enregistre.
 *
 * Les criteres etaient deja valides ; la pagination ne l'etait pas. La route
 * est publique et sert le catalogue de l'application : elle plafonne donc
 * per_page comme la route du garage, au meme seuil.
 *
 * Elle n'herite pas des filtres de CompatiblePartsRequest : le moteur ne lit ici
 * que les criteres ci-dessous, et valider un parametre que la route ignore
 * laisserait croire qu'il filtre.
 */
class CompatiblePartsCriteriaRequest extends CompatiblePartsRequest
{
    public function rules(): array
    {
        return $this->paginationRules() + [
            'vehicle_model_id' => ['required', 'exists:vehicle_models,id'],
            'year'             => ['nullable', 'integer', 'min:1950'],
            'trim_id'          => ['nullable', 'exists:trims,id'],
            'engine_type_id'   => ['nullable', 'exists:engine_types,id'],
            'drivetrain_id'    => ['nullable', 'exists:drivetrains,id'],
        ];
    }

    public function messages(): array
    {
        return $this->paginationMessages() + [
            'vehicle_model_id.required' => 'Sans modèle de véhicule, la recherche de compatibilité n\'a pas de sens.',
            'vehicle_model_id.exists'   => "Ce modèle de véhicule n'existe pas.",
            'year.min'                  => 'Aucun véhicule du catalogue n\'est antérieur à :min.',
            'trim_id.exists'            => "Cette finition n'existe pas.",
            'engine_type_id.exists'     => "Cette motorisation n'existe pas.",
            'drivetrain_id.exists'      => "Cette transmission n'existe pas.",
        ];
    }
}
