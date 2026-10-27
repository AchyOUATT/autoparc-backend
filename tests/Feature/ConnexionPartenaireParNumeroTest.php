<?php

namespace Tests\Feature;

use App\Models\PartnerAccount;
use App\Models\User;
use App\Support\NumeroDeTelephone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le numero de telephone identifie un partenaire.
 *
 * ── POURQUOI ──────────────────────────────────────────────────────────────
 *
 * Le courriel est devenu facultatif a l'inscription. Mesure faite, il ne
 * servait qu'a UNE chose : se connecter — aucun courriel n'est envoye nulle
 * part, toutes les notifications passent par `AppNotification`. Exiger une
 * adresse d'un revendeur qui n'en a pas revenait a lui demander d'en creer
 * une pour pouvoir revenir sur son compte.
 *
 * Reste a savoir DE QUEL compte il s'agit : c'est le numero, la seule chose
 * que tout partenaire a et donne deja.
 *
 * ── CE QUE CE BANC TIENT ──────────────────────────────────────────────────
 *
 * Que l'inscription passe sans adresse ; que la connexion marche par numero
 * SOUS N'IMPORTE QUELLE FORME, sans quoi l'unicite serait un decor ; que
 * l'application PUBLIEE, qui envoie `email`, continue de fonctionner ; que
 * deux comptes ne puissent plus partager une ligne, aussi bien par la
 * validation que par l'index — ce dernier etant le seul garde qu'une course
 * entre deux inscriptions simultanees ne contourne pas ; et que l'echec ne
 * dise jamais si l'identifiant existe.
 */
class ConnexionPartenaireParNumeroTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'motdepasse2026';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('partenaires.espace_ouvert', true);
    }

    /** @param  array<string, mixed>  $champs */
    private function inscrire(array $champs = []): TestResponse
    {
        return $this->postJson('/api/partner/register', array_merge([
            'contact_name' => 'Salif Kabore',
            'phone' => '+226 70 00 00 01',
            'password' => self::MOT_DE_PASSE,
            'password_confirmation' => self::MOT_DE_PASSE,
        ], $champs));
    }

    /** @param  array<string, mixed>  $champs */
    private function seConnecter(array $champs): TestResponse
    {
        return $this->postJson('/api/partner/login', array_merge(
            ['password' => self::MOT_DE_PASSE],
            $champs,
        ));
    }

    // ── L'INSCRIPTION SANS ADRESSE ────────────────────────────────────────

    public function test_l_inscription_passe_sans_courriel(): void
    {
        $this->inscrire()->assertCreated();

        $compte = PartnerAccount::firstOrFail();
        $this->assertNull($compte->user->email);
        $this->assertSame('+226 70 00 00 01', $compte->phone, 'Le numero affiche reste celui que le vendeur a tape.');
        $this->assertSame('70000001', $compte->phone_key);
    }

    public function test_un_courriel_donne_est_conserve(): void
    {
        $this->inscrire(['email' => 'Salif@Example.Test'])->assertCreated();

        $this->assertSame('salif@example.test', PartnerAccount::firstOrFail()->user->email);
    }

    // ── LA CONNEXION PAR NUMERO ───────────────────────────────────────────

    /**
     * LE BANC QUI COMPTE : toutes ces ecritures designent la meme ligne.
     *
     * Comparer les chaines brutes laisserait un partenaire dehors des qu'il
     * retape son numero autrement qu'a l'inscription — ce qui est le cas
     * courant, et non le cas limite.
     */
    public function test_le_numero_ouvre_la_session_sous_toutes_ses_formes(): void
    {
        $this->inscrire()->assertCreated();

        foreach ([
            '+226 70 00 00 01',
            '+22670000001',
            '00226 70 00 00 01',
            '70000001',
            '70 00 00 01',
            '(226) 70-00-00-01',
        ] as $forme) {
            $this->seConnecter(['identifiant' => $forme])
                ->assertOk()
                ->assertJsonPath('user.role', 'partner');
        }
    }

    /**
     * L'APPLICATION DEJA INSTALLEE envoie `email`, et son propre validateur
     * exige une forme d'adresse avant meme d'appeler. Retirer ce champ
     * deconnecterait tous les partenaires jusqu'a leur mise a jour.
     */
    public function test_l_ancien_champ_email_fonctionne_toujours(): void
    {
        $this->inscrire(['email' => 'salif@example.test'])->assertCreated();

        $this->seConnecter(['email' => 'salif@example.test'])
            ->assertOk()
            ->assertJsonPath('user.role', 'partner');
    }

    public function test_le_nouvel_identifiant_accepte_aussi_une_adresse(): void
    {
        $this->inscrire(['email' => 'salif@example.test'])->assertCreated();

        $this->seConnecter(['identifiant' => 'SALIF@EXAMPLE.TEST'])->assertOk();
    }

    // ── L'UNICITE ─────────────────────────────────────────────────────────

    public function test_un_second_compte_sur_le_meme_numero_est_refuse(): void
    {
        $this->inscrire()->assertCreated();

        $this->inscrire(['contact_name' => 'Autre vendeur', 'phone' => '+22670000001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(1, PartnerAccount::count());
    }

    /**
     * L'INDEX, ET NON LA VALIDATION.
     *
     * Deux inscriptions simultanees peuvent toutes deux passer le
     * `exists()` avant que l'une n'ait ecrit. Seule une contrainte de base
     * arrete la seconde — et sans elle, deux comptes porteraient le meme
     * identifiant de connexion, ce qui rend le second inaccessible a jamais.
     */
    public function test_la_base_refuse_le_doublon_meme_sans_validation(): void
    {
        $this->compte('+226 70 00 00 01');

        $this->expectException(QueryException::class);

        $this->compte('00226-70-00-00-01');
    }

    /**
     * L'UNICITE DU COURRIEL A SURVECU A SA MISE EN NULLABLE.
     *
     * `$table->string('email')->nullable()->change()` reecrit la colonne. Sous
     * SQLite, Laravel reconstruit la table entiere pour y parvenir : un index
     * perdu au passage ne se verrait nulle part, la validation d'inscription
     * ayant son propre controle applicatif. Deux comptes partageraient alors
     * une adresse, et la connexion par courriel deviendrait ambigue — elle
     * ouvrirait le premier trouve.
     */
    public function test_l_adresse_reste_unique_en_base(): void
    {
        User::factory()->create(['email' => 'salif@example.test']);

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Homonyme', 'email' => 'salif@example.test',
            'password' => 'peu importe', 'role' => 'partner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Plusieurs comptes SANS adresse coexistent : l'index unique tolere les nuls. */
    public function test_plusieurs_comptes_sans_adresse_coexistent(): void
    {
        $this->inscrire()->assertCreated();
        $this->inscrire(['contact_name' => 'Autre', 'phone' => '+22675000002'])
            ->assertCreated();

        $this->assertSame(2, User::whereNull('email')->where('role', 'partner')->count());
    }

    public function test_un_numero_different_passe(): void
    {
        $this->inscrire()->assertCreated();

        $this->inscrire(['contact_name' => 'Autre vendeur', 'phone' => '+22675000002'])
            ->assertCreated();

        $this->assertSame(2, PartnerAccount::count());
    }

    /** La cle suit le numero : sinon le compte deviendrait injoignable. */
    public function test_changer_de_numero_change_la_cle(): void
    {
        $compte = $this->compte('+226 70 00 00 01');

        $compte->update(['phone' => '+226 75 00 00 02']);

        $this->assertSame('75000002', $compte->fresh()->phone_key);
        $this->seConnecter(['identifiant' => '75000002'])->assertOk();
        $this->seConnecter(['identifiant' => '70000001'])->assertUnprocessable();
    }

    // ── CE QUE L'ECHEC NE DIT PAS ─────────────────────────────────────────

    /**
     * Le message est le MEME, que l'identifiant existe ou non.
     *
     * Le distinguer dirait a un inconnu quels numeros sont enregistres chez
     * nous — et un numero est une donnee personnelle, pas une statistique.
     */
    public function test_l_echec_ne_revele_pas_si_le_compte_existe(): void
    {
        $this->inscrire()->assertCreated();

        $mauvaisMotDePasse = $this->seConnecter([
            'identifiant' => '70000001', 'password' => 'autre-mot-de-passe',
        ])->assertUnprocessable();

        $inconnu = $this->seConnecter(['identifiant' => '79999999'])
            ->assertUnprocessable();

        $this->assertSame(
            $mauvaisMotDePasse->json('errors.identifiant'),
            $inconnu->json('errors.identifiant'),
        );
    }

    public function test_un_identifiant_sans_chiffre_ni_arobase_est_refuse(): void
    {
        $this->inscrire()->assertCreated();

        $this->seConnecter(['identifiant' => 'salif'])->assertUnprocessable();
    }

    /**
     * LE CAS QUI REND LE GARDE NECESSAIRE, ET NON DECORATIF.
     *
     * `where('phone_key', null)` ne compare pas a null : Laravel le traduit en
     * `whereNull`. Chercher avec une cle nulle trouverait donc tous les comptes
     * dont la colonne est nulle — c'est-a-dire ceux dont le numero enregistre
     * ne contient aucun chiffre.
     *
     * La validation d'inscription l'empeche aujourd'hui, mais une reprise de
     * donnees ou une correction du personnel peut poser un tel numero. Le
     * compte deviendrait alors ouvrable par n'importe quel identifiant sans
     * chiffre, « salif » compris, pour qui connait le mot de passe — et pire,
     * par un identifiant que son titulaire n'a jamais choisi.
     */
    public function test_un_compte_au_numero_illisible_ne_s_ouvre_pas_par_n_importe_quoi(): void
    {
        $compte = $this->compte('a convenir');

        $this->assertNull($compte->phone_key, 'Le decor ne reproduit pas le cas vise.');

        $this->seConnecter(['identifiant' => 'salif'])->assertUnprocessable();
        $this->seConnecter(['identifiant' => 'a convenir'])->assertUnprocessable();
    }

    /**
     * Un compte sans courriel ne doit pas se laisser ouvrir par une chaine
     * vide tombant sur la colonne nulle.
     */
    public function test_un_compte_sans_courriel_ne_s_ouvre_pas_par_une_adresse_vide(): void
    {
        $this->inscrire()->assertCreated();

        foreach (['@', ' @ ', '@example.test'] as $tentative) {
            $this->seConnecter(['identifiant' => $tentative])->assertUnprocessable();
        }
    }

    // ── La forme normalisee, unite par unite ──────────────────────────────

    public function test_la_cle_normalise_ce_qui_doit_l_etre(): void
    {
        $this->assertSame('70000001', NumeroDeTelephone::cle('+226 70 00 00 01'));
        $this->assertSame('70000001', NumeroDeTelephone::cle('00226-70.00.00.01'));
        $this->assertSame('70000001', NumeroDeTelephone::cle('(70) 00 00 01'));
        $this->assertNull(NumeroDeTelephone::cle(''));
        $this->assertNull(NumeroDeTelephone::cle('aucun chiffre'));
        $this->assertNull(NumeroDeTelephone::cle(null));
    }

    /**
     * Ce que la normalisation NE fait PAS, et c'est deliberé.
     *
     * Retirer « 226 » de n'importe quelle longueur inventerait des collisions
     * entre numeros etrangers. La regle ne s'applique qu'a la longueur exacte
     * d'un numero burkinabe prefixe.
     */
    public function test_la_cle_ne_tronque_pas_un_numero_etranger(): void
    {
        $this->assertSame('2267000', NumeroDeTelephone::cle('2267000'));
        $this->assertSame('226700000012', NumeroDeTelephone::cle('+226 70 00 00 01 2'));
    }

    private function compte(string $numero): PartnerAccount
    {
        $user = User::factory()->create([
            'role' => 'partner',
            'is_active' => true,
            'password' => self::MOT_DE_PASSE,
        ]);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'contact_name' => 'Salif Kabore',
            'phone' => $numero,
            'status' => 'approved',
        ]);
    }
}
