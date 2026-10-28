<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\FirebaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie les requetes de l'app Flutter via un ID token Firebase
 * (Authorization: Bearer <token>). Provisionne automatiquement le compte
 * local au premier appel : jamais de mot de passe, jamais de role staff.
 */
class VerifyFirebaseToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Token Firebase manquant.');
        }

        // Le service n'est resolu qu'ici, une fois le jeton trouve. Injecte
        // dans le constructeur, il etait construit a chaque passage du
        // middleware — et son constructeur lit le fichier de compte de
        // service. Une requete sans jeton dependait donc d'un fichier dont
        // elle n'avait aucun besoin : en integration continue, ou ce fichier
        // n'existe pas, les trois tests « une requete sans jeton est refusee »
        // mouraient sur « Failed to open stream » au lieu de recevoir leur
        // 401. En production, un fichier absent ou illisible aurait transforme
        // chaque refus d'authentification en erreur 500.
        $claims = app(FirebaseAuthService::class)->verify($token);

        if (! $claims || ! $claims['uid']) {
            abort(401, 'Token Firebase invalide ou expire.');
        }

        $user = $this->resolveUser($claims);

        if (! $user->is_active) {
            abort(403, 'Compte desactive.');
        }

        $request->setUserResolver(fn () => $user);
        // Nécessaire pour que Auth::user() et Gate (authorize()) trouvent l'utilisateur.
        \Auth::setUser($user);

        // Les notifications client sont adressees au firebase_uid, pas a l'id
        // local : NotificationController le lit ici. L'attribut n'etait jamais
        // pose — la moitie manquante d'un contrat que personne ne verifiait.
        // Consequence : unread-count repondait 500 (scopeForClient refuse null)
        // et marquer une notification lue repondait 403, la comparaison se
        // faisant contre null. La cloche n'a donc jamais fonctionne cote
        // client, sans que rien ne le signale ailleurs que dans les logs.
        $request->attributes->set('firebase_uid', $claims['uid']);

        // `firebase_verified_phone` A ETE RETIRE D'ICI, et il ne faut pas le
        // remettre sans savoir ce qu'il portait.
        //
        // Cet attribut etait la PREUVE de possession d'un numero : la
        // revendication `phone_number` d'un jeton Firebase, obtenue par une
        // verification SMS, et le module d'annonces entre particuliers ne
        // lisait le numero du vendeur que la — jamais le formulaire, jamais
        // `User.phone`. La verification par SMS est abandonnee au profit d'un
        // appel passe par le personnel pendant la moderation, et
        // `verifyPhoneNumber` n'a plus aucun appelant dans l'application.
        //
        // L'attribut ne serait donc plus qu'une case toujours nulle, qui a
        // l'air d'une preuve. Un mecanisme de confiance qui survit a ce qui le
        // fondait est pire que son absence : quelqu'un s'y adosserait. La
        // preuve vit maintenant dans deux colonnes que le formulaire ne peut
        // pas ecrire, `verified_phone` et `phone_verified_at`, posees par le
        // seul `approve()`. Voir docs/ventes-particuliers.md.

        return $next($request);
    }

    /** Retrouve le compte local par firebase_uid, sinon par email, sinon le cree. */
    protected function resolveUser(array $claims): User
    {
        $user = User::where('firebase_uid', $claims['uid'])->first();

        if ($user) {
            abort_unless($user->isClient(), 403, 'Ce compte ne peut pas utiliser la connexion client Firebase.');

            return $user;
        }

        // Compte cree par le staff avant la premiere connexion mobile (rare, mais possible)
        // : on rattache le firebase_uid a la fiche existante plutot que d'en creer une seconde.
        if ($claims['email'] && $existing = User::whereRaw('LOWER(email) = ?', [mb_strtolower($claims['email'])])->first()) {
            // Verifier AVANT le rattachement : un email Firebase ne transforme
            // jamais un employe ou une entreprise en compte client.
            abort_unless($existing->isClient(), 403, 'Ce compte ne peut pas utiliser la connexion client Firebase.');
            $existing->update(['firebase_uid' => $claims['uid']]);

            return $existing;
        }

        return User::create([
            'name' => $claims['name'] ?? 'Client',
            'email' => $claims['email'] ?? $claims['uid'].'@firebase.local',
            'password' => Hash::make(Str::random(40)), // jamais utilise : auth via Firebase uniquement
            'role' => UserRole::Client,
            'firebase_uid' => $claims['uid'],
            'is_active' => true,
        ]);
    }
}
