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
    public function __construct(protected FirebaseAuthService $firebase)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Token Firebase manquant.');
        }

        $claims = $this->firebase->verify($token);

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

        return $next($request);
    }

    /** Retrouve le compte local par firebase_uid, sinon par email, sinon le cree. */
    protected function resolveUser(array $claims): User
    {
        $user = User::where('firebase_uid', $claims['uid'])->first();

        if ($user) {
            return $user;
        }

        // Compte cree par le staff avant la premiere connexion mobile (rare, mais possible)
        // : on rattache le firebase_uid a la fiche existante plutot que d'en creer une seconde.
        if ($claims['email'] && $existing = User::where('email', $claims['email'])->first()) {
            $existing->update(['firebase_uid' => $claims['uid']]);

            return $existing;
        }

        return User::create([
            'name'         => $claims['name'] ?? 'Client',
            'email'        => $claims['email'] ?? $claims['uid'].'@firebase.local',
            'password'     => Hash::make(Str::random(40)), // jamais utilise : auth via Firebase uniquement
            'role'         => UserRole::Client,
            'firebase_uid' => $claims['uid'],
            'is_active'    => true,
        ]);
    }
}
