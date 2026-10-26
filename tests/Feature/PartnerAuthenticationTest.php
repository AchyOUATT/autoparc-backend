<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeFirebaseAuthService;
use Tests\TestCase;

class PartnerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $override = []): array
    {
        return array_merge([
            'company_name' => 'Garage Partenaire',
            'contact_name' => 'Contact Entreprise',
            'phone' => '+22670000000',
            'whatsapp' => '+22670000001',
            'email' => 'vendeur@example.test',
            'password' => 'mot-de-passe-partenaire123',
            'password_confirmation' => 'mot-de-passe-partenaire123',
        ], $override);
    }

    private function partner(string $status = 'pending', array $userAttributes = []): PartnerAccount
    {
        $user = User::factory()->create(array_merge([
            'role' => UserRole::Partner,
            'password' => Hash::make('mot-de-passe-partenaire123'),
        ], $userAttributes));

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Entreprise '.$user->id,
            'contact_name' => $user->name,
            'phone' => '+22670000000',
            'status' => $status,
        ])->load('user');
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_l_inscription_cree_un_compte_en_attente_et_un_jeton_sans_droit_personnel(): void
    {
        $response = $this->postJson('/api/partner/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.role', 'partner')
            ->assertJsonPath('partner_account.status', 'pending');

        $user = User::where('email', 'vendeur@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($this->registration()['password'], $user->password));
        $this->assertFalse($user->isStaff());
        foreach (['manage-catalog', 'manage-stock', 'manage-partners', 'manage-orders'] as $capability) {
            $this->assertFalse($user->role->can($capability));
        }
        $this->assertSame(['partner'], $user->tokens()->firstOrFail()->abilities);

        $this->bearer($response->json('token'))->getJson('/api/partner/me')
            ->assertOk()->assertJsonPath('partner_account.company_name', 'Garage Partenaire');
        $this->bearer($response->json('token'))->getJson('/api/partner/vehicles')->assertForbidden();
        $this->bearer($response->json('token'))->getJson('/api/vehicles')->assertForbidden();
    }

    public function test_l_inscription_ne_permet_pas_de_choisir_son_role_ou_son_approbation(): void
    {
        $this->postJson('/api/partner/register', $this->registration([
            'role' => 'admin',
            'status' => 'approved',
            'is_active' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['role', 'status', 'is_active']);

        $this->assertDatabaseCount('partner_accounts', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_l_inscription_exige_la_confirmation_et_normalise_l_email(): void
    {
        $this->postJson('/api/partner/register', $this->registration(['password_confirmation' => 'autre']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/partner/register', $this->registration(['email' => ' VENDEUR@EXAMPLE.TEST ']))
            ->assertCreated()->assertJsonPath('user.email', 'vendeur@example.test');
        $this->postJson('/api/partner/register', $this->registration(['email' => 'VENDEUR@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('partner_accounts', 1);
    }

    public function test_un_email_deja_utilise_en_majuscules_ne_cree_pas_un_second_compte(): void
    {
        User::factory()->create(['email' => 'VENDEUR@EXAMPLE.TEST']);

        $this->postJson('/api/partner/register', $this->registration())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('partner_accounts', 0);
    }

    public function test_les_valeurs_mal_formees_restent_des_erreurs_de_validation(): void
    {
        $this->postJson('/api/partner/register', $this->registration(['email' => ['vendeur@example.test']]))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $password = str_repeat('é', 40).'123';
        $this->postJson('/api/partner/register', $this->registration([
            'password' => $password,
            'password_confirmation' => $password,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $password = "mot-de-passe123\0invalide";
        $this->postJson('/api/partner/register', $this->registration([
            'password' => $password,
            'password_confirmation' => $password,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_un_compte_en_attente_peut_se_connecter_pour_lire_son_statut(): void
    {
        $account = $this->partner();

        $response = $this->postJson('/api/partner/login', [
            'email' => $account->user->email,
            'password' => 'mot-de-passe-partenaire123',
        ])->assertOk()->assertJsonPath('partner_account.status', 'pending');

        $this->bearer($response->json('token'))->getJson('/api/partner/me')
            ->assertOk()->assertJsonPath('partner_account.id', $account->id);
    }

    public function test_rejet_et_suspension_refusent_un_nouveau_jeton_et_ne_divulguent_pas_le_motif_sans_mot_de_passe(): void
    {
        foreach (['rejected', 'suspended'] as $status) {
            $account = $this->partner($status);
            $account->update(['review_reason' => 'Verification necessaire']);

            $this->postJson('/api/partner/login', [
                'email' => $account->user->email,
                'password' => 'incorrect',
            ])->assertUnprocessable()->assertJsonMissingPath('review_reason');

            $this->postJson('/api/partner/login', [
                'email' => $account->user->email,
                'password' => 'mot-de-passe-partenaire123',
            ])->assertForbidden()->assertJsonPath('status', $status)
                ->assertJsonPath('review_reason', 'Verification necessaire')->assertJsonMissingPath('token');

            $this->assertSame(0, $account->user->tokens()->count());
        }
    }

    public function test_un_partenaire_ne_peut_pas_se_connecter_sur_le_canal_du_personnel(): void
    {
        $account = $this->partner('approved');

        $this->postJson('/api/login', [
            'email' => $account->user->email,
            'password' => 'mot-de-passe-partenaire123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, $account->user->tokens()->count());
    }

    public function test_le_personnel_et_les_clients_n_entrent_pas_sur_le_canal_partenaire(): void
    {
        foreach (['admin', 'client'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->postJson('/api/partner/login', ['email' => $user->email, 'password' => 'password'])
                ->assertUnprocessable();
            $this->bearer($user->createToken('test')->plainTextToken)
                ->getJson('/api/partner/me')->assertForbidden();
        }
    }

    /**
     * CE TEST ATTENDAIT 403 ET ATTEND DESORMAIS 401 : c'est un changement
     * voulu, pas une regression.
     *
     * `update()` passe par Eloquent, donc par `UserObserver`, qui revoque les
     * jetons des que `is_active` passe de vrai a faux. Il n'y a plus de jeton a
     * refuser : la requete suivante n'est plus autorisee du tout.
     *
     * Le 403 n'a pas disparu pour autant — il reste la reponse de l'autre
     * chemin de desactivation, celui qui ne passe pas par Eloquent et laisse le
     * jeton survivre. `SessionsDesactiveesTest` eprouve les deux cote a cote,
     * et le 403 y porte le code `account_disabled` qui dit au client d'effacer
     * sa session.
     */
    public function test_un_compte_desactive_perd_l_acces_meme_avec_un_ancien_jeton(): void
    {
        $account = $this->partner('approved');
        $token = $account->user->createToken('test', ['partner'])->plainTextToken;
        $this->bearer($token)->getJson('/api/partner/me')->assertOk();
        $account->user->update(['is_active' => false]);

        Auth::forgetGuards();
        $this->bearer($token)->getJson('/api/partner/me')->assertUnauthorized();

        $this->assertSame(0, $account->user->tokens()->count(), 'Les jetons devaient etre revoques.');
    }

    public function test_firebase_ne_rattache_jamais_un_email_de_partenaire_ou_de_personnel(): void
    {
        foreach (['partner', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'firebase_uid' => null]);
            $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService([
                'uid' => 'tentative-'.$role,
                'email' => mb_strtoupper($user->email),
                'name' => $user->name,
            ]));

            Auth::forgetGuards();
            $this->withToken('firebase-test')->getJson('/api/my/vehicles')->assertForbidden();
            $this->assertNull($user->fresh()->firebase_uid);
            $this->assertSame($role, $user->fresh()->role->value);
        }
    }

    public function test_firebase_refuse_aussi_un_partenaire_deja_lie_par_uid(): void
    {
        $account = $this->partner('approved', ['firebase_uid' => 'uid-partenaire']);
        $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService([
            'uid' => 'uid-partenaire',
            'email' => $account->user->email,
            'name' => $account->user->name,
        ]));

        $this->withToken('firebase-test')->deleteJson('/api/my/account')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $account->user_id, 'role' => 'partner']);
    }

    public function test_la_rotation_des_mots_de_passe_du_personnel_ignore_les_partenaires(): void
    {
        $account = $this->partner('approved');
        $partnerPassword = $account->user->password;
        $account->user->createToken('partenaire');
        User::factory()->create(['role' => 'admin']);

        $this->artisan('staff:rotate-password', ['--all' => true])->assertSuccessful();
        $this->assertSame($partnerPassword, $account->user->fresh()->password);
        $this->assertSame(1, $account->user->tokens()->count());

        $this->artisan('staff:rotate-password', ['email' => $account->user->email])->assertFailed();
        $this->assertSame($partnerPassword, $account->user->fresh()->password);
    }
}
