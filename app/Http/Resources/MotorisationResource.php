<?php

namespace App\Http\Resources;

use App\Models\Motorisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une motorisation telle que l'application la propose au choix.
 *
 * Ce que l'utilisateur doit reconnaitre, c'est son moteur : « 2,5 l 4 cyl.,
 * boite auto. 6 », pas une finition. La provenance et le cycle d'essai
 * accompagnent toujours le chiffre — sans eux, 8,2 et 6,4 pour la meme voiture
 * passeraient pour une contradiction.
 */
class MotorisationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->libelle,
            'model_year' => $this->model_year,
            'fuel'       => Motorisation::libelleCarburant($this->fuel_code),
            'engine_l'   => $this->engine_l === null ? null : (float) $this->engine_l,
            'cylinders'  => $this->cylinders,

            'consumption' => [
                'city_l_100km'     => $this->consumption_city === null ? null : (float) $this->consumption_city,
                'highway_l_100km'  => $this->consumption_highway === null ? null : (float) $this->consumption_highway,
                'combined_l_100km' => $this->consumption_combined === null ? null : (float) $this->consumption_combined,
            ],

            'source' => [
                'code'  => $this->source,
                'label' => $this->libelle_source,
                'cycle' => $this->libelle_cycle,
            ],
        ];
    }
}
