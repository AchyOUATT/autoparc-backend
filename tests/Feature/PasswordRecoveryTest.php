<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Notifications\AccountPasswordReset;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://autoparc.example.test',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'noreply@autoparc.example.test',
        ]);
        Notification::fake();
    }

    private function requestLink(User $user, string $type = 'partner', string $locale = 'fr'): string
    {
        $this->postJson('/api/auth/password/forgot', ['email' => mb_strtoupper($user->email), 'account_type' => $type, 'locale' => $locale])
            ->assertOk()->assertJsonPath('code', 'password_reset_requested')->assertHeader('Cache-Control', 'no-store, private');
        Notification::assertSentTo($user, AccountPasswordReset::class);
        $notification = Notification::sent($user, AccountPasswordReset::class)->last();

        return app(PasswordRecovery::class)->resetUrl($notification->token, $user->email, $type, $locale);
    }

    private function newPassword(string $password = 'NewPassword1234'): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    public static function roles(): array
    {
        return array_map(fn (UserRole $role) => [$role, $role === UserRole::Partner ? 'partner' : 'staff'], [...UserRole::staffRoles(), UserRole::Partner]);
    }

    #[DataProvider('roles')]
    public function test_server_accounts_can_reset_without_changing_identity_or_role(UserRole $role, string $type): void
    {
        $user = User::factory()->create(['role' => $role, 'email' => 'Legacy.Caps@example.test', 'firebase_uid' => 'existing-uid']);
        $user->createToken('first');
        $user->createToken('second');
        $before = $user->fresh()->getRawOriginal();
        $url = $this->requestLink($user, $type);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $notification = Notification::sent($user, AccountPasswordReset::class)->last();
        $stored = DB::table('password_reset_tokens')->first();
        $this->assertNotSame($notification->token, $stored->token);
        $this->assertTrue(Hash::check($notification->token, $stored->token));
        $this->assertSame($user->email, $stored->email);

        $this->get($url)->assertOk()->assertSee('name="password"', false)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('<script', false);
        $this->post($url, $this->newPassword())->assertOk()->assertSee('Mot de passe modifié');
        $this->assertTrue(Hash::check('NewPassword1234', $user->fresh()->password));
        foreach (['id', 'email', 'role', 'is_active', 'firebase_uid'] as $attribute) {
            $this->assertSame($before[$attribute], $user->fresh()->getRawOriginal($attribute));
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('owned_vehicles', 0);
        $this->post($url, $this->newPassword('ReplayPassword123'))->assertStatus(422)->assertSee('Ce lien ne peut plus être utilisé');
        $this->assertTrue(Hash::check('NewPassword1234', $user->fresh()->password));
    }

    public function test_unknown_wrong_space_inactive_and_client_accounts_receive_the_same_generic_response(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $partner = User::factory()->create(['role' => UserRole::Partner]);
        $staff = User::factory()->create(['role' => UserRole::Admin]);
        $inactive = User::factory()->create(['role' => UserRole::Partner, 'is_active' => false]);
        $expected = null;
        foreach ([['missing@example.test', 'partner'], [$client->email, 'partner'], [$partner->email, 'staff'], [$staff->email, 'partner'], [$inactive->email, 'partner']] as [$email, $type]) {
            $response = $this->postJson('/api/auth/password/forgot', ['email' => $email, 'account_type' => $type])->assertOk();
            $expected ??= $response->json();
            $this->assertSame($expected, $response->json());
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_client_space_is_rejected_instead_of_resetting_a_firebase_account(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $hash = $client->password;
        $this->postJson('/api/auth/password/forgot', ['email' => $client->email, 'account_type' => 'client'])->assertUnprocessable()->assertJsonValidationErrors('account_type');
        $this->assertSame($hash, $client->fresh()->password);
        Notification::assertNothingSent();
    }

    public function test_ambiguous_case_insensitive_legacy_addresses_are_not_selected_arbitrarily(): void
    {
        User::factory()->create(['role' => UserRole::Partner, 'email' => 'DUPLICATE@example.test']);
        User::factory()->create(['role' => UserRole::Partner, 'email' => 'duplicate@example.test']);
        $this->postJson('/api/auth/password/forgot', ['email' => 'Duplicate@example.test', 'account_type' => 'partner'])->assertOk();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_partner_without_email_is_not_matched_by_an_unverified_phone_number(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner, 'email' => null, 'phone' => '+22670000001']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->phone, 'account_type' => 'partner'])->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_broker_cooldown_does_not_reveal_an_existing_account_or_replace_its_first_token(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email, 'account_type' => 'partner'])->assertOk()->assertJsonPath('code', 'password_reset_requested');
        Notification::assertSentToTimes($user, AccountPasswordReset::class, 1);
        $this->get($url)->assertOk()->assertSee('name="password"', false);
    }

    public function test_recovery_is_rate_limited_by_address_even_if_proxy_headers_change(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('X-Forwarded-For', '203.0.113.'.($i + 1))->postJson('/api/auth/password/forgot', ['email' => 'missing@example.test', 'account_type' => 'partner'])->assertOk();
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.99')->postJson('/api/auth/password/forgot', ['email' => 'MISSING@example.test', 'account_type' => 'staff'])->assertStatus(429);
    }

    public static function unavailableMailers(): array
    {
        return [['log'], ['array'], ['failover'], ['resend'], ['postmark']];
    }

    #[DataProvider('unavailableMailers')]
    public function test_non_delivering_mailers_never_log_tokens_or_claim_recovery_is_available(string $mailer): void
    {
        config(['mail.default' => $mailer]);
        $user = User::factory()->create(['role' => UserRole::Partner]);
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $this->postJson('/api/auth/password/forgot', ['email' => $email, 'account_type' => 'partner'])->assertStatus(503)->assertJsonPath('code', 'password_reset_unavailable');
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_placeholder_sender_local_smtp_and_insecure_production_url_are_unavailable(): void
    {
        $recovery = app(PasswordRecovery::class);
        config(['mail.from.address' => 'hello@example.com']);
        $this->assertFalse($recovery->deliveryAvailable());
        config(['mail.from.address' => 'noreply@autoparc.example.test', 'mail.mailers.smtp.host' => '127.0.0.1']);
        $this->assertFalse($recovery->deliveryAvailable());
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'app.url' => 'http://autoparc.example.test']);
        $this->app['env'] = 'production';
        $this->assertFalse($recovery->deliveryAvailable());
    }

    public function test_transport_failure_returns_a_generic_response_without_logging_private_data(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('sensitive-token-and-address'));
        Log::spy();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email, 'account_type' => 'partner'])->assertOk()->assertDontSee('sensitive-token-and-address');
        Log::shouldHaveReceived('error')->once()->with('password_reset_delivery_failed');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_changed_role_cannot_repurpose_a_link_or_override_its_signed_context_in_the_post_body(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user);
        $hash = $user->password;
        $user->forceFill(['role' => UserRole::Admin])->save();
        $this->post($url, [...$this->newPassword(), 'account_type' => 'staff', 'email' => $user->email])->assertStatus(422);
        $this->post(str_replace('account_type=partner', 'account_type=staff', $url), $this->newPassword())->assertStatus(422);
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_post_body_cannot_target_another_user_in_the_same_space(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $other = User::factory()->create(['role' => UserRole::Partner]);
        $otherHash = $other->password;
        $url = $this->requestLink($user);
        $this->post($url, [...$this->newPassword(), 'email' => $other->email])->assertOk();
        $this->assertTrue(Hash::check('NewPassword1234', $user->fresh()->password));
        $this->assertSame($otherHash, $other->fresh()->password);
    }

    public function test_deactivation_after_issuance_prevents_reset(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user);
        $hash = $user->password;
        $user->forceFill(['is_active' => false])->save();
        $this->post($url, $this->newPassword())->assertStatus(422);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_expired_used_and_tampered_links_show_a_localized_safe_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user, locale: 'en');
        $this->get($url.'&extra=1')->assertOk()->assertSee('This link can no longer be used')->assertDontSee('name="password"', false);
        $this->travel(61)->minutes();
        $this->get($url)->assertOk()->assertSee('This link can no longer be used');
        $this->post($url, $this->newPassword())->assertStatus(422);
    }

    public function test_broker_expiration_is_enforced_even_when_signature_has_not_expired(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user);
        DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(61)]);
        $this->get($url)->assertOk()->assertSee('Ce lien ne peut plus être utilisé');
        $this->post($url, $this->newPassword())->assertStatus(422);
    }

    public static function badPasswords(): array
    {
        return [['short123'], ['letterswithoutdigits'], ['1234567890123'], [str_repeat('x', 73).'1'], [str_repeat('é', 36).'12'], ["invalid\0password123"]];
    }

    #[DataProvider('badPasswords')]
    public function test_password_policy_is_enforced_without_consuming_the_token(string $password): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $hash = $user->password;
        $url = $this->requestLink($user, locale: 'en');
        $this->post($url, $this->newPassword($password))->assertStatus(422)->assertSee('name="password"', false);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_confirmation_and_csrf_are_required(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user);
        $this->post($url, ['password' => 'NewPassword1234', 'password_confirmation' => 'mismatch'])->assertStatus(422)->assertSee('Les deux mots de passe ne correspondent pas.');
        $this->app['env'] = 'local';
        $this->post($url, $this->newPassword())->assertStatus(419);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_french_password_rule_errors_are_translated(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $url = $this->requestLink($user);
        $this->post($url, $this->newPassword('onlyletterspassword'))->assertStatus(422)->assertSee('au moins un chiffre')->assertDontSee('validation.password');
        $this->post($url, $this->newPassword('1234567890123'))->assertStatus(422)->assertSee('au moins une lettre')->assertDontSee('validation.password');
    }

    public function test_mail_and_browser_page_are_english_and_host_header_cannot_poison_reset_url(): void
    {
        $user = User::factory()->create(['role' => UserRole::Partner]);
        $this->withHeader('Host', 'attacker.example.test')->postJson('/api/auth/password/forgot', ['email' => $user->email, 'account_type' => 'partner', 'locale' => 'en'])->assertOk();
        $notification = Notification::sent($user, AccountPasswordReset::class)->first();
        $mail = $notification->toMail($user);
        $this->assertSame('AutoParc — Password reset', $mail->subject);
        $this->assertStringStartsWith('https://autoparc.example.test/password/reset/', $mail->viewData['url']);
        $html = view('emails.password-reset', $mail->viewData)->render();
        $this->assertStringContainsString('Partner space', $html);
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertStringNotContainsString('attacker.example.test', $html);
        $this->get($mail->viewData['url'])->assertOk()->assertSee('Reset your password')->assertSee('At least 12 characters');
        $this->post($mail->viewData['url'], $this->newPassword())->assertOk()->assertSee('Password updated');
    }

    public function test_assisted_partner_rotation_supports_no_email_preserves_status_and_revokes_only_the_target_sessions(): void
    {
        $partner = User::factory()->create(['role' => UserRole::Partner, 'email' => null, 'is_active' => false, 'firebase_uid' => null]);
        $profile = PartnerAccount::create(['user_id' => $partner->id, 'company_name' => 'Test', 'contact_name' => 'Test', 'phone' => $this->numeroDePartenaire($partner->id), 'status' => 'suspended']);
        $partner->createToken('partner', ['partner']);
        $staff = User::factory()->create(['role' => UserRole::Admin]);
        $staff->createToken('staff');
        $staffHash = $staff->password;
        $this->assertSame(0, Artisan::call('partner:rotate-password', ['userId' => (string) $partner->id]));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/Nouveau mot de passe : (.{24})/', $output);
        preg_match('/Nouveau mot de passe : (.{24})/', $output, $matches);
        $this->assertTrue(Hash::check($matches[1], $partner->fresh()->password));
        $this->assertFalse($partner->fresh()->is_active);
        $this->assertSame(UserRole::Partner, $partner->fresh()->role);
        $this->assertNull($partner->fresh()->firebase_uid);
        $this->assertNull($partner->fresh()->email);
        $this->assertSame('suspended', $profile->fresh()->status);
        $this->assertSame(0, $partner->tokens()->count());
        $this->assertSame(1, $staff->tokens()->count());
        $this->assertSame($staffHash, $staff->fresh()->password);
    }

    public function test_assisted_rotation_rejects_staff_clients_and_invalid_identifiers(): void
    {
        foreach ([UserRole::Admin, UserRole::Client] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $hash = $user->password;
            $user->createToken('untouched');
            $this->artisan('partner:rotate-password', ['userId' => (string) $user->id])->assertFailed();
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertSame(1, $user->tokens()->count());
        }
        $this->artisan('partner:rotate-password', ['userId' => 'not-an-id'])->assertExitCode(2);
    }

    public function test_manual_rotations_invalidate_existing_reset_links_without_touching_other_accounts(): void
    {
        $partner = User::factory()->create(['role' => UserRole::Partner]);
        $staff = User::factory()->create(['role' => UserRole::Admin]);
        $partnerUrl = $this->requestLink($partner);
        $staffUrl = $this->requestLink($staff, 'staff');
        $this->artisan('staff:rotate-password', ['email' => $staff->email])->assertSuccessful();
        $this->get($staffUrl)->assertOk()->assertSee('Ce lien ne peut plus être utilisé');
        $this->get($partnerUrl)->assertOk()->assertSee('name="password"', false);
        $this->artisan('partner:rotate-password', ['userId' => (string) $partner->id])->assertSuccessful();
        $this->get($partnerUrl)->assertOk()->assertSee('Ce lien ne peut plus être utilisé');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
}
