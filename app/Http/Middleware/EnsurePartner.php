<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isPartner() && $user->is_active, 403, 'Acces reserve aux comptes partenaires actifs.');

        // Requete fraiche a chaque passage : une validation ou suspension
        // prend effet meme pour un jeton deja emis.
        $account = $user->partnerAccount()->first();
        abort_unless($account, 403, 'Compte partenaire introuvable.');
        $user->setRelation('partnerAccount', $account);
        $request->attributes->set('partner_account', $account);

        return $next($request);
    }
}
