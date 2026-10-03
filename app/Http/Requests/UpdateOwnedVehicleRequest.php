<?php

namespace App\Http\Requests;

class UpdateOwnedVehicleRequest extends StoreOwnedVehicleRequest
{
    public function rules(): array
    {
        // Memes regles qu'a la creation, mais tout devient optionnel (mise a jour partielle).
        return collect(parent::rules())->map(function (array $rule) {
            return collect($rule)
                ->map(fn ($r) => $r === 'required' ? 'sometimes' : $r)
                // `required_without` exige l'autre champ des que celui-ci est
                // absent — ce qui est juste a la creation et faux ici : une
                // mise a jour du seul kilometrage ne porte ni le modele ni sa
                // saisie libre, et se verrait refusee. La contrainte « l'un ou
                // l'autre » est reverifiee plus bas, sur l'etat resultant.
                ->reject(fn ($r) => is_string($r) && str_starts_with($r, 'required_without'))
                ->values()
                ->all();
        })->all();
    }

    /**
     * Un vehicule ne doit jamais se retrouver sans aucun modele.
     *
     * Retirer `required_without` ouvrait une porte : une requete portant
     * `vehicle_model_id: null` et rien d'autre passait la validation et vidait
     * le seul champ qui nommait le vehicule. La fiche affichait alors
     * « Toyota 2015 » — un libelle plausible dont rien ne disait qu'il avait
     * perdu son modele.
     *
     * Le controle porte sur l'etat APRES la mise a jour, pas sur ce qui est
     * envoye : un champ absent de la requete garde sa valeur. Remplacer le
     * modele par une saisie libre, ou l'inverse, reste donc possible — c'est
     * vider les deux qui est refuse.
     */
    public function withValidator($validator): void
    {
        parent::withValidator($validator);

        $validator->after(function ($validator) {
            $vehicule = $this->route('ownedVehicle');

            if ($vehicule === null) {
                return;
            }

            $modele = $this->has('vehicle_model_id')
                ? $this->input('vehicle_model_id')
                : $vehicule->vehicle_model_id;

            $libre = $this->has('model_libre')
                ? $this->input('model_libre')
                : $vehicule->model_libre;

            if ($modele === null && trim((string) $libre) === '') {
                $validator->errors()->add(
                    'vehicle_model_id',
                    'Indiquez un modele de la liste, ou tapez le votre.'
                );
            }
        });
    }
}
