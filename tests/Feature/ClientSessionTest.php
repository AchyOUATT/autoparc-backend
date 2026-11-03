<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OwnedVehicle;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFirebaseAuthService;
use Tests\TestCase;

class ClientSessionTest extends TestCase
{
    use RefreshDatabase;

    private function firebase(array $claims): static
    {
        Auth::forgetGuards();
        $this->app->instance(FirebaseAuthService::class, new FakeFirebaseAuthService($claims));

        return $this->withToken('firebase-test-token');
    }

    public function test_a_missing_token_is_rejected_before_resolving_firebase_credentials(): void
    {
        $this->app->bind(FirebaseAuthService::class, function () {
            throw new \RuntimeException('Firebase must not be resolved without a bearer token.');
        });

        $this->getJson('/api/my/account')->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_client_profile_returns_only_the_customer_identity_without_caching(): void
    {
        $client = User::factory()->create([
            'role' => UserRole::Client, 'firebase_uid' => 'private-firebase-identity',
            'remember_token' => 'private-remember-token',
        ]);

        $this->firebase([
            'uid' => $client->firebase_uid, 'email' => $client->email, 'name' => $client->name,
        ])->getJson('/api/my/account')->assertOk()
            ->assertHeader('Content-Language', 'fr')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'id' => $client->id, 'name' => $client->name,
                'email' => $client->email, 'role' => 'client',
            ]]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_new_firebase_identity_is_provisioned_as_client_only(): void
    {
        $response = $this->firebase([
            'uid' => 'new-client-uid', 'email' => 'new-client@example.test',
            'name' => 'New Customer', 'role' => 'admin',
        ])->getJson('/api/my/account')->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.email', 'new-client@example.test')
            ->assertHeader('Cache-Control', 'no-store, private');

        $client = User::findOrFail($response->json('data.id'));
        $this->assertSame(UserRole::Client, $client->role);
        $this->assertSame('new-client-uid', $client->firebase_uid);
        $this->assertTrue($client->is_active);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('partner_accounts', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_existing_client_is_linked_by_email_without_a_duplicate_account(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client, 'firebase_uid' => null]);

        $this->firebase([
            'uid' => 'first-client-uid', 'email' => mb_strtoupper($client->email), 'name' => $client->name,
        ])->getJson('/api/my/account')->assertOk()
            ->assertJsonPath('data.id', $client->id)->assertJsonPath('data.role', 'client');

        $this->assertSame('first-client-uid', $client->fresh()->firebase_uid);
        $this->assertSame(UserRole::Client, $client->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public static function nonClientIdentities(): array
    {
        $cases = [];
        foreach (array_merge([UserRole::Partner], UserRole::staffRoles()) as $role) {
            $cases[$role->value.' via uid'] = [$role->value, true, 'en'];
            $cases[$role->value.' via email'] = [$role->value, false, 'fr'];
        }

        return $cases;
    }

    #[DataProvider('nonClientIdentities')]
    public function test_non_clients_are_refused_before_any_uid_link_or_role_change(string $role, bool $linked, string $language): void
    {
        $user = User::factory()->create([
            'role' => $role, 'firebase_uid' => $linked ? 'already-linked-uid' : null,
        ]);
        if ($role === 'partner') {
            PartnerAccount::create([
                'user_id' => $user->id, 'company_name' => 'Partner Company',
                'contact_name' => $user->name, 'phone' => $this->numeroDePartenaire($user->id),
                'status' => 'approved',
            ]);
        }
        $originalUid = $user->firebase_uid;
        $code = $role === 'partner'
            ? 'client_account_requires_partner_login'
            : 'client_account_requires_staff_login';
        $message = match ([$role === 'partner', $language]) {
            [true, 'en'] => 'This is a partner account. Sign in to the partner area.',
            [false, 'en'] => 'This is a staff account. Use staff sign-in.',
            [true, 'fr'] => 'Ce compte est un compte partenaire. Connectez-vous à l’espace partenaire.',
            default => 'Ce compte appartient au personnel. Utilisez la connexion du personnel.',
        };

        $this->firebase([
            'uid' => $originalUid ?? 'attempted-new-uid',
            'email' => mb_strtoupper($user->email), 'name' => $user->name,
        ])->withHeader('Accept-Language', $language)
            ->getJson('/api/my/account')->assertForbidden()
            ->assertHeader('Content-Language', $language)
            ->assertJsonPath('code', $code)->assertJsonPath('message', $message)
            ->assertJsonMissingPath('data')->assertJsonMissingPath('token');
        $this->getJson('/api/my/vehicles')->assertForbidden()->assertJsonPath('code', $code);

        $this->assertSame($originalUid, $user->fresh()->firebase_uid);
        $this->assertSame($role, $user->fresh()->role->value);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('owned_vehicles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        if ($role === 'partner') {
            $this->assertDatabaseHas('partner_accounts', ['user_id' => $user->id, 'status' => 'approved']);
        }
    }

    public function test_a_disabled_client_cannot_confirm_a_session_or_open_the_garage(): void
    {
        $client = User::factory()->create([
            'role' => UserRole::Client, 'firebase_uid' => 'disabled-client-uid', 'is_active' => false,
        ]);
        $this->firebase([
            'uid' => $client->firebase_uid, 'email' => $client->email, 'name' => $client->name,
        ])->withHeader('Accept-Language', 'en')
            ->getJson('/api/my/account')->assertForbidden()
            ->assertJsonPath('code', 'account_disabled')->assertJsonPath('message', 'Account disabled.');
        $this->getJson('/api/my/vehicles')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_confirming_a_customer_session_keeps_the_customers_garage_accessible(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client, 'firebase_uid' => 'garage-owner-uid']);
        $vehicle = OwnedVehicle::factory()->create(['user_id' => $client->id]);
        $other = User::factory()->create(['role' => UserRole::Client, 'firebase_uid' => 'other-owner-uid']);
        OwnedVehicle::factory()->create(['user_id' => $other->id]);

        $this->firebase([
            'uid' => $client->firebase_uid, 'email' => $client->email, 'name' => $client->name,
        ])->getJson('/api/my/account')->assertOk()->assertJsonPath('data.id', $client->id);
        $this->getJson('/api/my/vehicles')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $vehicle->id);
    }
}
