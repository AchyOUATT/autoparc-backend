<?php

namespace App\Mail\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/** Sends through Google's HTTPS endpoints; no SMTP port or mailbox read access. */
class GmailApiTransport extends AbstractTransport
{
    private ?string $accessToken = null;

    private float $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        #[SensitiveParameter] private readonly string $clientSecret,
        #[SensitiveParameter] private readonly string $refreshToken,
        private readonly string $senderAddress,
        private readonly int $timeout = 10,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $raw = $this->mimePayload($message);
            $response = $this->request()->withToken($this->token())
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
                ]);
            $id = $response->json('id');
            if ($response->status() === 401) {
                $this->accessToken = null;
                $this->accessTokenExpiresAt = 0;
            }
            if (! $response->successful() || ! is_string($id) || trim($id) === '') {
                throw new TransportException('Gmail API delivery failed.');
            }
            $message->setMessageId($id);
        } catch (Throwable) {
            // Never attach the original exception: HTTP exceptions can contain
            // credentials, MIME content and password-reset URLs in their traces.
            throw new TransportException('Gmail API delivery failed.');
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null && microtime(true) < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }
        if (trim($this->clientId) === '' || trim($this->clientSecret) === '' || trim($this->refreshToken) === '') {
            throw new TransportException('Gmail API authorization is unavailable.');
        }

        $response = $this->request()->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $token = $response->json('access_token');
        $expires = $response->json('expires_in');
        $type = $response->json('token_type');
        $scope = $response->json('scope');
        if (! $response->successful() || ! is_string($token) || trim($token) === ''
            || ! is_int($expires) || $expires < 1 || $expires > 86400
            || ! is_string($type) || strtolower($type) !== 'bearer'
            || ! $this->allowedScope($scope)) {
            throw new TransportException('Gmail API authorization is unavailable.');
        }

        // Only retain this short-lived token in the transport's memory. The
        // refresh token stays in the environment, never in a cache/database.
        $this->accessToken = $token;
        $this->accessTokenExpiresAt = microtime(true) + max(0, $expires - 60);

        return $token;
    }

    private function allowedScope(mixed $scope): bool
    {
        // OAuth can omit scope when it is unchanged on refresh. The initial
        // authorization helper has already verified the minimal grant.
        if ($scope === null) {
            return true;
        }
        if (! is_string($scope)) {
            return false;
        }
        $scopes = preg_split('/\s+/', trim($scope));

        return in_array('https://www.googleapis.com/auth/gmail.send', $scopes, true)
            && array_diff($scopes, ['https://www.googleapis.com/auth/gmail.send', 'openid', 'email', 'https://www.googleapis.com/auth/userinfo.email']) === [];
    }

    private function request(): PendingRequest
    {
        // No automatic retry: an uncertain send response must not duplicate an
        // email. No redirects, which could forward credentials to another host.
        return Http::acceptJson()->connectTimeout(5)->timeout(max(1, min(30, $this->timeout)))
            ->withoutRedirecting()->withOptions(['verify' => true, 'debug' => false]);
    }

    private function mimePayload(SentMessage $message): string
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message || ! filter_var($this->senderAddress, FILTER_VALIDATE_EMAIL)) {
            throw new TransportException('Gmail API message is invalid.');
        }
        $email = MessageConverter::toEmail($original);
        $from = $email->getFrom();
        if (count($from) !== 1 || strcasecmp($from[0]->getAddress(), $this->senderAddress) !== 0
            || strcasecmp($message->getEnvelope()->getSender()->getAddress(), $this->senderAddress) !== 0
            || ($email->getSender() !== null && strcasecmp($email->getSender()->getAddress(), $this->senderAddress) !== 0)) {
            throw new TransportException('Gmail API sender does not match its configured account.');
        }

        // Gmail derives recipients from MIME headers, not a separate SMTP
        // envelope. Refuse a custom envelope that would silently change them.
        $headerRecipients = [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()];
        if ($this->addresses($headerRecipients) !== $this->addresses($message->getEnvelope()->getRecipients())) {
            throw new TransportException('Gmail API recipients do not match the message.');
        }
        $headers = $email->getPreparedHeaders();
        if (! $email->getHeaders()->has('Message-ID')) {
            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $message->getMessageId());
        }
        if ($email->getBcc() !== []) {
            // Symfony strips Bcc for SMTP serialization. Restore it solely in
            // the Gmail API upload so hidden recipients are not dropped.
            $headers->addMailboxListHeader('Bcc', $email->getBcc());
        }

        return $headers->toString().$email->getBody()->toString();
    }

    /** @param Address[] $addresses */
    private function addresses(array $addresses): array
    {
        $values = array_unique(array_map(fn (Address $address) => $address->getAddress(), $addresses));
        sort($values, SORT_STRING);

        return $values;
    }

    public function __toString(): string
    {
        return 'gmail_api';
    }

    public function __debugInfo(): array
    {
        return ['transport' => 'gmail_api'];
    }
}
