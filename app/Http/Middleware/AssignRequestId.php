<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Donne une reference courte a chaque requete.
 *
 * En production, une erreur serveur renvoie « Server Error » et rien d'autre :
 * l'utilisateur signale « ca affiche 500 », et retrouver la trace correspondante
 * suppose de deviner l'heure puis de fouiller le journal. Avec une reference
 * affichee dans l'application et posee dans le contexte des logs, le diagnostic
 * commence par un `grep`.
 *
 * Huit caracteres suffisent : la reference doit rester lisible a voix haute ou
 * recopiable depuis une capture d'ecran, et n'a besoin d'etre unique que parmi
 * les requetes recentes.
 */
class AssignRequestId
{
    public const HEADER    = 'X-Request-Id';
    public const ATTRIBUTE = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        // Un identifiant fourni par l'appelant est conserve : il permet de
        // suivre un meme echange de bout en bout quand l'application en genere
        // un elle-meme.
        $id = $request->headers->get(self::HEADER) ?: Str::lower(Str::random(8));

        $request->attributes->set(self::ATTRIBUTE, $id);

        // Toutes les lignes ecrites pendant cette requete la porteront.
        Log::withContext([self::ATTRIBUTE => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
