<?php

namespace App\Services;

use App\Exceptions\FirebaseUnavailableException;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Factory;
use Lcobucci\JWT\Token\Parser;

/**
 * Verifie les ID tokens emis par Firebase Auth cote client (app Flutter).
 * Ne gere ni inscription ni mot de passe : Firebase s'en charge entierement.
 */
class FirebaseAuthService
{
    protected FirebaseAuth $auth;

    /**
     * @param FirebaseAuth|null $auth Injecte par les tests. En production le
     *     service se construit seul : c'est le conteneur qui l'instancie, sans
     *     argument. Le parametre existe pour pouvoir eprouver le tri entre
     *     « jeton refuse » et « verification impossible » — la distinction qui
     *     manquait — sans dependre du reseau ni d'un compte de service.
     */
    public function __construct(?FirebaseAuth $auth = null)
    {
        $this->auth = $auth ?? (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'))
            ->createAuth();
    }

    /**
     * Supprime definitivement un compte Firebase.
     *
     * Appele par la suppression de compte : effacer nos donnees sans effacer
     * l'identite laisserait un compte capable de se reconnecter, ce qui n'est
     * pas ce qu'on a demande.
     *
     * Laisse remonter l'exception : l'appelant decide quoi en faire, et il a
     * deja supprime les donnees locales quand il arrive ici.
     */
    public function deleteUser(string $uid): void
    {
        $this->auth->deleteUser($uid);
    }

    /**
     * Verifie un ID token et retourne ses informations (uid, email, name...).
     * Retourne null si le token est invalide, expire, ou mal signe.
     */
    public function verify(string $idToken): ?array
    {
        try {
            $verified = $this->auth->verifyIdToken($idToken);
        } catch (FailedToVerifyToken $e) {
            // Jeton reellement refuse : signature, audience, horloge, revocation.
            // La raison etait perdue — le middleware repondait « invalide ou
            // expire » quel que soit le motif. Sans elle, le diagnostic prend
            // des heures. Le jeton lui-meme n'est jamais journalise.
            Log::warning('[Firebase] Verification du jeton refusee', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            // Tout le reste : reseau coupe, certificats absents, compte de
            // service illisible, Google injoignable. Le client n'y est pour
            // rien, et lui repondre 401 l'envoie se reconnecter en boucle.
            //
            // C'est exactement ce qui s'est produit : cURL error 60, faute de
            // bundle de certificats cote PHP. Cette exception-la n'etait meme
            // pas attrapee — elle ressortait en 500 « Server Error », sans
            // cause, en production.
            Log::error('[Firebase] Verification impossible', [
                'reason'    => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw new FirebaseUnavailableException($e);
        }

        $claims = $verified->claims();

        return [
            'uid'            => $claims->get('sub'),
            'email'          => $claims->get('email'),
            'email_verified' => $claims->get('email_verified', false),
            'name'           => $claims->get('name'),
        ];
    }
}
