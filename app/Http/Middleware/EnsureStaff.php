<?php

namespace App\Http\Middleware;

use App\Exceptions\RefusMetier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserve les routes back-office (vehicules, ventes, commandes...) au personnel.
 * A poser en plus de auth:sanctum : celui-ci verifie seulement la connexion,
 * pas le role.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isStaff()) {
            throw RefusMetier::permissionRefusee('Acces reserve au personnel.');
        }

        // LE ROLE EST BON, LE COMPTE NE L EST PLUS. Deux refus que le statut
        // 403 ne distingue pas, et que le client doit traiter a l oppose :
        // celui-ci ferme la session, celui du dessus la conserve. Voir
        // App\Exceptions\RefusMetier.
        if (! $user->is_active) {
            throw RefusMetier::compteDesactive('Acces reserve au personnel actif.');
        }

        return $next($request);
    }
}
