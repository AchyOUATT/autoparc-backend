<?php

namespace Tests;

use App\Models\User;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeFirebaseAuthService;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authentifie un client mobile, comme le fait l'application Flutter.
     *
     * Substitue le service Firebase pour que le middleware `firebase` soit
     * reellement traverse : c'est lui qui provisionne le compte local au
     * premier appel, et c'est la chaine middleware → liaison de modele →
     * policy qui a deja repondu 403 sur tout le garage.
     *
     * Sans utilisateur passe, le compte est cree par le middleware lui-meme,
     * exactement comme a la premiere connexion d'un vrai client.
     */
    protected function actingAsClient(?User $user = null, string $uid = 'firebase-uid-test'): static
    {
        if ($user !== null) {
            $uid = $user->firebase_uid ?? $uid;
            $user->forceFill(['firebase_uid' => $uid])->save();
        }

        $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService([
            'uid'   => $uid,
            'email' => $user?->email ?? "{$uid}@exemple.test",
            'name'  => $user?->name ?? 'Client de test',
        ]));

        return $this->withHeader('Authorization', 'Bearer jeton-de-test');
    }
}
