<?php

use App\Http\Controllers\Web\ArticleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pages de partage d'un article
|--------------------------------------------------------------------------
|
| Publiques et sans authentification : ces adresses sont collees dans des
| conversations, et lues d'abord par le robot de WhatsApp ou de Facebook, qui
| ne porte aucun jeton. Une page qui demanderait a s'identifier ne rendrait
| aucun apercu — et le partage n'aurait servi a rien.
|
| Chemins d'une lettre parce qu'ils voyagent a la main : un lien recopie dans
| un message, parfois lu a voix haute au telephone. Liaison de modele par
| identifiant, comme le reste du catalogue.
|
| HORS DU GROUPE 'web', ET C'EST LE POINT DE CE FICHIER.
|
| Le groupe 'web' pose StartSession et AddQueuedCookiesToResponse. Mesure sur
| GET /v/1 quand ces routes vivaient dans routes/web.php :
|
|     cache-control: max-age=300, public
|     set-cookie: XSRF-TOKEN=...
|     set-cookie: laravel-session=...; httponly
|
| Une reponse « public » qui pose un cookie de session est stockable par tout
| cache partage — mandataire d'entreprise, cache d'operateur, Cloudflare — qui
| peut ensuite la resservir a quelqu'un d'autre, cookie compris.
|
| Second effet, mesure lui aussi : config/session.php vaut
| env('SESSION_DRIVER', 'database') et render.yaml ne pose pas SESSION_DRIVER.
| Chaque visite anonyme ecrivait donc une ligne dans `sessions` (mesure : table
| a 0 ligne, puis 1 apres une visite). Un lien qui tourne dans un groupe de
| 200 personnes, c'est 200 lignes sur le Postgres gratuit — pour des pages qui
| n'ont aucun etat a retenir.
|
| Ces pages n'ont ni session, ni jeton CSRF, ni cookie a utiliser : elles
| rendent du HTML et rien d'autre. Un test assure l'absence de Set-Cookie,
| sans quoi le defaut reviendrait au premier refactor de routes.
|
| Le seul intergiciel conserve est le plafond de debit. Ce sont les premiers
| points d'entree publics qui interrogent la base hors de l'API, et les
| identifiants sont sequentiels donc enumerables.
|
| Mesure, une fiche dont les relations sont remplies (photos, categorie ou
| marque, modele, motorisation, lieu) :
|
|     /v -> 8 requetes SQL
|     /p -> 5 requetes SQL
|
| Soit 160 000 requetes SQL pour une boucle de /v/1 a /v/20000 sur l'instance
| gratuite. Les chiffres qui tenaient cette place — 7 et 3 — etaient faux, et
| celui de /p l'etait du double : le controleur charge QUATRE relations en plus
| de la fiche. Ils dependent des relations REMPLIES, une relation vide ne
| coutant aucune requete : mesure, la meme piece privee de son lieu descend a
| 4 requetes.
|
| Le plafond est volontairement large (voir AppServiceProvider) : il ne doit
| gener ni le robot ni un lien viral.
|
*/

Route::get('/v/{vehicle}', [ArticleController::class, 'vehicule'])->name('partage.vehicule');
Route::get('/p/{part}', [ArticleController::class, 'piece'])->name('partage.piece');
Route::get('/a/{accessory}', [ArticleController::class, 'accessoire'])->name('partage.accessoire');
