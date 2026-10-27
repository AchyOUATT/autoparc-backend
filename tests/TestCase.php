<?php

namespace Tests;

use App\Enums\UserRole;
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
            // Ce helper prepare un compte client reel. Les tests de refus des
            // autres roles injectent leur jeton Firebase directement.
            $user->forceFill(['firebase_uid' => $uid, 'role' => UserRole::Client])->save();
        }

        $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService([
            'uid' => $uid,
            'email' => $user?->email ?? "{$uid}@exemple.test",
            'name' => $user?->name ?? 'Client de test',
        ]));

        return $this->withHeader('Authorization', 'Bearer jeton-de-test');
    }

    /**
     * Un numero DISTINCT par compte partenaire.
     *
     * Le numero identifie un partenaire depuis que le courriel est facultatif,
     * et `partner_accounts.phone_key` est unique. Les decors qui donnaient
     * « +22670000000 » a chaque compte creaient donc un doublon des le
     * deuxieme — ce qui est precisement ce que l'unicite doit interdire.
     *
     * Le derive de l'identifiant de l'utilisateur plutot que d'un aleatoire :
     * un banc qui tombe doit tomber de la meme facon a chaque execution.
     */
    protected function numeroDePartenaire(int $userId): string
    {
        return '+2267'.str_pad((string) $userId, 7, '0', STR_PAD_LEFT);
    }
}
