<?php

use App\Support\PlafondsDeDepot;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',

        // Les pages de partage d'un article sont enregistrees ICI, et non dans
        // routes/web.php, pour rester HORS du groupe 'web'.
        //
        // Ce groupe pose StartSession et AddQueuedCookiesToResponse : mesure
        // sur GET /v/1 quand ces routes etaient dans routes/web.php,
        // « cache-control: max-age=300, public » accompagnait
        // « set-cookie: laravel-session=...; httponly ». Une reponse publique
        // qui pose un cookie de session est stockable par n'importe quel cache
        // partage, qui peut la resservir — cookie compris — a quelqu'un
        // d'autre. Et chaque visite anonyme ecrivait une ligne dans `sessions`
        // sur le Postgres gratuit, pour des pages sans aucun etat a retenir.
        //
        // Pas de pile 'web', donc, et pas de pile 'api' non plus : celle-ci
        // prepend AssignRequestId et force le rendu JSON des erreurs, alors
        // qu'un 404 doit ici rendre une page. Seul le plafond de debit est
        // conserve — le detail est en tete de routes/partage.php.
        then: function () {
            Route::middleware([
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \App\Http\Middleware\EnsurePrivateSalesEnabled::class,
                'throttle:partage',
            ])->get('/annonces/{listing}', [\App\Http\Controllers\Api\PrivateVehicleListingController::class, 'share']);
            Route::middleware([
                // SubstituteBindings vit dans les groupes 'web' et 'api', pas
                // dans la pile globale : sans lui, `Vehicle $vehicle` n'est plus
                // resolu depuis l'identifiant de l'URL, et le controleur recoit
                // un modele vide dont published_at est null — mesure, GET /v/1
                // sur un vehicule publie rendait alors 404. La liaison de
                // modele est la seule chose du groupe 'web' dont ces pages
                // aient besoin.
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                'throttle:partage',
            ])->group(base_path('routes/partage.php'));
        },
    )
    
->withMiddleware(function (Middleware $middleware) {
    // Sans cela, `$request->ip()` renvoie l'adresse du repartiteur de charge
    // de Render — la meme pour tout le monde. Les limites posees par adresse
    // deviendraient alors globales : vingt tentatives de connexion par minute
    // pour l'ensemble de la plateforme, et le premier visiteur a atteindre le
    // plafond bloquerait tous les autres.
    //
    // Faire confiance a tous les mandataires est la configuration usuelle
    // derriere un service gere : les adresses du repartiteur ne sont ni fixes
    // ni publiees. Cela suppose que l'application ne soit joignable qu'a
    // travers lui, ce qui est le cas sur Render.
    $middleware->trustProxies(at: '*');

    // PAS DE REDIRECTION VERS UNE PAGE DE CONNEXION QUI N'EXISTE PAS.
    //
    // Sans cette ligne, un appel non authentifie sur n'importe quelle route
    // d'API rendait 500 au lieu de 401 — et ecrivait a chaque fois une trace
    // complete dans le journal.
    //
    // LE MECANISME, parce qu'il n'est pas dans notre code et qu'on le cherche
    // longtemps. `withMiddleware()` pose un defaut, dans le framework :
    // ApplicationBuilder.php:291, `redirectGuestsTo(fn () => route('login'))`.
    // Et `Authenticate::unauthenticated()` fait
    // `$request->expectsJson() ? null : $this->redirectTo($request)` : le
    // rappel n'est donc appele QUE si l'appelant n'a pas demande du JSON. Il
    // cherche alors la route nommee `login`, qui n'existe pas ici — cette API
    // n'a que `POST api/login`, sans nom — et `route()` leve une
    // RouteNotFoundException AVANT que le rendu des erreurs n'intervienne.
    //
    // D'ou le symptome : la MEME route, non authentifiee, rend 401 avec
    // `Accept: application/json` et 500 sans. Le `shouldRenderJsonWhen` pose
    // plus bas declare pourtant du JSON pour tout `api/*` ; il ne pouvait rien,
    // puisque l'exception se produisait plus tot.
    //
    // Ce que ca coutait n'est pas qu'un code faux : un refus normal passait
    // pour une panne serveur. C'est ce qui m'a fait croire la production cassee
    // alors qu'elle allait bien, et chaque appel de ce genre remplissait le
    // journal — la seule fenetre de diagnostic sur une instance sans shell.
    //
    // `null` est explicitement prevu par le framework (Middleware.php,
    // `redirectGuestsTo` remplace null par `fn () => null`). Le gestionnaire
    // rend alors du JSON pour `api/*`, et un 401 sans corps ailleurs.
    $middleware->redirectGuestsTo(null);

    // Toute requete d'API porte une reference, y compris celles qui echouent
    // avant d'atteindre un controleur.
    $middleware->prependToGroup('api', \App\Http\Middleware\AssignRequestId::class);

    // Plafond general. Laravel 11 a retire le throttle pose d'office sur le
    // groupe api : sans cette ligne, l'API n'a aucune limite de debit.
    // Les limites plus serrees (connexion, ecritures publiques) se posent
    // route par route dans routes/api.php.
    $middleware->appendToGroup('api', 'throttle:api');

    $middleware->alias([
        'private-sales' => \App\Http\Middleware\EnsurePrivateSalesEnabled::class,
        'staff'      => \App\Http\Middleware\EnsureStaff::class,
        'capability' => \App\Http\Middleware\EnsureCapability::class,
        'partner' => \App\Http\Middleware\EnsurePartner::class,
        'partner.approved' => \App\Http\Middleware\EnsureApprovedPartner::class,
        'partner.space' => \App\Http\Middleware\EnsurePartnerSpaceOpen::class,
        'actif' => \App\Http\Middleware\EnsureActiveUser::class,
        'firebase'   => \App\Http\Middleware\VerifyFirebaseToken::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Le corps de requête trop lourd, dit en français.
        //
        // UN FILET, ET PLUS LE CHEMIN COURANT. ValidatePostSize lève
        // PostTooLargeException sur la condition
        // `CONTENT_LENGTH > post_max_size` ; public/index.php teste la MÊME
        // condition et rend la réponse avant `Request::capture()`, donc avant
        // que ce noyau existe. Tant que ce refus est là, ce rendu-ci ne
        // s'exécute pas en production. Pourquoi si tôt : capture() json_decode
        // le corps entier, et un corps JSON plus gros que post_max_size rendait
        // 500 AU CORPS VIDE après 135,67 s — la mesure est sur le refus
        // lui-même.
        //
        // Il reste parce que le retirer ferait dépendre le message français de
        // la place d'un seul `if` : un contrôleur frontal régénéré par une mise
        // à jour du squelette, et le vendeur relirait le message d'origine.
        // Mesure de celui-ci, dix photos de 2 072 238 o sur
        // POST /api/parts/1/media avec post_max_size=8M :
        //
        //     413 {"message":"The POST data is too large.", ...}
        //
        // En anglais, et sans dire ni ce qui a ete envoye ni ce qui est
        // accepte. Le vendeur ne peut rien en faire. C'est aussi ce chemin-ci
        // que la suite de tests traverse, PHPUnit amorçant le noyau sans passer
        // par public/index.php — voir PlafondsDeDepotTest.
        //
        // Le texte vient de PlafondsDeDepot et non d'ici, parce que les deux
        // autres chemins du même refus — le contrôleur frontal, et le corps
        // vidé sans Content-Length qui atteint MediaController — doivent rendre
        // le même : le même geste ne peut pas donner deux explications selon le
        // client HTTP employé.
        //
        // Ce plafond garde TOUTES les routes POST, pas seulement le depot
        // de photos, et c'est pourquoi le message parle de « l'envoi » sans
        // nommer les photos.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $envoye = $request->server('CONTENT_LENGTH');

            return response()->json([
                'message' => PlafondsDeDepot::messageCorpsTropLourd(
                    is_numeric($envoye) ? (int) $envoye : null,
                ),
            ], 413);
        });
        // La reference accompagne l'erreur jusqu'a l'ecran. Sans elle,
        // « Server Error » ne mene nulle part : c'est le seul fil entre ce que
        // voit l'utilisateur et ce qu'on peut lire dans le journal.
        $exceptions->respond(function (SymfonyResponse $response, \Throwable $e, Request $request) {
            $id = $request->attributes->get(\App\Http\Middleware\AssignRequestId::ATTRIBUTE);

            if ($id !== null && $response instanceof JsonResponse) {
                $payload = $response->getData(true);

                if (is_array($payload)) {
                    $payload['request_id'] = $id;
                    $response->setData($payload);
                }
            }

            return $response;
        });
    })->create();
