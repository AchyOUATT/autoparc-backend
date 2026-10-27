<?php

namespace Tests\Feature;

use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * La reprise des numeros existants, quand deux comptes en partagent un.
 *
 * ── POURQUOI CE BANC EXISTE ───────────────────────────────────────────────
 *
 * Le numero devient l'identifiant de connexion d'un partenaire. Attribuer le
 * numero au MAUVAIS compte d'un groupe de doublons donnerait donc l'acces d'un
 * vendeur a un autre : c'est la faute la plus grave que cette reprise puisse
 * commettre, et elle ne se verrait pas.
 *
 * ── POURQUOI LA MIGRATION N'ARRETE PAS LE DEPLOIEMENT ─────────────────────
 *
 * Elle a d'abord leve une exception sur un doublon. Le Dockerfile enchaine
 * `php artisan migrate --force && … && php artisan serve` : une exception
 * arrete donc la chaine AVANT le serveur, et le site ne demarre pas. L'offre
 * Render employee ici n'ouvre aucun shell, donc personne ne pourrait
 * departager les comptes. Proteger la donnee en eteignant la boutique echange
 * un probleme contre un pire.
 *
 * Le plus ANCIEN compte garde le numero. Les autres gardent leur courriel —
 * l'adresse etait obligatoire jusqu'a ce lot, donc ils en ont tous un, et
 * aucun partenaire existant ne perd l'acces.
 */
class MigrationDesNumerosPartenairesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * On appelle la methode plutot que la migration : celle-ci est deja jouee
     * quand le banc demarre, et la rejouer tenterait de recreer la colonne.
     */
    private function rejouerLaReprise(): void
    {
        $migration = require database_path(
            'migrations/2026_10_27_000003_identifier_un_partenaire_par_son_numero.php'
        );

        $migration->attribuerLesCles();
    }

    /**
     * Les lignes passent par `DB::table` et non par le modele : son crochet
     * `saving` remplirait la cle, donc le decor ne reproduirait pas l'etat
     * d'avant la migration.
     */
    private function ligne(string $numero, string $nom): int
    {
        $user = User::factory()->create(['role' => 'partner']);

        return DB::table('partner_accounts')->insertGetId([
            'user_id' => $user->id,
            'company_name' => $nom,
            'contact_name' => $nom,
            'phone' => $numero,
            'phone_key' => null,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_le_plus_ancien_compte_garde_le_numero(): void
    {
        $ancien = $this->ligne('+226 70 00 00 01', 'Premier arrive');
        $recent = $this->ligne('+22670000001', 'Second, meme ligne');

        $this->rejouerLaReprise();

        $this->assertSame('70000001', $this->cle($ancien));
        $this->assertNull($this->cle($recent),
            'Le second compte a pris l identifiant de connexion du premier.');
    }

    public function test_les_numeros_distincts_sont_tous_repris(): void
    {
        $a = $this->ligne('+226 70 00 00 01', 'Garage A');
        $b = $this->ligne('75 00 00 02', 'Garage B');

        $this->rejouerLaReprise();

        $this->assertSame('70000001', $this->cle($a));
        $this->assertSame('75000002', $this->cle($b));
    }

    /** Un numero sans aucun chiffre ne donne pas de cle, et n'en bloque aucune. */
    public function test_un_numero_illisible_ne_prend_la_place_de_personne(): void
    {
        $illisible = $this->ligne('a convenir', 'Numero manquant');
        $normal = $this->ligne('+22670000001', 'Garage normal');

        $this->rejouerLaReprise();

        $this->assertNull($this->cle($illisible));
        $this->assertSame('70000001', $this->cle($normal));
    }

    /**
     * LE POINT QUI FERME LE TROU.
     *
     * Le numero d'un groupe de doublons est PRIS par le compte le plus ancien.
     * S'il restait libre, un nouvel inscrit pourrait le reclamer — et se
     * retrouver a l'identifiant de connexion de deux vendeurs existants.
     */
    public function test_le_numero_repris_n_est_plus_disponible_a_l_inscription(): void
    {
        config()->set('partenaires.espace_ouvert', true);

        $this->ligne('+226 70 00 00 01', 'Premier arrive');
        $this->ligne('+22670000001', 'Second, meme ligne');

        $this->rejouerLaReprise();

        $this->postJson('/api/partner/register', [
            'contact_name' => 'Nouvel arrivant',
            'phone' => '70 00 00 01',
            'password' => 'motdepasse2026',
            'password_confirmation' => 'motdepasse2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    /**
     * LE DOUBLON SE DIT, SINON IL EST INVISIBLE.
     *
     * Un compte qui ne peut plus se connecter par numero sans que personne ne
     * l'apprenne, c'est une regression dont on ne saura qu'au premier appel du
     * vendeur. L'avertissement NOMME les comptes, pour que le personnel les
     * departage quand il le voudra.
     */
    public function test_les_comptes_laisses_sans_numero_sont_nommes(): void
    {
        Log::spy();

        $this->ligne('+226 70 00 00 01', 'Premier arrive');
        $this->ligne('+22670000001', 'Garage Sankara');

        $this->rejouerLaReprise();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'Garage Sankara')
                && str_contains($message, 'COURRIEL')
        );
    }

    public function test_sans_doublon_rien_n_est_signale(): void
    {
        Log::spy();

        $this->ligne('+226 70 00 00 01', 'Garage A');
        $this->ligne('+22675000002', 'Garage B');

        $this->rejouerLaReprise();

        Log::shouldNotHaveReceived('warning');
    }

    /** La reprise est REJOUABLE : un second passage ne deplace rien. */
    public function test_un_second_passage_ne_change_rien(): void
    {
        $ancien = $this->ligne('+226 70 00 00 01', 'Premier arrive');
        $recent = $this->ligne('+22670000001', 'Second, meme ligne');

        $this->rejouerLaReprise();
        $this->rejouerLaReprise();

        $this->assertSame('70000001', $this->cle($ancien));
        $this->assertNull($this->cle($recent));
    }

    private function cle(int $id): ?string
    {
        return PartnerAccount::findOrFail($id)->phone_key;
    }
}
