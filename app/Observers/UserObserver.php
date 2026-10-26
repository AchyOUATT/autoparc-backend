<?php

namespace App\Observers;

use App\Models\User;

/**
 * Revoque les jetons d'un compte au moment ou il est desactive.
 *
 * ── CE QUE CECI CORRIGE ────────────────────────────────────────────────────
 *
 * Desactiver un employe ne revoquait AUCUN jeton. Les deux seules revocations
 * du depot etaient `Admin\PartnerApplicationController` (refus et suspension
 * d'un partenaire) et la commande `RotateStaffPassword`. Avec
 * `config/sanctum.php` a `'expiration' => null`, un employe desactive gardait
 * donc l'acces jusqu'a sa propre deconnexion — c'est-a-dire potentiellement
 * pour toujours.
 *
 * ── CE QUE CHAQUE TERME DE LA CONDITION ACHETE, MESURE ────────────────────
 *
 * Les deux termes n'ont pas le meme poids, et on les a casses separement pour
 * le savoir.
 *
 * `! $user->is_active` tient le cas qui compte. Sans lui, la condition se
 * reduit a « la colonne a change » et une REACTIVATION revoque elle aussi. Ce
 * n'est pas theorique : un jeton peut etre vivant sur un compte inactif, par
 * le chemin SQL direct, et l'administration qui restaure le compte
 * deconnecterait alors la personne qu'elle vient de retablir.
 * `test_reactiver_un_compte_ne_revoque_pas_un_jeton_qui_avait_survecu` le
 * mesure, et c'est le seul banc qui y arrive — celui d'a cote cree son jeton
 * apres la reactivation, donc n'a rien de vivant a perdre.
 *
 * `wasChanged('is_active')` n'achete, lui, AUCUN changement de comportement.
 * Le retirer ne fait qu'ajouter un DELETE a chaque ecriture sur un compte deja
 * inactif, dont les jetons sont de toute facon censes avoir disparu. Aucun
 * banc ne le distingue et il ne faut pas en ecrire un pour la forme : le terme
 * reste parce qu'un observateur ne doit pas interroger la base a chaque
 * sauvegarde pour rien, pas parce qu'il corrige quoi que ce soit.
 *
 * A noter pour qui relirait ce fichier en cherchant l'argument habituel :
 * « changer un numero de telephone ne doit pas deconnecter quelqu'un » est
 * vrai, mais c'est `! $user->is_active` qui le tient, pas `wasChanged`. Un
 * compte actif n'est jamais revoque, quelle que soit la colonne ecrite.
 *
 * ── CE QU'IL NE VOIT PAS, ET QUI EST TENU AILLEURS ────────────────────────
 *
 * Tout ce qui ne passe pas par Eloquent : `updateQuietly()`, un
 * `DB::table('users')->update(...)`, une correction en SQL a la main, une
 * migration de donnees. Aucun evenement n'est emis, le jeton survit, et c'est
 * `EnsureActiveUser` qui l'arrete a la requete suivante — en 403
 * `account_disabled`. Les deux mecanismes sont complementaires et le test
 * `SessionsDesactiveesTest` les eprouve separement, chacun par son chemin.
 */
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $user->tokens()->delete();
        }
    }
}
