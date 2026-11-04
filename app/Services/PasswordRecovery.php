<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Aws\Ses\SesClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Resend\Client;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;

class PasswordRecovery
{
    public function credentials(string $email, string $accountType): array
    {
        // A closure keeps email matching case-insensitive on PostgreSQL too.
        // The same live role/active filter is used when issuing AND consuming.
        return ['recovery_scope' => function (Builder $query) use ($email, $accountType): void {
            $matches = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->limit(2)->pluck('id');
            // Legacy PostgreSQL rows could differ only by case. Never select
            // arbitrarily between accounts sharing the delivery address.
            $query->whereIn('id', $matches->count() === 1 ? $matches : [])
                ->where('is_active', true)
                ->whereIn('role', $accountType === 'partner' ? [UserRole::Partner->value] : UserRole::staffValues());
        }];
    }

    public function resetUrl(string $token, string $email, string $accountType, string $locale): string
    {
        // A relative signature binds the role and address without trusting Host.
        $path = URL::temporarySignedRoute('password.reset', now()->addMinutes((int) config('auth.passwords.users.expire', 60)), [
            'token' => $token,
            'email' => mb_strtolower($email),
            'account_type' => $accountType,
            'locale' => $locale,
        ], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    public function deliveryAvailable(): bool
    {
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || ! in_array($scheme, ['http', 'https'], true)
            || ($scheme !== 'https' && ! app()->environment(['local', 'testing']))) {
            return false;
        }

        $from = (string) config('mail.from.address');
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($from), '@example.com')) {
            return false;
        }

        return $this->usableMailer((string) config('mail.default'), []);
    }

    private function usableMailer(string $name, array $visited): bool
    {
        if (in_array($name, $visited, true)) {
            return false;
        }
        $visited[] = $name;
        $config = config('mail.mailers.'.$name);
        if (! is_array($config)) {
            return false;
        }

        return match ($config['transport'] ?? '') {
            'smtp' => $this->usableSmtp($config),
            'sendmail' => filled($config['path'] ?? null),
            'resend' => class_exists(Client::class) && filled(config('services.resend.key')),
            'postmark' => class_exists(PostmarkApiTransport::class) && filled(config('services.postmark.key')),
            'ses', 'ses-v2' => class_exists(SesClient::class) && filled(config('services.ses.key')) && filled(config('services.ses.secret')),
            // A failover to log would report success while disclosing a token
            // in logs. Every branch must therefore be a real mail transport.
            'failover', 'roundrobin' => ! empty($config['mailers']) && collect($config['mailers'])
                ->every(fn ($mailer) => is_string($mailer) && $this->usableMailer($mailer, $visited)),
            default => false,
        };
    }

    private function usableSmtp(array $config): bool
    {
        $host = filled($config['url'] ?? null)
            ? parse_url((string) $config['url'], PHP_URL_HOST)
            : ($config['host'] ?? null);

        return is_string($host) && trim($host) !== ''
            && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}
