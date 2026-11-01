<?php

namespace Tests\Feature;

use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le declencheur externe des taches planifiees.
 *
 * Il existe parce que Render n'offre aucun plan gratuit pour les taches cron :
 * le render.yaml en declarait une avec `plan: free`, elle n'a donc jamais pu
 * exister, et rien ne le signalait. Aucun rappel d'entretien n'est jamais parti
 * pendant que des visites techniques etaient depassees depuis des semaines.
 *
 * Une route declenchable depuis l'exterieur merite ses tests, et le premier
 * d'entre eux est celui du secret absent. C'est le piege classique de ce genre
 * de garde : la variable d'environnement n'est pas definie, l'attendu vaut la
 * chaine vide, l'en-tete absent aussi, les deux sont egaux — et le declencheur
 * s'ouvre a tout le monde au moment precis ou l'on croyait l'avoir ferme.
 */
class ScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'un-secret-de-test-suffisamment-long';

    private function avecSecret(?string $secret = self::SECRET): void
    {
        config(['services.tasks.secret' => $secret]);
    }

    private function declencher(?string $jeton, string $tache = 'reminders', array $corps = [])
    {
        return $this->postJson(
            "/api/tasks/{$tache}",
            $corps,
            $jeton === null ? [] : ['X-Task-Token' => $jeton],
        );
    }

    public static function tachesExposees(): array
    {
        return [
            'rappels' => ['reminders', 'reminders:maintenance'],
            'consommation' => ['fuel', 'alerts:fuel-anomaly'],
        ];
    }

    public static function nomsTaches(): array
    {
        return array_map(fn (array $tache) => [$tache[0]], self::tachesExposees());
    }

    // ── La garde ─────────────────────────────────────────────────────

    #[DataProvider('nomsTaches')]
    public function test_sans_secret_configure_la_route_est_fermee(string $tache): void
    {
        // Le cas qui compte. Un oubli de variable d'environnement doit fermer
        // la porte, jamais l'ouvrir — y compris quand l'appelant n'envoie rien.
        $this->avecSecret(null);
        Artisan::shouldReceive('call')->never();

        $this->declencher(null, $tache)->assertStatus(401);
        $this->declencher('', $tache)->assertStatus(401);
        $this->declencher('nimporte quoi', $tache)->assertStatus(401);
    }

    #[DataProvider('nomsTaches')]
    public function test_un_jeton_absent_ou_faux_est_refuse(string $tache): void
    {
        // Trois tentatives seulement : le plafond de cadence compte AUSSI les
        // appels refuses, ce qui est voulu — c'est ce qui freine un essai de
        // force brute sur le jeton.
        $this->avecSecret();
        Artisan::shouldReceive('call')->never();

        $this->declencher(null, $tache)->assertStatus(401);
        $this->declencher('', $tache)->assertStatus(401);
        $this->declencher('presque-le-bon', $tache)->assertStatus(401);
    }

    public function test_un_prefixe_correct_ne_suffit_pas(): void
    {
        $this->avecSecret();

        $this->declencher(substr(self::SECRET, 0, 10))->assertStatus(401);
    }

    public function test_le_bon_jeton_lance_la_tache(): void
    {
        $this->avecSecret();

        $this->declencher(self::SECRET)
            ->assertOk()
            ->assertJsonPath('task', 'reminders')
            ->assertJsonPath('command', 'reminders:maintenance')
            ->assertJsonPath('exit_code', 0)
            ->assertJsonPath('dry_run', false);
    }

    public function test_la_sortie_de_la_commande_est_renvoyee(): void
    {
        // C'est elle qui s'affiche dans le journal GitHub Actions : sans elle,
        // on saurait que la tache a tourne mais pas ce qu'elle a fait — et on
        // retomberait dans le defaut qu'on vient de corriger.
        $this->avecSecret();

        $sortie = $this->declencher(self::SECRET)->json('output');

        $this->assertIsString($sortie);
        $this->assertStringContainsString('rappel', mb_strtolower($sortie));
    }

    public function test_le_mode_a_blanc_se_demande_dans_le_corps(): void
    {
        // Pour verifier le cablage sans rien envoyer a personne : c'est le
        // premier appel qu'on fait apres avoir pose le secret.
        $this->avecSecret();

        $reponse = $this->declencher(self::SECRET, corps: ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('dry_run', true);

        $this->assertStringContainsString("n'a ete emis", $reponse->json('output'));
    }

    #[DataProvider('tachesExposees')]
    public function test_chaque_tache_exposee_transmet_le_mode_a_blanc_sans_notification(string $tache, string $commande): void
    {
        $this->avecSecret();
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldNotReceive('sendToToken');
            $mock->shouldNotReceive('sendToTokens');
        });
        Artisan::shouldReceive('call')->once()->with($commande, ['--dry-run' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn("Rien n'a ete emis.\n");

        $this->declencher(self::SECRET, $tache, ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('task', $tache)
            ->assertJsonPath('command', $commande)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('exit_code', 0)
            ->assertJsonPath('output', "Rien n'a ete emis.");
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_une_tache_inconnue_n_est_pas_une_commande_arbitraire(): void
    {
        // La route ne doit jamais devenir un « lance ce que tu veux » : seuls
        // les noms declares passent, et la contrainte est posee sur la route
        // elle-meme.
        $this->avecSecret();
        Artisan::shouldReceive('call')->never();

        $this->declencher(self::SECRET, tache: 'migrate-fresh')->assertNotFound();
        $this->declencher(self::SECRET, tache: 'db:wipe')->assertNotFound();
    }

    public function test_le_declencheur_est_plafonne(): void
    {
        // Trois par minute : un ordonnanceur appelle une fois par jour, et la
        // tache balaie le parc entier.
        $this->avecSecret();
        Artisan::shouldReceive('call')->times(3)->withAnyArgs()->andReturn(0);
        Artisan::shouldReceive('output')->times(3)->andReturn('Tache terminee.');

        for ($i = 0; $i < 3; $i++) {
            $this->declencher(self::SECRET, $i % 2 === 0 ? 'reminders' : 'fuel')->assertOk();
        }

        $this->declencher(self::SECRET, 'fuel')->assertStatus(429);
    }

    public function test_un_echec_de_la_commande_remonte_en_erreur(): void
    {
        // Un ordonnanceur qui recoit 200 quoi qu'il arrive ne sert a rien :
        // GitHub Actions doit virer au rouge quand la tache echoue.
        $this->avecSecret();

        Artisan::command('reminders:maintenance {--dry-run}', fn () => 1);

        $this->declencher(self::SECRET)
            ->assertStatus(500)
            ->assertJsonPath('exit_code', 1);
    }

    public function test_un_echec_de_l_alerte_carburant_remonte_en_erreur(): void
    {
        $this->avecSecret();
        Artisan::shouldReceive('call')->once()->with('alerts:fuel-anomaly', [])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Echec de la tache.');

        $this->declencher(self::SECRET, 'fuel')
            ->assertStatus(500)
            ->assertJsonPath('task', 'fuel')
            ->assertJsonPath('exit_code', 1);
    }
}
