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
            'trim_id' => $this->trim_id,
            'engine_type_id' => $this->engine_type_id,
            'drivetrain_id' => $this->drivetrain_id,
            'color_id' => $this->color_id,

            // Le modele tape a la main, quand le referentiel ne l'avait pas.
            // Il est transmis tel quel : le formulaire de modification doit
            // pouvoir le reafficher, et non le remplacer par un vide qui
            // effacerait la seule trace du modele reel.
            'model_libre'      => $this->model_libre,
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
                // `relationLoaded` plutot que `whenLoaded` : ce dernier rend
                // null sans appeler le callback des que la relation vaut null,
                // ce qui est exactement le cas d'un vehicule au modele tape a
                // la main — la retombee sur la saisie libre n'aurait jamais eu
                // lieu, et la fiche aurait affiche une marque sans modele.
                'model'      => $this->when(
                    $this->relationLoaded('vehicleModel'),
                    fn () => $this->modeleAffiche(),
                ),
                'trim'       => $this->whenLoaded('trim', fn () => $this->trim?->name),
                'engine_type' => $this->whenLoaded('engineType', fn () => $this->engineType?->label),
                'drivetrain' => $this->whenLoaded('drivetrain', fn () => $this->drivetrain?->code),
                'color'      => $this->whenLoaded('color', fn () => $this->color?->name),
            ],

            // Indicateur général de présence d'un code moteur exploitable.
            // Le verdict par pièce est fourni par compatibility_assessment.
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

            // La consommation REELLE, mesuree aux passages a la pompe, a cote
            // de la cote constructeur ci-dessus. Les deux ne disent pas la meme
            // chose et ne doivent jamais etre confondues : les cycles
            // d'homologation s'ecartent de 20 a 30 % de la conduite reelle ici,
            // entre les pistes, les embouteillages de Ouaga et la climatisation
            // permanente. C'est pourquoi l'alerte se compare a l'histoire du
            // vehicule et jamais a 'consumption'.
            //
            // TOUJOURS presente, contrairement a 'consumption' et 'last_check'
            // qui disparaissent sous whenLoaded : l'application doit pouvoir
            // distinguer « ce vehicule n'a aucun passage » — champs nuls,
            // compteurs a zero — de « le serveur n'a rien dit sur ce point ».
            // Une cle absente ne dit ni l'un ni l'autre, et l'ecran afficherait
            // un tiret la ou il doit proposer d'enregistrer un premier passage.
            //
            // Le calcul depend de la relation fuelFills : elle est chargee dans
            // OwnedVehicleController::$relations, donc sur index, show, store et
            // update a la fois. Si elle venait a manquer a un seul de ces
            // endroits, la cle resterait juste ailleurs et le defaut ne se
            // verrait qu'a la lenteur de la liste.
            //
            // DEUX TAUX, et ils ne se remplacent pas. 'l_100km' est le chiffre
            // de l'ecran : les cinq derniers intervalles. 'alerte_l_100km' est
            // celui du verdict : les deux derniers, ceux sur lesquels
            // 'abnormal' s'est prononce, nul quand il n'y a pas eu de verdict.
            // Sur la serie 8 8 8 8 11 11 ils valent 9,2 et 11,0 — afficher le
            // premier a cote du titre « Consommation en hausse » annoncait un
            // ecart que les seuils publies ne justifient pas.
            //
            // 'series_rompue' dit qu'un compteur a ete remplace : l'histoire
            // d'avant existe toujours en base mais n'entre plus dans aucun de
            // ces chiffres. Sans l'afficher, le proprietaire voit total_fills
            // et ses totaux fondre sans explication.
            'fuel_tracking' => $this->suiviConsommation(),

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
