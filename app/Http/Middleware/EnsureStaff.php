<?php

namespace App\Http\Middleware;

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
            abort(403, "Acces reserve au personnel.");
        }

        return $next($request);
    }
}
