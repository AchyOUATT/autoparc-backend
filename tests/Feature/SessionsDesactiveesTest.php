<?php

namespace Tests\Feature;

use App\Exceptions\RefusMetier;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePartner;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\VerifyFirebaseToken;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Un compte desactive perd l'acces a la requete suivante, par les DEUX chemins.
 *
 * ── LE TROU QUE CE BANC FERME ──────────────────────────────────────────────
 *
 * `is_active` n'etait controle qu'au `login()`. `EnsureStaff` ne regardait que
 * le role, `me()` ne lisait pas `is_active`, et desactiver un employe ne
 * revoquait aucun jeton — les deux seules revocations du depot etaient
 * `Admin\PartnerApplicationController` et `RotateStaffPassword`. Avec
 * `config/sanctum.php` a `'expiration' => null`, un employe desactive gardait
 * donc l'acces jusqu'a sa propre deconnexion, c'est-a-dire potentiellement
 * pour toujours. Les partenaires et les clients etaient, eux, deja controles :
 * le chemin du personnel etait le seul oublie.
 *
 * ── POURQUOI DEUX STATUTS DIFFERENTS, ET POURQUOI C'EST VOULU ─────────────
 *
 * Les deux chemins de desactivation ne produisent pas le meme refus, et ce
 * banc les eprouve SEPAREMENT :
 *
 *   par Eloquent (`$user->update(...)`)  -> `UserObserver` revoque les jetons
 *                                           -> 401, generique
 *   en SQL direct (`DB::table(...)`)     -> aucun evenement, le jeton survit
 *                                           -> 403 `account_disabled`
 *
 * 401 dit « ce jeton n'existe plus », 403 `account_disabled` dit « ce jeton
 * existe, ce compte non ». Le client efface sa session dans les deux cas, et
 * c'est le CODE qui le lui permet — le statut seul ne distingue pas un compte
 * desactive d'un simple refus d'autorisation, que le client doit au contraire
 * traverser sans se deconnecter.
 *
 * C'est exactement la confusion que `partner_repository.dart` a payee :
 * `sessionRejected => statusCode == 401 || statusCode == 403` effacait le
 * jeton partenaire sur N'IMPORTE QUEL 403, « cette annonce n'est pas la
 * votre » comprise.
 */
class SessionsDesactiveesTest extends TestCase
{
    use RefreshDatabase;

    private function jeton(User $user, array $abilities = ['*']): string
    {
        return $user->createToken('essai', $abilities)->plainTextToken;
    }

    /**
     * Une requete portant ce jeton, garde d'authentification REMISE A ZERO.
     *
     * `Auth::forgetGuards()` n'est pas une precaution : sans lui ce banc
     * mesure du vide. Les requetes d'un meme test partagent l'instance de
     * l'application, et la garde memorise l'utilisateur qu'elle a resolu a la
     * premiere. La seconde requete, apres desactivation, repondait donc 200
     * avec l'utilisateur d'avant — quatre tests de ce fichier sont passes au
     * vert de cette facon avant qu'on ne le voie.
     *
     * `PartnerAuthenticationTest` porte le meme appel, pour la meme raison.
     */
    private function avec(string $jeton): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$jeton);
    }

    private function partenaire(): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => true]);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Garage Kossodo',
            'contact_name' => 'Awa Traore',
            'phone' => '+22670000000',
            'status' => 'approved',
        ]);
    }

    // ── Personnel, desactivation par Eloquent : revocation, donc 401 ────────

    public function test_le_personnel_desactive_par_eloquent_voit_ses_jetons_revoques(): void
    {
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $jeton = $this->jeton($employe);

        $this->avec($jeton)->getJson('/api/me')->assertOk();

        $employe->update(['is_active' => false]);

        // 401 et non 403 : il n'y a plus de jeton du tout. C'est voulu — l'ecran
        // de connexion donnera le motif.
        $this->avec($jeton)->getJson('/api/me')->assertUnauthorized();

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $employe->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'L observateur n a pas revoque les jetons.',
        );
    }

    public function test_reactiver_un_compte_ne_revoque_rien_et_sauvegarder_autre_chose_non_plus(): void
    {
        // `wasChanged('is_active')` et le passage vrai -> faux : changer un
        // telephone ne doit pas deconnecter quelqu un, et reactiver un compte
        // ne doit pas fermer les sessions qu on vient d ouvrir.
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->jeton($employe);

        $employe->update(['phone' => '+22670000001']);
        $this->assertSame(1, $employe->tokens()->count(), 'Un changement sans rapport a revoque.');

        $employe->update(['is_active' => false]);
        $this->assertSame(0, $employe->tokens()->count());

        $employe->update(['is_active' => true]);
        $nouveau = $this->jeton($employe);
        $employe->update(['is_active' => true]);

        $this->assertSame(1, $employe->tokens()->count(), 'Une reactivation a revoque.');
        $this->avec($nouveau)->getJson('/api/me')->assertOk();
    }

    /**
     * Reactiver un compte ne revoque pas un jeton qui avait SURVECU.
     *
     * Le test du dessus ne pouvait pas atteindre ce cas. Il frappe au bon
     * endroit — « une reactivation ne doit rien revoquer » — mais son jeton est
     * cree APRES la reactivation : au moment ou le compte repasse a actif, il
     * n'y a aucun jeton vivant, donc rien a revoquer, donc rien a mesurer.
     * Retirer le second terme de la condition de l'observateur passait le banc
     * au vert.
     *
     * Un jeton vivant sur un compte inactif n'existe que par le chemin SQL
     * direct : apres une desactivation par Eloquent l'observateur a deja tout
     * revoque. C'est donc par la qu'il faut passer pour eprouver la condition.
     */
    public function test_reactiver_un_compte_ne_revoque_pas_un_jeton_qui_avait_survecu(): void
    {
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $jeton = $this->jeton($employe);

        DB::table('users')->where('id', $employe->id)->update(['is_active' => false]);
        $this->avec($jeton)->getJson('/api/me')->assertForbidden();

        // `refresh()` n'est pas cosmetique : sans lui le modele en memoire croit
        // encore `is_active` vrai, l'ecriture ne change donc rien,
        // `wasChanged` est faux et l'observateur ne se declenche meme pas. Le
        // banc mesurerait du vide. On relit la ligne desactivee en base avant
        // de la reactiver, comme le ferait l'administration.
        $employe->refresh()->update(['is_active' => true]);

        $this->assertSame(1, $employe->tokens()->count(), 'La reactivation a revoque un jeton vivant.');
        $this->avec($jeton)->getJson('/api/me')->assertOk();
    }

    // ── Personnel, desactivation en SQL direct : 403 account_disabled ───────

    public function test_le_personnel_desactive_en_sql_direct_est_arrete_par_le_middleware(): void
    {
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $jeton = $this->jeton($employe);

        $this->avec($jeton)->getJson('/api/me')->assertOk();

        // Contourne l observateur : aucun evenement Eloquent n est emis.
        DB::table('users')->where('id', $employe->id)->update(['is_active' => false]);

        $this->assertSame(1, $employe->tokens()->count(), 'Le jeton devait survivre a ce chemin.');

        $this->avec($jeton)->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', RefusMetier::COMPTE_DESACTIVE);
    }

    public function test_une_route_du_personnel_refuse_aussi_un_compte_desactive_en_sql(): void
    {
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $jeton = $this->jeton($employe);

        $this->avec($jeton)->getJson('/api/vehicles')->assertOk();

        DB::table('users')->where('id', $employe->id)->update(['is_active' => false]);

        // C est `EnsureStaff` qui mord ici, pas `EnsureActiveUser` : le role est
        // bon, le compte non.
        $this->avec($jeton)->getJson('/api/vehicles')
            ->assertForbidden()
            ->assertJsonPath('code', RefusMetier::COMPTE_DESACTIVE);
    }

    /**
     * `logout` reste ouvert a un compte desactive, et c est deliberé.
     *
     * Le lui refuser laisserait une session morte dans le coffre du telephone,
     * que rien ne viendrait plus effacer. D ou le middleware pose sur `me`
     * seul.
     */
    public function test_un_compte_desactive_peut_encore_nettoyer_son_jeton(): void
    {
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $jeton = $this->jeton($employe);

        DB::table('users')->where('id', $employe->id)->update(['is_active' => false]);

        $this->avec($jeton)->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $employe->tokens()->count());
    }

    // ── Partenaires, desactivation en SQL direct ───────────────────────────

    public function test_un_partenaire_desactive_en_sql_direct_recoit_le_code_compte_desactive(): void
    {
        $compte = $this->partenaire();
        $jeton = $this->jeton($compte->user, ['partner']);

        $this->avec($jeton)->getJson('/api/partner/me')->assertOk();

        DB::table('users')->where('id', $compte->user_id)->update(['is_active' => false]);

        $this->avec($jeton)->getJson('/api/partner/me')
            ->assertForbidden()
            ->assertJsonPath('code', RefusMetier::COMPTE_DESACTIVE);
    }

    // ── Un refus d autorisation ne ferme JAMAIS la session ─────────────────

    public function test_un_role_insuffisant_rend_permission_refusee_et_laisse_le_jeton_intact(): void
    {
        // LE TEST QUI PROTEGE LE CLIENT DE LUI-MEME. C est ce refus que
        // `partner_repository.dart` traitait comme un rejet de session.
        $employe = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $jeton = $this->jeton($employe);

        $this->avec($jeton)->getJson('/api/partner-applications')
            ->assertForbidden()
            ->assertJsonPath('code', RefusMetier::PERMISSION_REFUSEE);

        $this->assertSame(1, $employe->tokens()->count(), 'Un refus d autorisation a revoque un jeton.');

        // Et la session reste utilisable sur ce a quoi elle a droit.
        $this->avec($jeton)->getJson('/api/me')->assertOk();
    }

    public function test_un_non_partenaire_rend_permission_refusee_et_non_compte_desactive(): void
    {
        $employe = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $this->avec($this->jeton($employe))->getJson('/api/partner/me')
            ->assertForbidden()
            ->assertJsonPath('code', RefusMetier::PERMISSION_REFUSEE);
    }

    // ── Le message de connexion ne revele pas l etat d un compte ───────────

    public function test_la_connexion_d_un_compte_desactive_garde_son_message_generique(): void
    {
        User::factory()->create([
            'email' => 'desactive@autoparc.bf',
            'role' => 'manager',
            'is_active' => false,
            'password' => bcrypt('un-mot-de-passe-correct'),
        ]);

        $reponse = $this->postJson('/api/login', [
            'email' => 'desactive@autoparc.bf',
            'password' => 'un-mot-de-passe-correct',
        ]);

        $reponse->assertStatus(422);

        // On ne dit pas a qui essaie un mot de passe si le compte existe et
        // s il est desactive.
        $this->assertStringNotContainsString(
            'desactive',
            mb_strtolower(json_encode($reponse->json(), JSON_UNESCAPED_UNICODE)),
            'Le message de connexion revele l etat du compte.',
        );
    }

    // ── Aucune route n'echappe au controle, et ca se verifie tout seul ─────

    /**
     * Le garde structurel : la question posee a la LISTE DES ROUTES, pas a la
     * main.
     *
     * Les bancs du dessus eprouvent les routes qu'on a pensé a eprouver. Celui-ci
     * eprouve celles qu'on n'a pas pensé a eprouver, et surtout celles qui
     * n'existent pas encore. Une route ajoutee demain derriere `auth:sanctum`,
     * sans garde d'activite, rendrait a un compte desactive un acces qui survit
     * a sa desactivation — exactement le trou qu'on vient de fermer, rouvert
     * ailleurs. Personne ne s'en apercevrait : aucun banc nominatif ne parle
     * d'une route qui n'est pas encore ecrite.
     *
     * Le decompte ci-dessous a d'abord ete fait a la main, et il etait FAUX :
     * `EnsureApprovedPartner` et `EnsureCapability` avaient ete comptes comme
     * gardes parce que leur nom en avait l'air. Ils controlent l'approbation et
     * une capacite, pas l'activite du compte. Les inclure masquait precisement
     * les trous qu'on cherchait. D'ou la liste etablie en lisant chaque
     * middleware, et d'ou ce banc plutot qu'une relecture periodique.
     */
    public function test_aucune_route_a_jeton_sanctum_n_echappe_au_controle_d_activite(): void
    {
        // Les SEULS middlewares du depot qui lisent `is_active`. Si on en
        // ajoute un, il vient ici — et si l'un de ceux-la cesse de lire la
        // colonne, c'est un autre banc qui doit tomber, pas celui-ci.
        $gardes = [
            EnsureActiveUser::class,
            EnsurePartner::class,
            EnsureStaff::class,
            VerifyFirebaseToken::class,
        ];

        $alias = app('router')->getMiddleware();
        $groupes = app('router')->getMiddlewareGroups();

        // `gatherMiddleware()` rend des noms courts et des noms de groupes
        // (`api`, `auth:sanctum`, `staff`...). On les resout en classes, en
        // gardant le parametre : `auth:sanctum` et `auth:web` ne sont pas la
        // meme chose.
        $resoudre = function (array $noms) use ($alias, $groupes, &$resoudre): array {
            $pile = [];
            foreach ($noms as $nom) {
                $base = explode(':', $nom, 2)[0];
                $suffixe = substr($nom, strlen($base));

                if (isset($groupes[$base])) {
                    $pile = array_merge($pile, $resoudre($groupes[$base]));

                    continue;
                }

                $pile[] = ($alias[$base] ?? $base).$suffixe;
            }

            return $pile;
        };

        $trous = [];

        foreach (Route::getRoutes() as $route) {
            $pile = $resoudre($route->gatherMiddleware());

            if (! in_array(Authenticate::class.':sanctum', $pile, true)) {
                continue;
            }

            foreach ($gardes as $garde) {
                foreach ($pile as $entree) {
                    if (str_starts_with($entree, $garde)) {
                        continue 3;
                    }
                }
            }

            $trous[] = implode('|', $route->methods()).' '.$route->uri();
        }

        sort($trous);

        $this->assertSame(
            ['POST api/logout'],
            $trous,
            "La liste des routes a jeton Sanctum sans controle d'activite a change.\n"
            ."AJOUTEE : un compte desactive garde un acces qui survit a sa desactivation.\n"
            ."RETIREE : si c'est `logout`, un compte desactive ne peut plus nettoyer son\n"
            ."propre jeton, et une session morte reste dans le coffre du telephone sans que\n"
            ."rien ne vienne plus l'effacer. Les deux sens sont des regressions.",
        );
    }

    // ── La forme de la reponse, puisque le client s y fie ──────────────────

    public function test_un_refus_metier_porte_message_code_et_request_id(): void
    {
        // `request_id` est le seul fil entre ce que voit l utilisateur et le
        // journal. Il est ajoute par `respond()` de bootstrap/app.php, APRES le
        // `render()` de l exception : ce test verifie que les deux cohabitent.
        $employe = User::factory()->create(['role' => 'viewer', 'is_active' => true]);

        $this->avec($this->jeton($employe))->getJson('/api/partner-applications')
            ->assertForbidden()
            ->assertJsonStructure(['message', 'code', 'request_id']);
    }
}
