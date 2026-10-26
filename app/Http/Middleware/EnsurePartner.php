<?php

namespace App\Http\Middleware;

use App\Exceptions\RefusMetier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Le role d abord, l activite ensuite : les deux etaient confondus
        // dans un seul `abort_unless`, si bien qu un partenaire desactive et
        // un simple visiteur recevaient le meme refus. Le client doit
        // pourtant fermer sa session dans le premier cas et la garder dans
        // le second.
        if (! $user || ! $user->isPartner()) {
            throw RefusMetier::permissionRefusee('Acces reserve aux comptes partenaires.');
        }

        if (! $user->is_active) {
            throw RefusMetier::compteDesactive('Votre compte partenaire a ete desactive.');
        }

        // Requete fraiche a chaque passage : une validation ou suspension
        // prend effet meme pour un jeton deja emis.
        $account = $user->partnerAccount()->first();
        abort_unless($account, 403, 'Compte partenaire introuvable.');
        $user->setRelation('partnerAccount', $account);
        $request->attributes->set('partner_account', $account);

        return $next($request);
    }
}
