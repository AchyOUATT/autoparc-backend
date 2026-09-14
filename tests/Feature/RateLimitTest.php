<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
