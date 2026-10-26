<?php

namespace App\Http\Middleware;

use App\Exceptions\RefusMetier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arrete un jeton qui a SURVECU a la desactivation de son compte.
 *
 * ── LE TROU QU'IL FERME ────────────────────────────────────────────────────
 *
 * `is_active` n'etait controle qu'au `login()`. `EnsureStaff` ne regardait que
 * le role, `me()` ne lisait pas `is_active`, et desactiver un employe ne
 * revoquait aucun jeton. Un employe desactive gardait donc l'acces jusqu'a sa
 * propre deconnexion — c'est-a-dire, avec `expiration => null` cote Sanctum,
 * potentiellement pour toujours.
 *
 * Les partenaires (`EnsurePartner`) et les clients (`VerifyFirebaseToken`)
 * etaient, eux, deja controles. Le chemin du personnel etait le seul oublie.
 *
 * ── POURQUOI DEUX MECANISMES, ET NON CELUI-CI SEUL ────────────────────────
 *
 * `UserObserver` revoque les jetons quand `is_active` passe de vrai a faux par
 * ELOQUENT : la desactivation depuis l'administration ferme donc la session
 * immediatement, et la requete suivante rend 401 sans passer ici.
 *
 * Mais un observateur ne voit pas tout : `updateQuietly()`, un `DB::table(...)
 * ->update(...)`, une correction en SQL a la main ou une migration de donnees
 * ne declenchent aucun evenement Eloquent. Le jeton survit alors, et c'est ce
 * middleware qui l'arrete — en 403 `account_disabled`, parce que le jeton est
 * valable et que c'est le COMPTE qui ne l'est plus.
 *
 * Les deux chemins rendent donc deux statuts differents. Ce n'est pas une
 * incoherence, c'est l'information : 401 dit « ce jeton n'existe plus », 403
 * `account_disabled` dit « ce jeton existe, ce compte non ». Le client efface
 * sa session dans les deux cas.
 *
 * ── CE QU'IL NE GARDE PAS, ET POURQUOI ────────────────────────────────────
 *
 * `logout`. Un compte desactive doit pouvoir nettoyer son propre jeton : le
 * lui refuser laisserait une session morte dans le coffre du telephone, que
 * rien ne viendrait plus effacer. Il est donc pose sur `me` et non sur le
 * groupe entier.
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            throw RefusMetier::compteDesactive('Votre compte a ete desactive.');
        }

        return $next($request);
    }
}
