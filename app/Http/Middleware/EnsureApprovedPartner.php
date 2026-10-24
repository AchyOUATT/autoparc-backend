<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A utiliser apres auth:sanctum et EnsurePartner sur toutes les routes d'inventaire. */
class EnsureApprovedPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->attributes->get('partner_account');
        abort_unless($account && $account->isApproved(), 403, 'Votre compte partenaire doit etre approuve pour gerer des annonces.');

        return $next($request);
    }
}
