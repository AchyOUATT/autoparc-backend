<?php

namespace Tests\Feature;

use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'interrupteur de l'espace partenaire, dans sa position FERMEE.
 *
 * Le reste de la suite mesure la fonctionnalite, drapeau ouvert — c'est
 * `phpunit.xml` qui l'ouvre, et son commentaire dit pourquoi. Ce banc-ci est
 * le seul a le refermer, et il est donc le seul a verifier ce qui se passe
 * dans l'etat qui sera celui de la PRODUCTION au premier deploiement.
 *
 * ── CE QU'IL FAUT PROTEGER, ET POURQUOI C'EST SUBTIL ───────────────────────
 *
 * Fermer l'espace partenaire ne consiste pas a fermer « la fonctionnalite ».
 * Il faut fermer l'INSCRIPTION — c'est elle qui cree des comptes que personne
 * ne peut encore approuver, puisque l'ecran de validation n'existe dans aucun
 * binaire livre — tout en LAISSANT OUVERTE l'administration du personnel, qui
 * est precisement l'outil par lequel le premier partenaire sera approuve.
 *
 * Les deux groupes vivent dans le meme fichier de routes. Poser le middleware
 * un cran trop haut fermerait les deux, et le jour de l'ouverture on
 * decouvrirait qu'on ne peut approuver personne. C'est ce que le dernier test
 * de ce banc interdit.
 */
class EspacePartenaireFermeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // On referme ce que phpunit.xml ouvre pour tous les autres bancs.
        config(['partenaires.espace_ouvert' => false]);
    }

    /**
     * Les deux portes publiques, celles qu'un anonyme de l'application deja
     * publiee peut toucher depuis la tuile du tiroir.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function portesPubliques(): array
    {
        return [
            'inscription' => ['post', '/api/partner/register', [
                'company_name'          => 'Garage Kossodo',
                'contact_name'          => 'Awa Traore',
                'phone'                 => '+22670000000',
                'email'                 => 'awa@garage-kossodo.bf',
                'password'              => 'un-mot-de-passe-correct',
                'password_confirmation' => 'un-mot-de-passe-correct',
            ]],
            'connexion' => ['post', '/api/partner/login', [
                'email'    => 'awa@garage-kossodo.bf',
                'password' => 'un-mot-de-passe-correct',
            ]],
        ];
    }

    #[DataProvider('portesPubliques')]
    public function test_les_portes_publiques_refusent_avec_un_motif(string $verbe, string $route, array $corps): void
    {
        $reponse = $this->{$verbe.'Json'}($route, $corps);

        $reponse->assertStatus(503);

        // Le motif compte autant que le code. Un 404 muet se confondrait avec
        // un serveur ou la fonctionnalite n'a jamais ete deployee — l'etat
        // d'hier, et ce que l'application publiee rend deja.
        $this->assertStringContainsString(
            'pas encore ouvert',
            (string) $reponse->json('message'),
            "Le refus doit dire POURQUOI, sinon il est indiscernable d'un serveur non deploye.",
        );
    }

    public function test_aucun_compte_n_est_cree_par_une_inscription_refusee(): void
    {
        // Le coeur du probleme : ce n'est pas le refus qui importe, c'est qu'il
        // tombe AVANT l'ecriture. Un 503 rendu apres la creation du compte
        // laisserait exactement les comptes « pending » qu'on cherche a eviter.
        $this->postJson('/api/partner/register', [
            'company_name'          => 'Garage Kossodo',
            'contact_name'          => 'Awa Traore',
            'phone'                 => '+22670000000',
            'email'                 => 'awa@garage-kossodo.bf',
            'password'              => 'un-mot-de-passe-correct',
            'password_confirmation' => 'un-mot-de-passe-correct',
        ])->assertStatus(503);

        $this->assertSame(0, PartnerAccount::count(), 'Un compte partenaire a ete cree malgre le refus.');
        $this->assertFalse(
            User::where('email', 'awa@garage-kossodo.bf')->exists(),
            'Un utilisateur a ete cree malgre le refus.'
        );
    }

    public function test_une_route_d_annonce_refuse_aussi_avec_un_jeton_valide(): void
    {
        // Le drapeau ne protege pas seulement l'inscription : un partenaire
        // deja approuve, jeton en main, est ferme lui aussi. Sans quoi fermer
        // l'espace laisserait les annonces modifiables.
        $partenaire = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        PartnerAccount::create([
            'user_id'      => $partenaire->id,
            'company_name' => 'Garage Kossodo',
            'contact_name' => 'Awa Traore',
            'phone'        => '+22670000000',
            'status'       => 'approved',
        ]);

        Sanctum::actingAs($partenaire);

        $this->getJson('/api/partner/vehicles')->assertStatus(503);
    }

    public function test_l_administration_du_personnel_reste_OUVERTE(): void
    {
        // LE TEST QUI COMPTE LE PLUS DE CE BANC. C'est par cette route que le
        // premier partenaire sera approuve : si l'interrupteur la fermait
        // aussi, on ouvrirait l'espace un jour pour decouvrir qu'on ne peut
        // valider personne, et il faudrait un second deploiement pour s'en
        // apercevoir.
        $personnel = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($personnel);

        $this->getJson('/api/partner-applications')->assertOk();
    }

    public function test_la_route_de_version_annonce_l_espace_ferme(): void
    {
        // C'est ce champ que l'application lira pour masquer la tuile. Il doit
        // suivre le drapeau, sans quoi l'interrupteur serveur et l'affichage
        // se contrediraient.
        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertJson(['partner_space_open' => false]);
    }

    /**
     * Les valeurs d'environnement qui NE DOIVENT PAS ouvrir.
     *
     * Mesure du 25/10/2026 : `env()` traduit `false`, `FALSE`, `(false)`, `0`
     * et le vide en booleen faux, mais rend les autres chaines telles quelles.
     * Or `(bool) 'no'` vaut VRAI en PHP, comme `(bool) 'off'`. Ecrire
     * `PARTNER_SPACE_OPEN=no` dans Render — un geste naturel — aurait ouvert
     * l'espace. Ce test est ce qui empeche d'y revenir par un `(bool)`.
     *
     * @return array<string, array{0: string}>
     */
    public static function valeursQuiNOuvrentPas(): array
    {
        return array_map(
            fn (string $v) => [$v],
            ['false' => 'false', 'FALSE' => 'FALSE', '(false)' => '(false)', 'zero' => '0',
                'vide' => '', 'no' => 'no', 'off' => 'off', 'nimportequoi' => 'peut-etre'],
        );
    }

    #[DataProvider('valeursQuiNOuvrentPas')]
    public function test_seuls_quatre_mots_ouvrent_l_espace(string $valeur): void
    {
        $this->assertFalse(
            filter_var($valeur, FILTER_VALIDATE_BOOLEAN),
            "« {$valeur} » ne doit pas ouvrir l'espace partenaire.",
        );
    }

    /** Et les quatre qui ouvrent, sans quoi le test ci-dessus passerait a vide. */
    public function test_les_mots_qui_ouvrent_ouvrent_bien(): void
    {
        foreach (['1', 'true', 'TRUE', 'on', 'yes'] as $valeur) {
            $this->assertTrue(
                filter_var($valeur, FILTER_VALIDATE_BOOLEAN),
                "« {$valeur} » doit ouvrir l'espace partenaire.",
            );
        }
    }

    public function test_le_drapeau_ouvert_rend_la_main(): void
    {
        config(['partenaires.espace_ouvert' => true]);

        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertJson(['partner_space_open' => true]);

        // Et la porte s'ouvre : 422 et non 503, c'est-a-dire que la requete est
        // enfin EXAMINEE. Sans cette contre-epreuve, un 503 pose en dur
        // passerait tous les tests ci-dessus.
        $this->postJson('/api/partner/login', [])->assertStatus(422);
    }
}
