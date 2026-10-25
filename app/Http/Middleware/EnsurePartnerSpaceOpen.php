<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme l'espace partenaire tant que la chaine de livraison n'est pas prete.
 *
 * A poser sur le groupe `prefix('partner')` ENTIER, inscription et connexion
 * comprises — c'est l'inscription qu'il faut fermer en premier, puisque c'est
 * elle qui cree des comptes que personne ne peut encore approuver. Le
 * raisonnement complet, et l'ordre dans lequel ouvrir, sont dans
 * `config/partenaires.php`.
 *
 * ── POURQUOI 503 ET NON 404 ────────────────────────────────────────────────
 *
 * Un 404 se confondrait avec un serveur ou la fonctionnalite n'a jamais ete
 * deployee — exactement l'etat d'hier, et exactement ce que l'application
 * publiee rend deja aujourd'hui. On ne saurait plus distinguer « pas encore
 * livre » de « livre et volontairement ferme », ni depuis l'application, ni
 * dans les journaux. 503 dit la seconde chose, et porte un motif.
 *
 * ── POURQUOI UN MIDDLEWARE ET NON UN `if` DANS routes/partner.php ──────────
 *
 * `route:cache` tourne au demarrage du conteneur. Un `if` autour du groupe
 * marcherait donc, mais il ferait DISPARAITRE les routes : `route:list` ne les
 * montrerait plus, et le diagnostic d'un drapeau mal regle deviendrait une
 * enquete. Ici les routes existent toujours, et c'est la reponse qui dit non.
 */
class EnsurePartnerSpaceOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            config('partenaires.espace_ouvert'),
            503,
            "L'espace partenaire n'est pas encore ouvert. Reessayez apres la mise a jour de l'application.",
        );

        return $next($request);
    }
}
