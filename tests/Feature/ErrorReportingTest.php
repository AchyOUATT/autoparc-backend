<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignRequestId;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Mockery;
use Tests\TestCase;

/**
 * Ce que l'API dit quand elle echoue.
 *
 * Le defaut le plus couteux du projet n'etait pas une panne, mais une panne
 * muette : « Server Error » sans cause ni reference, et « jeton invalide »
 * quand le serveur lui-meme ne pouvait pas verifier. On a cherche des heures
 * du mauvais cote. Ces tests figent le contraire.
 */
class ErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Comme en production : la cause detaillee ne doit pas fuir au client,
        // c'est justement pourquoi il faut une reference.
        config(['app.debug' => false]);
    }

    // ── Reference de requete ─────────────────────────────────────────────────

    public function test_chaque_reponse_porte_une_reference(): void
    {
        $response = $this->getJson('/api/catalog/vehicles')->assertOk();

        $this->assertNotEmpty($response->headers->get(AssignRequestId::HEADER));
    }

    public function test_une_erreur_serveur_porte_la_reference_dans_le_corps(): void
    {
        Route::middleware('api')->get('/api/_test_boom', fn () => throw new \RuntimeException('boum'));

        $response = $this->getJson('/api/_test_boom')->assertStatus(500);

        $reference = $response->json('request_id');

        $this->assertNotEmpty($reference, 'Sans reference, « Server Error » ne mene nulle part.');
        $this->assertSame(
            $response->headers->get(AssignRequestId::HEADER),
            $reference,
            'En-tete et corps doivent porter la meme reference.',
        );
        // La cause reste hors de la reponse : elle vit dans le journal.
        $this->assertSame('Server Error', $response->json('message'));
    }

    public function test_une_erreur_de_validation_porte_aussi_la_reference(): void
    {
        $response = $this->postJson('/api/needs', [])->assertStatus(422);

        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_une_reference_fournie_par_l_appelant_est_conservee(): void
    {
        $this->withHeader(AssignRequestId::HEADER, 'trace-du-client')
            ->getJson('/api/catalog/vehicles')
            ->assertOk()
            ->assertHeader(AssignRequestId::HEADER, 'trace-du-client');
    }

    // ── Panne serveur contre jeton invalide ──────────────────────────────────

    /**
     * Le cas qui a coute le plus cher : PHP sans bundle de certificats, donc
     * incapable de telecharger les cles publiques de Google.
     */
    public function test_une_verification_impossible_repond_503_et_non_401(): void
    {
        $this->app->instance(
            FirebaseAuthService::class,
            new FirebaseAuthService($this->authQuiEchoue(new \RuntimeException(
                'cURL error 60: SSL certificate problem: unable to get local issuer certificate'
            ))),
        );

        $response = $this->withHeader('Authorization', 'Bearer jeton')
            ->getJson('/api/my/vehicles');

        $response->assertStatus(503);

        $this->assertStringNotContainsStringIgnoringCase(
            'expire',
            $response->json('message'),
            'Un 401 enverrait le client se reconnecter en boucle pour une panne serveur.',
        );

        // La cause technique ne fuit pas, mais la reference permet de la retrouver.
        $this->assertStringNotContainsString('cURL', $response->getContent());
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_un_jeton_reellement_refuse_repond_401(): void
    {
        $this->app->instance(
            FirebaseAuthService::class,
            // Ce que leve la bibliotheque quand le jeton lui-meme est en cause.
            new FirebaseAuthService($this->authQuiEchoue(new FailedToVerifyToken('Le jeton a expire.'))),
        );

        $this->withHeader('Authorization', 'Bearer jeton')
            ->getJson('/api/my/vehicles')
            ->assertStatus(401);
    }

    public function test_un_jeton_absent_le_dit_clairement(): void
    {
        $this->getJson('/api/my/vehicles')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token Firebase manquant.');
    }

    /**
     * Un refus d'authentification ne depend pas du compte de service Firebase.
     *
     * Le middleware recevait le service par son constructeur : le conteneur le
     * construisait donc a chaque passage, y compris pour une requete sans
     * jeton, et ce constructeur lit `storage/app/firebase/service-account.json`.
     * En integration continue, ou ce fichier n'existe pas, trois tests
     * mouraient sur « Failed to open stream » au lieu de recevoir leur 401 —
     * la suite backend etait rouge depuis le 13 septembre sans que personne
     * s'en apercoive. En production, un fichier absent ou illisible aurait
     * transforme chaque refus en erreur 500.
     */
    public function test_une_requete_sans_jeton_est_refusee_meme_sans_identifiants(): void
    {
        $this->app->bind(FirebaseAuthService::class, function () {
            throw new \RuntimeException('Compte de service Firebase illisible.');
        });

        $this->getJson('/api/my/vehicles')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token Firebase manquant.');
    }

    /**
     * Le conteneur instancie ce service sans argument a chaque requete /my/*.
     *
     * Le parametre de constructeur ajoute pour les tests doit donc rester
     * facultatif : le rendre obligatoire casserait toute la zone client en
     * production, la ou aucun test ne passe par le conteneur.
     */
    public function test_le_service_firebase_reste_constructible_sans_argument(): void
    {
        $constructeur = new \ReflectionMethod(FirebaseAuthService::class, '__construct');

        $this->assertSame(0, $constructeur->getNumberOfRequiredParameters());
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * Un client Firebase dont la verification echoue avec l'exception donnee.
     *
     * On remplace la dependance, pas la methode : c'est le tri fait dans
     * `verify()` que l'on veut eprouver, et un double qui redefinirait
     * `verify()` sauterait precisement le code teste.
     */
    private function authQuiEchoue(\Throwable $e): FirebaseAuth
    {
        $auth = Mockery::mock(FirebaseAuth::class);
        $auth->shouldReceive('verifyIdToken')->andThrow($e);

        return $auth;
    }
}
