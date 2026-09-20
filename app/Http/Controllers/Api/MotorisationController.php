<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MotorisationResource;
use App\Models\Motorisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Les motorisations connues pour un modele, avec leur cote officielle.
 *
 * L'application les propose au proprietaire pour qu'il reconnaisse la sienne :
 * une Camry 2013 existe en 2,5 l quatre cylindres et en 3,5 l V6, et l'ecart
 * de consommation entre les deux — 8,2 contre 9,4 l/100 — ne se devine pas.
 */
class MotorisationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $valide = $request->validate([
            'vehicle_model_id' => ['required', 'integer', 'exists:vehicle_models,id'],
            'year'             => ['nullable', 'integer', 'min:1950', 'max:2100'],
        ]);

        $annee = $valide['year'] ?? null;

        $cotes = Motorisation::query()
            ->where('vehicle_model_id', $valide['vehicle_model_id'])
            ->limit(400)
            ->get();

        // Le tri par proximite se fait ici, pas en SQL.
        //
        // `ABS(model_year - 2016)` paraissait naturel, et MySQL refusait la
        // requete : `model_year` est un entier non signe, et 1995 - 2016
        // deborde avant meme d'atteindre la valeur absolue. Les tests, qui
        // tournent sur SQLite, n'auraient jamais vu l'erreur — elle est
        // apparue au premier appel reel.
        //
        // On prend le millesime le plus proche plutot que l'exact : rendre une
        // liste vide parce que 2014 manque, alors que 2013 et 2015 sont la,
        // n'aiderait personne. Les cotes d'une generation varient peu d'une
        // annee a l'autre.
        $cotes = $cotes
            ->sortBy(fn (Motorisation $m) => [
                $annee === null ? 0 : abs($m->model_year - $annee),
                (float) ($m->engine_l ?? 0),
                (float) ($m->consumption_combined ?? 0),
            ])
            // Une meme motorisation revient a chaque millesime : le tri a
            // remonte la plus proche, on ne garde qu'elle.
            ->unique(fn (Motorisation $m) => implode('|', [
                $m->engine_l, $m->cylinders, $m->transmission_code, $m->fuel_code,
            ]))
            ->sortBy(fn (Motorisation $m) => (float) ($m->engine_l ?? 0))
            ->take(60)
            ->values();

        return MotorisationResource::collection($cotes);
    }
}
