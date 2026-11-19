<?php

namespace App\Support;

use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtre les pieces et accessoires sans aucune compatibilite.
 *
 * `without_fitments` n'est honore que pour le personnel, comme
 * `inactive_only`. Un visiteur qui l'envoie sur une route publique
 * le voit ignore : la liste reste celle du catalogue, avec ou sans
 * applications. Une piece n'est retenue que si elle n'a ni ligne
 * dans `part_fitments`, ni application via un numero OEM — les deux
 * chemins que la recherche de pieces compatibles interroge. Les
 * accessoires n'ont que leurs propres fitments.
 */
class FiltreCompatibiliteArticle
{
    public static function appliquer(Builder $query, Request $request): Builder
    {
        if (! $request->user()?->isStaff() || ! $request->boolean('without_fitments')) {
            return $query;
        }

        $query->whereDoesntHave('fitments');

        if ($query->getModel() instanceof Part) {
            $query->whereDoesntHave('oemNumbers.fitments');
        }

        return $query;
    }
}
