<?php

namespace App\Support;

use App\Models\Motorisation;
use App\Models\Vehicle;

/**
 * Ce qu'on peut dire de la consommation d'une annonce.
 *
 * Une fiche du catalogue connait son modele et son millesime, rarement sa
 * motorisation exacte : le vendeur saisit « Toyota Camry 2013 », pas « 2,5 l
 * quatre cylindres ». Or l'ecart entre les moteurs d'un meme modele atteint
 * couramment un litre et demi aux cent.
 *
 * On annonce donc une fourchette, et on la nomme comme telle. Afficher une
 * valeur unique reviendrait a choisir un moteur au hasard pour le compte de
 * l'acheteur.
 */
class CotesOfficielles
{
    /**
     * @return array{
     *     min: float, max: float, motorisations: int,
     *     source: string, cycle: string, model_year: int
     * }|null
     */
    public static function pourVehicule(Vehicle $vehicule): ?array
    {
        if ($vehicule->vehicle_model_id === null) {
            return null;
        }

        $cotes = Motorisation::query()
            ->where('vehicle_model_id', $vehicule->vehicle_model_id)
            ->whereNotNull('consumption_combined')
            ->get(['model_year', 'consumption_combined', 'source', 'cycle']);

        if ($cotes->isEmpty()) {
            return null;
        }

        // Le millesime exact quand il existe, le plus proche sinon : les cotes
        // d'une generation varient peu d'une annee a l'autre, et rendre une
        // fourchette vide parce que 2014 manque n'aiderait personne.
        $annee = $vehicule->manufacturing_year;

        if ($annee !== null) {
            $ecartMin = $cotes->min(fn ($c) => abs($c->model_year - $annee));
            $cotes    = $cotes->filter(fn ($c) => abs($c->model_year - $annee) === $ecartMin);
        }

        $valeurs = $cotes->map(fn ($c) => (float) $c->consumption_combined);
        $premiere = $cotes->first();

        return [
            'min'           => round($valeurs->min(), 1),
            'max'           => round($valeurs->max(), 1),
            'motorisations' => $cotes->count(),
            'model_year'    => (int) $premiere->model_year,
            'source'        => (new Motorisation(['source' => $premiere->source]))->libelle_source,
            'cycle'         => (new Motorisation(['cycle' => $premiere->cycle]))->libelle_cycle,
        ];
    }
}
