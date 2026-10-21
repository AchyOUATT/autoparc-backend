<?php

namespace App\Http\Requests;

use App\Support\EngineCode;
use App\Support\VehicleClassification;
use Illuminate\Foundation\Http\FormRequest;

class StoreOwnedVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy 'create' verifiee dans le controller
    }

    /**
     * Un champ laisse vide vaut absent.
     *
     * Le formulaire envoie une chaine vide pour une saisie libre effacee ; sans
     * cette normalisation la colonne stockerait `''`, que ni la validation ni
     * l'affichage ne distinguent de NULL — mais que `whereNull` ne trouve pas.
     * Le vehicule aurait alors un modele vide invisible, impossible a rattraper
     * par une requete.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('engine_code') && (is_string($this->input('engine_code')) || $this->input('engine_code') === null)) {
            $this->merge(['engine_code' => EngineCode::normalize($this->input('engine_code'))]);
        }

        foreach (['vehicle_model_id', 'model_libre'] as $champ) {
            if ($this->has($champ) && is_string($this->input($champ)) && trim($this->input($champ)) === '') {
                $this->merge([$champ => null]);
            }
        }

        if (is_string($this->input('model_libre'))) {
            $this->merge(['model_libre' => trim((string) $this->input('model_libre'))]);
        }
    }

    public function rules(): array
    {
        return [
            'brand_id'           => ['required', 'integer', 'exists:brands,id'],

            // Le modele n'est plus obligatoire, mais l'un des deux l'est.
            //
            // Le referentiel est bati sur les flux d'occasion europeens ; le
            // parc vient aussi des Etats-Unis, du Golfe et du Japon. Il
            // manquera toujours des modeles — un CX-9, jamais vendu en Europe,
            // rendait l'enregistrement impossible. Qui ne trouve pas le sien
            // le tape, et le vehicule entre quand meme au garage.
            'vehicle_model_id'   => ['nullable', 'required_without:model_libre', 'integer', 'exists:vehicle_models,id'],
            'model_libre'        => ['nullable', 'required_without:vehicle_model_id', 'string', 'max:80'],
            'trim_id'            => ['nullable', 'integer', 'exists:trims,id'],
            'engine_type_id'     => ['nullable', 'exists:engine_types,id'],
            'motorisation_id'    => ['nullable', 'integer', 'exists:motorisations,id'],
            'drivetrain_id'      => ['nullable', 'exists:drivetrains,id'],
            'color_id'           => ['nullable', 'exists:colors,id'],

            'manufacturing_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'vin'                => ['nullable', 'string', 'size:17'],
            // Code moteur fourni par le decodage VIN (NHTSA) ou saisi manuellement.
            // Exemples : "1NZ", "K20", "OM651". Max 20 chars, tout format accepte.
            'engine_code'        => ['nullable', 'string', 'max:20'],
            'plate_number'       => ['nullable', 'string', 'max:30'],
            'nickname'           => ['nullable', 'string', 'max:80'],
            // Le plafond vient de StoreVehicleCheckRequest, qui le posait deja
            // sur le meme nombre. Il attrape le zero de trop, pas un vehicule
            // reellement tres use.
            //
            // Le RECUL, lui, n'est pas refuse ici, et ce n'est pas un oubli :
            // un compteur remplace existe sur un parc importe, et une saisie
            // trop haute doit pouvoir etre corrigee a la baisse. Ce qui a ete
            // supprime, c'est le recul SILENCIEUX — le dialogue de saisie
            // nomme desormais les deux chiffres et demande un second appui.
            // Voir le commentaire de _MileageDialogState::_valider() cote
            // application : un 9 600 tape pour 96 000 repoussait le rappel de
            // vidange de 80 000 km, sans la moindre erreur pour le signaler.
            'mileage_km'         => ['nullable', 'integer', 'min:0', 'max:2000000'],

            // Echeances d'entretien. Les deux dates d'expiration peuvent etre
            // passees : un proprietaire qui saisit une visite technique deja
            // expiree doit justement etre prevenu, pas bloque par le formulaire.
            'technical_inspection_expiry' => ['nullable', 'date'],
            'insurance_expiry'            => ['nullable', 'date'],
            'last_service_date'           => ['nullable', 'date', 'before_or_equal:today'],
            'last_service_mileage_km'     => ['nullable', 'integer', 'min:0'],
            'service_interval_km'         => ['nullable', 'integer', 'min:1000', 'max:50000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            VehicleClassification::validate(
                $validator,
                $this->only(['brand_id', 'vehicle_model_id', 'trim_id', 'motorisation_id']),
                $this->route('ownedVehicle'),
            );
        });
    }
}
