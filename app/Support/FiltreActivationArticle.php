<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtre d'activation des pieces et des accessoires.
 *
 * `active_only` vaut vrai par defaut, ce qui masque les fiches inactives.
 * Sur les routes publiques, un visiteur pouvait pourtant envoyer
 * `active_only=0` et lister les articles AutoParc inactifs : le scope
 * `visibleToPublic` ne regarde `is_active` que pour les annonces
 * partenaires. Ce garde ignore donc toute demande d'elargissement si
 * l'appelant n'est pas du personnel. `inactive_only` est reserve au
 * personnel, pour n'afficher que les fiches a completer.
 */
class FiltreActivationArticle
{
    public static function appliquer(Builder $query, Request $request): Builder
    {
        $personnel = (bool) $request->user()?->isStaff();

        if ($personnel && $request->boolean('inactive_only')) {
            return $query->where($query->qualifyColumn('is_active'), false);
        }

        if (! $personnel || $request->boolean('active_only', true)) {
            return $query->active();
        }

        return $query;
    }
}
