<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\PasswordRecovery;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class AccountPasswordReset extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $accountType,
        public readonly string $language,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $texts = Lang::get('password_recovery', [], $this->language);

        return (new MailMessage)->subject($texts['mail_subject'])
            ->view(['html' => 'emails.password-reset', 'text' => 'emails.password-reset-text'], [
                'texts' => $texts,
                'locale' => $this->language,
                'space' => $texts[$this->accountType],
                'minutes' => (int) config('auth.passwords.users.expire', 60),
                'url' => app(PasswordRecovery::class)->resetUrl($this->token, $notifiable->email, $this->accountType, $this->language),
            ]);
    }
}
