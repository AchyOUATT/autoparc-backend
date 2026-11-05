<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\Transport\GmailApiTransport;
use App\Models\User;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class GmailApiTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://autoparc.example.test',
            'mail.default' => 'gmail_api',
            'mail.from.address' => 'sender@example.test',
            'mail.from.name' => 'AutoParc',
            'services.gmail.client_id' => 'test-client-id',
            'services.gmail.client_secret' => 'test-client-secret',
            'services.gmail.refresh_token' => 'test-refresh-token',
        ]);
        Http::preventStrayRequests();
    }

    private function fakeDelivery(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer',
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/gmail.send',
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-message-id']),
        ]);
    }

    private function transport(): GmailApiTransport
    {
        return Mail::mailer('gmail_api')->getSymfonyTransport();
    }

    private function email(): Email
    {
        return (new Email)->from('sender@example.test')->to('buyer@example.test')
            ->subject('Réinitialisation AutoParc')->text('Texte privé de récupération')
            ->html('<p>Texte privé de récupération</p>');
    }

    private function uploadedMime(): string
    {
        $requests = Http::recorded(fn ($request) => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        $encoded = $requests->last()[0]['raw'];

        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    public function test_real_laravel_notification_uses_https_and_renders_the_signed_reset_mail(): void
    {
        $this->fakeDelivery();
        $user = User::factory()->create(['role' => UserRole::Partner, 'email' => 'partner@example.test']);
        $before = $user->fresh()->getRawOriginal();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email, 'account_type' => 'partner', 'locale' => 'en'])
            ->assertOk()->assertJsonPath('code', 'password_reset_requested');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token' && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret' && $request['refresh_token'] === 'test-refresh-token'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        Http::assertSent(fn ($request) => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && preg_match('/\A[A-Za-z0-9_-]+\z/', $request['raw']) === 1);
        $mime = $this->uploadedMime();
        $this->assertStringContainsString('From: AutoParc <sender@example.test>', $mime);
        $this->assertStringContainsString('To: partner@example.test', $mime);
        $this->assertStringContainsString('https://autoparc.example.test/password/reset/', $mime);
        $this->assertStringContainsString('signature=', $mime);
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('Reset your password', quoted_printable_decode($mime));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        foreach (['password', 'role', 'is_active', 'firebase_uid'] as $attribute) {
            $this->assertSame($before[$attribute], $user->fresh()->getRawOriginal($attribute));
        }
    }

    public function test_mime_preserves_to_cc_bcc_and_attachments_without_mutating_the_original(): void
    {
        $this->fakeDelivery();
        $email = $this->email()->cc('cc@example.test')->bcc('hidden@example.test')->attach('attachment-content', 'document.txt');
        $sent = $this->transport()->send($email);
        $mime = $this->uploadedMime();
        $this->assertStringContainsString('To: buyer@example.test', $mime);
        $this->assertStringContainsString('Cc: cc@example.test', $mime);
        $this->assertStringContainsString('Bcc: hidden@example.test', $mime);
        $this->assertStringContainsString('filename=document.txt', $mime);
        $this->assertStringContainsString(base64_encode('attachment-content'), $mime);
        $this->assertSame('gmail-message-id', $sent->getMessageId());
        $this->assertCount(1, $email->getBcc());
        $this->assertStringNotContainsString('Bcc:', $email->toString());
    }

    public function test_unknown_or_wrong_space_accounts_do_not_contact_google_or_create_tokens(): void
    {
        Http::fake();
        $client = User::factory()->create(['role' => UserRole::Client]);
        foreach (['unknown@example.test', $client->email] as $email) {
            $this->postJson('/api/auth/password/forgot', ['email' => $email, 'account_type' => 'partner'])
                ->assertOk()->assertJsonPath('code', 'password_reset_requested');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_access_token_is_reused_only_in_memory_and_renewed_before_expiry(): void
    {
        $this->fakeDelivery();
        $transport = $this->transport();
        $transport->send($this->email());
        $transport->send($this->email());
        Http::assertSentCount(3);
        $property = new \ReflectionProperty($transport, 'accessTokenExpiresAt');
        $property->setValue($transport, microtime(true) - 1);
        $transport->send($this->email());
        Http::assertSentCount(5);
        $this->assertSame(['transport' => 'gmail_api'], $transport->__debugInfo());
        $this->assertSame('gmail_api', (string) $transport);
    }

    public static function missingCredentials(): array
    {
        return [['client_id'], ['client_secret'], ['refresh_token']];
    }

    #[DataProvider('missingCredentials')]
    public function test_incomplete_authorization_keeps_recovery_unavailable_without_http_or_tokens(string $key): void
    {
        Http::fake();
        config(['services.gmail.'.$key => '   ']);
        User::factory()->create(['role' => UserRole::Partner, 'email' => 'partner@example.test']);
        $this->assertFalse(app(PasswordRecovery::class)->deliveryAvailable());
        $this->postJson('/api/auth/password/forgot', ['email' => 'partner@example.test', 'account_type' => 'partner'])
            ->assertStatus(503)->assertJsonPath('code', 'password_reset_unavailable');
        Http::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_custom_envelope_and_sender_cannot_redirect_mail_or_spoof_another_account(): void
    {
        Http::fake();
        foreach ([
            [$this->email()->from('other@example.test'), null],
            [$this->email(), new Envelope(new Address('sender@example.test'), [new Address('other@example.test')])],
            [$this->email()->sender('other@example.test'), null],
            [$this->email(), new Envelope(new Address('other@example.test'), [new Address('buyer@example.test')])],
        ] as [$email, $envelope]) {
            try {
                $this->transport()->send($email, $envelope);
                $this->fail('The invalid message was accepted.');
            } catch (TransportException $error) {
                $this->assertSame('Gmail API delivery failed.', $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
        Http::assertNothingSent();
    }

    public static function badTokenResponses(): array
    {
        return [
            [['error' => 'invalid_grant', 'error_description' => 'test-refresh-token'], 400],
            [['access_token' => 'private-token', 'expires_in' => 3600, 'token_type' => 'MAC'], 200],
            [['access_token' => 'private-token', 'expires_in' => 0, 'token_type' => 'Bearer'], 200],
            [['access_token' => 'private-token', 'expires_in' => 86401, 'token_type' => 'Bearer'], 200],
            [['access_token' => '', 'expires_in' => 3600, 'token_type' => 'Bearer'], 200],
            [['access_token' => 'private-token', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'openid'], 200],
            [['access_token' => 'private-token', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly'], 200],
            [[], 302],
        ];
    }

    #[DataProvider('badTokenResponses')]
    public function test_bad_authorization_fails_without_sending_or_exposing_provider_details(array $body, int $status): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($body, $status, ['Location' => 'https://attacker.example.test'])]);
        $this->assertSanitizedFailure();
        Http::assertSentCount(1);
    }

    public static function failedSendResponses(): array
    {
        return [[401], [403], [429], [500], [302], [200]];
    }

    #[DataProvider('failedSendResponses')]
    public function test_failed_or_uncertain_sends_are_not_retried_and_errors_have_no_secrets(int $status): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['error' => 'test-client-secret test-refresh-token test-access-token private mail content'], $status, ['Location' => 'https://attacker.example.test']),
        ]);
        $this->assertSanitizedFailure();
        Http::assertSentCount(2);
    }

    public function test_connection_failure_is_sanitized_without_retry_or_cause_chain(): void
    {
        Http::fake(fn () => throw new ConnectionException('test-client-secret and private body'));
        $this->assertSanitizedFailure();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'messages/send'));
    }

    public function test_both_https_requests_disable_redirects_and_bound_network_waits(): void
    {
        $options = [];
        Http::fake(function ($request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;

            return str_contains($request->url(), 'oauth2.googleapis.com')
                ? Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer'])
                : Http::response(['id' => 'gmail-message-id']);
        });
        $this->transport()->send($this->email());
        $this->assertCount(2, $options);
        foreach ($options as $requestOptions) {
            $this->assertFalse($requestOptions['allow_redirects']);
            $this->assertTrue($requestOptions['verify']);
            $this->assertFalse($requestOptions['debug']);
            $this->assertSame(10, $requestOptions['timeout']);
            $this->assertSame(5, $requestOptions['connect_timeout']);
        }
    }

    public function test_unauthorized_send_is_not_retried_and_the_next_request_refreshes_authorization(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'first-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer'])
                ->push(['access_token' => 'next-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::sequence()
                ->push(['error' => 'invalid token'], 401)->push(['id' => 'next-message-id']),
        ]);
        $transport = $this->transport();
        $this->assertSanitizedFailure();
        Http::assertSentCount(2);
        $this->assertSame('next-message-id', $transport->send($this->email())->getMessageId());
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages/send')
            && $request->hasHeader('Authorization', 'Bearer next-access-token'));
    }

    public function test_a_failed_notification_invalidates_its_token_and_logs_no_private_content(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'test-refresh-token'], 400)]);
        $user = User::factory()->create(['role' => UserRole::Partner]);
        Log::spy();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email, 'account_type' => 'partner'])
            ->assertOk()->assertJsonPath('code', 'password_reset_requested');
        Log::shouldHaveReceived('error')->once()->with('password_reset_delivery_failed');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Http::assertSentCount(1);
    }

    private function assertSanitizedFailure(): void
    {
        try {
            $this->transport()->send($this->email());
            $this->fail('An unsuccessful delivery was accepted.');
        } catch (TransportException $error) {
            $this->assertSame('Gmail API delivery failed.', $error->getMessage());
            $this->assertSame('', $error->getDebug());
            $this->assertNull($error->getPrevious());
        }
    }
}
