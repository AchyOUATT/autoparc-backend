<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Le plancher de version, servi a l'ouverture de l'application.
 *
 * Trois valeurs de configuration, aucun modele, aucune requete : cette route
 * doit rester la plus simple et la plus rapide de l'API. Toute ouverture de
 * l'application l'appelle, avant tout le reste et sans jeton — y compris sur
 * le premier appel du matin, celui qui reveille l'instance endormie du plan
 * gratuit et met couramment vingt-cinq secondes. Y ajouter un acces a la base
 * ferait payer ce reveil deux fois, a chaque demarrage, a chaque utilisateur.
 *
 * Elle reste hors de 'firebase' et de 'auth:sanctum' pour une raison de fond :
 * la version qu'on cherche justement a bloquer est aussi celle qui peut ne
 * plus savoir s'authentifier. Une reponse 401 ici laisserait l'application
 * dans l'ignorance de son propre obsolescence.
 */
class AppVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'min_version_code'    => (int) config('mobile.min_version_code'),
            'latest_version_code' => (int) config('mobile.latest_version_code'),
            'store_url'           => config('mobile.store_url'),
            'message'             => config('mobile.update_message'),
        ]);
    }
}
