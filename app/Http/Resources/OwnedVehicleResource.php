<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OwnedVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'designation'      => $this->designation,
            'nickname'         => $this->nickname,
            'brand_id'         => $this->brand_id,
            'vehicle_model_id' => $this->vehicle_model_id,
            'vin'          => $this->vin,
            'engine_code'  => $this->engine_code,
            'plate_number' => $this->plate_number,
            'mileage_km'  => $this->mileage_km,
            'year'        => $this->manufacturing_year,

            // Echeances d'entretien. Les champs bruts servent au formulaire,
            // `deadlines` a l'affichage : c'est la meme liste, calculee au meme
            // endroit que celle utilisee par la tache de rappel.
            'technical_inspection_expiry' => $this->technical_inspection_expiry?->toDateString(),
            'insurance_expiry'            => $this->insurance_expiry?->toDateString(),
            'last_service_date'           => $this->last_service_date?->toDateString(),
            'last_service_mileage_km'     => $this->last_service_mileage_km,
            'service_interval_km'         => $this->service_interval_km,
            'deadlines'                   => $this->deadlines(),

            'identity' => [
                'brand'      => $this->whenLoaded('brand', fn () => $this->brand->name),

                // Le slug identifie le fichier de logo cote application
                // (assets/brands/<slug>.svg), comme pour les annonces du
                // catalogue. L'accueil le fabriquait jusqu'ici a partir du nom,
                // ce qui marchait tant qu'aucune marque n'avait d'accent :
                // « Citroën » y devenait « citro-n » et perdait son logo.
                'brand_slug' => $this->whenLoaded('brand', fn () => $this->brand->slug),
                'model'      => $this->whenLoaded('vehicleModel', fn () => $this->vehicleModel->name),
                'trim'       => $this->whenLoaded('trim', fn () => $this->trim?->name),
                'engine_type' => $this->whenLoaded('engineType', fn () => $this->engineType?->label),
                'drivetrain' => $this->whenLoaded('drivetrain', fn () => $this->drivetrain?->code),
                'color'      => $this->whenLoaded('color', fn () => $this->color?->name),
            ],

            // Indique si le calcul de compatibilite dispose d'une motorisation
            // (saisie ou deduite de la finition) pour etre precis.
            'compatibility_ready' => $this->hasPreciseEngineData(),

            // La cote officielle du moteur choisi, quand il l'a ete. Nulle
            // sinon : l'application propose alors de le choisir, plutot que
            // d'afficher une estimation que rien n'etaye.
            'motorisation_id' => $this->motorisation_id,
            'consumption'     => $this->whenLoaded('motorisation', function () {
                if (! $this->motorisation) {
                    return null;
                }

                return [
                    'label'            => $this->motorisation->libelle,
                    'fuel'             => \App\Models\Motorisation::libelleCarburant($this->motorisation->fuel_code),
                    'city_l_100km'     => $this->motorisation->consumption_city === null ? null : (float) $this->motorisation->consumption_city,
                    'highway_l_100km'  => $this->motorisation->consumption_highway === null ? null : (float) $this->motorisation->consumption_highway,
                    'combined_l_100km' => $this->motorisation->consumption_combined === null ? null : (float) $this->motorisation->consumption_combined,
                    'source'           => $this->motorisation->libelle_source,
                    'cycle'            => $this->motorisation->libelle_cycle,
                ];
            }),

            // Le dernier controle avant voyage, en resume.
            //
            // Sous whenLoaded, comme la consommation : la cle disparait quand la
            // relation n'est pas chargee, plutot que de valoir null — sans quoi
            // l'application ne saurait pas distinguer « aucun controle » de
            // « pas demande ».
            //
            // Trois nombres et une date, pas les reponses : la fiche du garage
            // affiche « 2 points a reprendre », et c'est l'ecran de controle qui
            // dit lesquels.
            'last_check' => $this->whenLoaded('dernierControle', function () {
                if (! $this->dernierControle) {
                    return null;
                }

                return [
                    'id'             => $this->dernierControle->id,
                    'performed_at'   => $this->dernierControle->performed_at?->toIso8601String(),
                    'mileage_km'     => $this->dernierControle->mileage_km,
                    'verdict'        => $this->dernierControle->verdict?->value,
                    'reason'         => $this->dernierControle->reason,
                    'verdict_label'  => $this->dernierControle->verdict?->libelle(
                        \App\Enums\CheckReason::tryFrom((string) $this->dernierControle->reason)
                            ?? \App\Enums\CheckReason::Trip,
                    ),
                    'blocking_count' => $this->dernierControle->blocking_count,
                    'watch_count'    => $this->dernierControle->watch_count,
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
