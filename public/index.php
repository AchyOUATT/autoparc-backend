<?php

use App\Support\PlafondsDeDepot;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Le refus d'un corps trop lourd, ICI ET PAS AILLEURS.
//
// Ce n'est pas la place naturelle d'une règle de ce genre, et c'est la seule
// qui marche. `Request::capture()`, en bas de ce fichier, appelle
// `Illuminate\Http\Request::createFromBase()`, qui fait
//
//     if ($newRequest->isJson()) {
//         $newRequest->request->replace($newRequest->json()->all());
//
// — un json_decode du corps ENTIER, avant le noyau, donc avant le premier
// intergiciel. Et `php artisan serve`, qui sert la production (Dockerfile),
// garde tout le corps dans le tampon de son SAPI : php://input le rend en
// entier MÊME quand PHP a refusé le corps pour dépassement de post_max_size.
//
// Mesure par HTTP réel, post_max_size=24M, memory_limit=192M, corps JSON de
// 94 371 840 o sur POST /api/login, route NON AUTHENTIFIÉE :
//
//     sans ce refus                        -> 500 au corps VIDE, 135,67 s
//     avec un intergiciel en tête de pile  -> 500 au corps VIDE, 114,35 s
//     avec ce refus-ci                     -> 413 « L'envoi pese 90 Mo [...] »
//
// L'essai avec l'intergiciel a été fait, et abandonné : la pile globale tourne
// APRÈS capture(). post_max_size ne bornait donc pas la mémoire d'un corps
// JSON — ce qui la bornait, c'était la patience de l'appelant.
//
// LA MÉMOIRE NE DÉPEND PLUS DE LA TAILLE DU CORPS, parce que Laravel n'est
// jamais amorcé sur ce chemin. Mesures, mêmes plafonds :
//
//      94 371 836 o urlencoded -> 413, pic 7 911 256 o,  79,24 s
//      94 371 840 o json       -> 413, pic 7 911 216 o, 149,69 s
//     201 326 592 o json       -> 413, pic 7 911 216 o, 628,19 s
//
// CE QUE CE REFUS NE RATTRAPE PAS : le temps, et les trois lignes ci-dessus le
// disent. Le SAPI a déjà lu tout le corps quand celle-ci s'exécute. La lecture
// n'est pas bloquante — `GET /up` sondé pendant un refus de 50 331 648 o a
// répondu en 0,53 s —, mais l'appelant attend, et rien dans PHP ne borne cette
// attente. Seul un vrai serveur devant l'application, refusant sur l'en-tête
// sans lire la suite, le bornerait.
if (($corpsTropLourd = PlafondsDeDepot::depassementAnnonce($_SERVER['CONTENT_LENGTH'] ?? null)) !== null) {
    http_response_code(413);
    header('Content-Type: application/json');

    // json_encode nu, sans drapeau : c'est ce que rend `response()->json()` sur
    // les deux autres chemins du même refus — mesure, le 413 de ValidatePostSize
    // porte « L'envoi pese [...] » avec une apostrophe littérale, donc sans
    // JSON_HEX_APOS. Et le message est en ASCII : messageCorpsTropLourd
    // n'appelle que enMo, qui n'émet ni accent ni espace insécable.
    echo json_encode(['message' => PlafondsDeDepot::messageCorpsTropLourd($corpsTropLourd)]);

    exit;
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
