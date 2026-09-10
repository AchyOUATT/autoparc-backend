<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reserve une route a un role qui possede la capacite demandee.
 *
 * A poser en plus de `staff`, qui ne distingue que le personnel des clients :
 * sans cela, un compte « viewer » creait des vehicules, un magasinier
 * enregistrait des ventes et n'importe quel employe pouvait notifier tous les
 * clients de l'application.
 *
 * Usage : ->middleware('capability:manage-orders')
 * Les capacites sont declarees dans App\Enums\UserRole::can().
 */
class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $role = $request->user()?->role;

        // Un nom de capacite inconnu tombe dans le `default` de can() et
        // refuse : une faute de frappe ferme la route, elle ne l'ouvre pas.
        if (! $role || ! $role->can($capability)) {
            abort(403, "Votre role ne permet pas cette action.");
        }

        return $next($request);
    }
}
