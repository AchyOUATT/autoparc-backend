<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
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

    // Toute requete d'API porte une reference, y compris celles qui echouent
    // avant d'atteindre un controleur.
    $middleware->prependToGroup('api', \App\Http\Middleware\AssignRequestId::class);

    // Plafond general. Laravel 11 a retire le throttle pose d'office sur le
    // groupe api : sans cette ligne, l'API n'a aucune limite de debit.
    // Les limites plus serrees (connexion, ecritures publiques) se posent
    // route par route dans routes/api.php.
    $middleware->appendToGroup('api', 'throttle:api');

    $middleware->alias([
        'staff'      => \App\Http\Middleware\EnsureStaff::class,
        'capability' => \App\Http\Middleware\EnsureCapability::class,
        'firebase'   => \App\Http\Middleware\VerifyFirebaseToken::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

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
