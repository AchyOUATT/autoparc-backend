<?php

namespace App\Http\Requests;

use App\Models\Motorisation;
use App\Models\Trim;
use App\Models\VehicleModel;
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
        foreach (['vehicle_model_id', 'model_libre'] as $champ) {
            if ($this->has($champ) && trim((string) $this->input($champ)) === '') {
                $this->merge([$champ => null]);
            }
        }

        if ($this->filled('model_libre')) {
            $this->merge(['model_libre' => trim((string) $this->input('model_libre'))]);
        }
    }

    public function rules(): array
    {
        return [
            'brand_id'           => ['required', 'exists:brands,id'],

            // Le modele n'est plus obligatoire, mais l'un des deux l'est.
            //
            // Le referentiel est bati sur les flux d'occasion europeens ; le
            // parc vient aussi des Etats-Unis, du Golfe et du Japon. Il
            // manquera toujours des modeles — un CX-9, jamais vendu en Europe,
            // rendait l'enregistrement impossible. Qui ne trouve pas le sien
            // le tape, et le vehicule entre quand meme au garage.
            'vehicle_model_id'   => ['nullable', 'required_without:model_libre', 'exists:vehicle_models,id'],
            'model_libre'        => ['nullable', 'required_without:vehicle_model_id', 'string', 'max:80'],
            'trim_id'            => ['nullable', 'exists:trims,id'],
            'engine_type_id'     => ['nullable', 'exists:engine_types,id'],
            'motorisation_id'    => ['nullable', 'exists:motorisations,id'],
            'drivetrain_id'      => ['nullable', 'exists:drivetrains,id'],
            'color_id'           => ['nullable', 'exists:colors,id'],

            'manufacturing_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'vin'                => ['nullable', 'string', 'size:17'],
            // Code moteur fourni par le decodage VIN (NHTSA) ou saisi manuellement.
            // Exemples : "1NZ", "K20", "OM651". Max 20 chars, tout format accepte.
            'engine_code'        => ['nullable', 'string', 'max:20'],
            'plate_number'       => ['nullable', 'string', 'max:30'],
            'nickname'           => ['nullable', 'string', 'max:80'],
            'mileage_km'         => ['nullable', 'integer', 'min:0'],

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

    /** Le modele concerne : celui envoye, sinon celui du vehicule modifie. */
    protected function modeleCible(): ?int
    {
        if ($this->filled('vehicle_model_id')) {
            return (int) $this->input('vehicle_model_id');
        }

        $vehicule = $this->route('ownedVehicle');

        return $vehicule?->vehicle_model_id;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled(['brand_id', 'vehicle_model_id'])) {
                $belongs = VehicleModel::where('id', $this->input('vehicle_model_id'))
                    ->where('brand_id', $this->input('brand_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('vehicle_model_id', "Ce modele n'appartient pas a la marque selectionnee.");
                }
            }

            // Une cote de consommation appartient a un modele precis : celle
            // d'une Hilux ne dit rien d'une Corolla. Sans ce controle, une
            // erreur de saisie afficherait une consommation credible et
            // fausse, sans que rien ne la signale.
            //
            // Le modele vient de la requete a la creation, du vehicule
            // existant a la mise a jour : une modification partielle n'envoie
            // que le champ modifie, et le controle ne se declenchait pas.
            if ($this->filled('motorisation_id') && $this->modeleCible() !== null) {
                $appartient = Motorisation::where('id', $this->input('motorisation_id'))
                    ->where('vehicle_model_id', $this->modeleCible())
                    ->exists();

                if (! $appartient) {
                    $validator->errors()->add(
                        'motorisation_id',
                        "Cette motorisation ne correspond pas au modele selectionne."
                    );
                }
            }

            if ($this->filled(['vehicle_model_id', 'trim_id'])) {
                $belongs = Trim::where('id', $this->input('trim_id'))
                    ->where('vehicle_model_id', $this->input('vehicle_model_id'))
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('trim_id', "Cette finition n'appartient pas au modele selectionne.");
                }
            }
        });
    }
}
