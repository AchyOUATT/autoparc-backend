<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Limites de debit.
 *
 * L'API n'en avait aucune : Laravel 11 a retire le `throttle` pose d'office
 * sur le groupe api, et rien ne l'a remplace. Le mot de passe administrateur
 * pouvait donc se deviner a la cadence du reseau — et ce compte possede
 * toutes les capacites, ce qui rend le reste du travail sur les roles sans
 * effet face a une attaque automatisee.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function tenterConnexion(string $email, string $motDePasse = 'mauvais'): int
    {
        return $this->postJson('/api/login', [
            'email'    => $email,
            'password' => $motDePasse,
        ])->getStatusCode();
    }

    // ── Connexion ────────────────────────────────────────────────────────────

    public function test_les_tentatives_de_connexion_sont_plafonnees(): void
    {
        User::factory()->create(['email' => 'admin@autoparc.bf', 'role' => 'admin']);

        // Dix essais passent (et echouent sur le mot de passe : 422).
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(422, $this->tenterConnexion('admin@autoparc.bf'), "essai {$i}");
        }

        // Le onzieme est refuse avant meme d'etre examine.
        $this->assertSame(429, $this->tenterConnexion('admin@autoparc.bf'));
    }

    public function test_le_refus_explique_quoi_faire(): void
    {
        User::factory()->create(['email' => 'admin@autoparc.bf', 'role' => 'admin']);

        for ($i = 1; $i <= 10; $i++) {
            $this->tenterConnexion('admin@autoparc.bf');
        }

        $response = $this->postJson('/api/login', [
            'email' => 'admin@autoparc.bf', 'password' => 'mauvais',
        ]);

        $response->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertStringContainsString('Patientez', $response->json('message'));
    }

    /**
     * La cle inclut l'adresse : sans cela, un tiers bloquerait la connexion
     * d'un employe en echouant a sa place.
     */
    public function test_le_blocage_ne_vise_pas_le_compte_mais_le_couple_compte_adresse(): void
    {
        User::factory()->create(['email' => 'admin@autoparc.bf', 'role' => 'admin']);
        User::factory()->create(['email' => 'manager@autoparc.bf', 'role' => 'manager']);

        for ($i = 1; $i <= 11; $i++) {
            $this->tenterConnexion('admin@autoparc.bf');
        }

        // Le second compte reste joignable depuis la meme machine, jusqu'au
        // plafond par adresse.
        $this->assertSame(422, $this->tenterConnexion('manager@autoparc.bf'));
    }

    /** Changer d'adresse e-mail a chaque essai contourne la premiere limite ; pas la seconde. */
    public function test_un_balayage_de_comptes_depuis_une_meme_adresse_est_plafonne(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->tenterConnexion("compte{$i}@exemple.test");
        }

        $this->assertSame(429, $this->tenterConnexion('compte21@exemple.test'));
    }

    public function test_une_connexion_valide_passe(): void
    {
        User::factory()->create([
            'email'    => 'manager@autoparc.bf',
            'role'     => 'manager',
            'password' => bcrypt('un-mot-de-passe-correct'),
        ]);

        $this->postJson('/api/login', [
            'email'    => 'manager@autoparc.bf',
            'password' => 'un-mot-de-passe-correct',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    // ── Ecritures ouvertes a tous ────────────────────────────────────────────

    /**
     * Chaque besoin soumis notifie l'ensemble du personnel : sans plafond,
     * n'importe qui peut noyer les notifications de l'equipe.
     */
    public function test_la_soumission_de_besoins_est_plafonnee(): void
    {
        $besoin = [
            'type'           => 'part',
            'description'    => 'Recherche plaquettes de frein',
            'contact_name'   => 'Awa',
            'contact_phone'  => '+22600000000',
        ];

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/needs', $besoin)->assertSuccessful();
        }

        $this->postJson('/api/needs', $besoin)->assertStatus(429);
    }

    // ── Adresse reelle du client ─────────────────────────────────────────────

    /**
     * Derriere le repartiteur de Render, toutes les requetes arrivent de la
     * meme adresse. Si Laravel s'y fie, les limites par adresse deviennent
     * globales : le premier visiteur a atteindre le plafond bloque tous les
     * autres. Autrement dit, sans mandataires de confiance, ces limites
     * seraient pires qu'inutiles.
     */
    public function test_l_adresse_du_client_est_lue_derriere_le_repartiteur(): void
    {
        User::factory()->create(['email' => 'admin@autoparc.bf', 'role' => 'admin']);

        // Onze echecs depuis une premiere adresse : au-dela du plafond.
        for ($i = 1; $i <= 11; $i++) {
            $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '41.202.10.5'])
                ->tenterConnexion('admin@autoparc.bf');
        }

        // Une autre adresse n'est pas concernee par ce blocage.
        $statut = $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '41.202.10.9'])
            ->postJson('/api/login', ['email' => 'admin@autoparc.bf', 'password' => 'mauvais'])
            ->getStatusCode();

        $this->assertSame(422, $statut, 'Les limites par adresse doivent distinguer les clients.');
    }

    // ── L'en-tete forge, et ce qui y resiste ─────────────────────────────────

    /**
     * LE DEFAUT, ET SA CORRECTION.
     *
     * Le test precedent montre une propriete necessaire : derriere Render, il
     * FAUT lire `X-Forwarded-For`, sinon toutes les requetes paraissent venir
     * de la meme adresse et le premier visiteur a atteindre le plafond bloque
     * tous les autres. `trustProxies(at: '*')` est donc assume.
     *
     * Mais ce qui est lu est, par construction, ecrit par l'appelant. Et
     * `at: '*'` ne permet pas de distinguer l'en-tete pose par le repartiteur
     * de Render de celui qu'un attaquant ajoute lui-meme. Toute limite dont la
     * cle contient `$request->ip()` est donc contournable en changeant d'en-tete
     * a chaque essai — ce qui, avant cette correction, laissait le mot de passe
     * d'un administrateur devinable a la cadence du reseau, le plafond affiche
     * n'y changeant rien.
     *
     * On ne peut pas resserrer la cle par adresse sans casser le cas Render
     * legitime. La reponse est donc une cle que l'en-tete NE TOUCHE PAS : le
     * compte vise. Trente essais par minute sur un compte donne, quelle que
     * soit l'adresse d'ou ils viennent.
     *
     * Ce test fait exactement ce qu'un attaquant ferait : une adresse neuve a
     * chaque essai.
     */
    public function test_un_compte_vise_depuis_mille_adresses_reste_plafonne(): void
    {
        User::factory()->create(['email' => 'admin@autoparc.bf', 'role' => 'admin']);

        // Trente essais, trente adresses differentes. Les limites par adresse
        // (10 par couple compte|adresse, 20 par adresse) sont donc toutes
        // neuves a chaque tour : aucune ne mord.
        for ($i = 1; $i <= AppServiceProvider::LOGIN_PAR_COMPTE_PAR_MINUTE; $i++) {
            $statut = $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => "41.202.{$i}.{$i}"])
                ->tenterConnexion('admin@autoparc.bf');

            $this->assertSame(422, $statut, "L'essai {$i} aurait du etre examine.");
        }

        // Le suivant, depuis une adresse encore inedite, est refuse quand meme.
        $this->assertSame(
            429,
            $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '197.158.200.1'])
                ->tenterConnexion('admin@autoparc.bf'),
            "Changer d'en-tete a chaque essai suffit a deviner un mot de passe : "
            .'la limitation ne tient que par des cles que le client ne controle pas.'
        );
    }

    /**
     * Le cablage, sur les deux portes publiques a la fois.
     *
     * Atteindre 120 soumissions de besoin dans un test couterait plusieurs
     * secondes pour prouver ce qu'une comparaison de cles etablit exactement :
     * chaque limiteur public doit porter AU MOINS UNE cle identique pour deux
     * appelants d'adresses differentes. Sans elle, il n'existe aucun plafond
     * qu'un en-tete ne desarme.
     */
    #[DataProvider('limiteursPublics')]
    public function test_chaque_limiteur_public_porte_une_cle_hors_d_atteinte_de_l_en_tete(
        string $limiteur,
        array $corps,
    ): void {
        $cles = fn (string $adresse) => array_map(
            fn (Limit $limite) => $limite->key,
            Arr::wrap(call_user_func(
                RateLimiter::limiter($limiteur),
                Request::create('/', 'POST', $corps, server: ['REMOTE_ADDR' => $adresse]),
            )),
        );

        $communes = array_values(array_intersect($cles('41.202.10.5'), $cles('197.158.0.1')));

        $this->assertNotEmpty($communes, sprintf(
            'Toutes les cles du limiteur « %s » dependent de l adresse : un en-tete '
            .'X-Forwarded-For different a chaque requete le desarme entierement.',
            $limiteur
        ));
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function limiteursPublics(): array
    {
        return [
            'connexion'         => ['login', ['email' => 'admin@autoparc.bf']],
            'ecriture publique' => ['public-write', []],
        ];
    }

    // ── Plafond general ──────────────────────────────────────────────────────

    /**
     * Verifie le cablage plutot que le seuil : atteindre 120 requetes dans un
     * test couterait plusieurs secondes pour prouver ce qu'une assertion sur
     * le groupe etablit exactement.
     */
    public function test_le_groupe_api_porte_un_plafond_general(): void
    {
        $groupes = app(\Illuminate\Foundation\Http\Kernel::class)->getMiddlewareGroups();

        $this->assertContains('throttle:api', $groupes['api'] ?? [], <<<'TXT'
            Sans cette entree, l'API n'a aucune limite de debit : Laravel 11 ne
            la pose plus d'office.
            TXT);
    }
}
