<?php

namespace Tests\Support;

use App\Services\FirebaseAuthService;

/**
 * Remplace la verification Firebase le temps d'un test.
 *
 * Le vrai service lit un fichier de compte de service dans son constructeur :
 * il ne peut donc pas exister en integration continue, et le construire n'aurait
 * de toute facon aucun sens sans reseau. Ce double se substitue a lui dans le
 * conteneur, ce qui permet de traverser reellement le middleware `firebase`
 * plutot que de le desactiver — la chaine complete (middleware, liaison de
 * modele, policy) est donc celle du vrai serveur.
 *
 * Le constructeur parent n'est volontairement pas appele : la propriete `$auth`
 * reste non initialisee et n'est jamais lue, `verify()` etant redefinie.
 */
class FakeFirebaseAuthService extends FirebaseAuthService
{
    /** @param array{uid: string, email: ?string, name: ?string} $claims */
    public function __construct(private array $claims)
    {
        // Pas de parent::__construct() : voir le commentaire de classe.
    }

    public function verify(string $idToken): ?array
    {
        return $this->claims;
    }
}
