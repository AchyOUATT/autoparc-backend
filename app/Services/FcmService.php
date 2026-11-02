<?php

namespace App\Services;

use App\Models\ClientFcmToken;
use App\Models\User;
use App\Support\NotificationText;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des notifications push via Firebase Cloud Messaging (HTTP v1).
 * Utilise le meme compte de service que FirebaseAuthService.
 */
class FcmService
{
    // Initialisé de façon lazy — uniquement au premier envoi effectif.
    private ?\Kreait\Firebase\Contract\Messaging $messaging = null;

    /**
     * Retourne l'instance Messaging, en l'initialisant au premier appel.
     * Lève une exception si le fichier de service account est absent.
     */
    private function messaging(): \Kreait\Firebase\Contract\Messaging
    {
        if ($this->messaging === null) {
            $this->messaging = (new Factory)
                ->withServiceAccount(config('services.firebase.credentials'))
                ->createMessaging();
        }

        return $this->messaging;
    }

    /**
     * Envoie une notification a un unique token FCM.
     *
     * @param array<string,string> $data  Payload de données (pour deep link Flutter)
     */
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array  $data = [],
    ): void {
        try {
            $locale = $this->tokenLocales([$token])[$token] ?? 'fr';
            $text = NotificationText::forLocale($title, $body, $data, $locale);
            $message = CloudMessage::fromArray(['token' => $token])
                ->withNotification(Notification::create($text['title'], $text['body']))
                ->withData(array_map('strval', $data)); // FCM exige des strings

            $this->messaging()->send($message);
        } catch (\Throwable $e) {
            Log::warning('[FCM] sendToToken failed', [
                'token'   => substr($token, 0, 20) . '…',
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie en multicast (jusqu'a 500 tokens par lot).
     *
     * @param string[] $tokens
     * @param array<string,string> $data
     */
    public function sendToTokens(
        array  $tokens,
        string $title,
        string $body,
        array  $data = [],
    ): void {
        if (empty($tokens)) {
            return;
        }

        $stringData   = array_map('strval', $data);

        $groups = [];
        $locales = $this->tokenLocales($tokens);
        foreach (array_unique($tokens) as $token) {
            $groups[($locales[$token] ?? null) === 'en' ? 'en' : 'fr'][] = $token;
        }

        // FCM multicast : max 500 tokens par appel
        foreach ($groups as $locale => $group) {
          $text = NotificationText::forLocale($title, $body, $data, $locale);
          $notification = Notification::create($text['title'], $text['body']);
          foreach (array_chunk($group, 500) as $chunk) {
            try {
                $message = CloudMessage::new()
                    ->withNotification($notification)
                    ->withData($stringData);

                $this->messaging()->sendMulticast($message, $chunk);
            } catch (\Throwable $e) {
                Log::warning('[FCM] sendToTokens chunk failed', [
                    'count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
          }
        }
    }

    /** At most two lookups per batch; unknown/older devices retain French. */
    private function tokenLocales(array $tokens): array
    {
        $locales = [];
        try {
            foreach (array_chunk($tokens, 500) as $chunk) {
                $locales += ClientFcmToken::whereIn('fcm_token', $chunk)->pluck('locale', 'fcm_token')->all();
                $locales += User::whereIn('fcm_token', $chunk)->pluck('fcm_locale', 'fcm_token')->all();
            }
        } catch (\Throwable $error) {
            // Push delivery remains best effort if the preference lookup fails.
            Log::warning('[FCM] token locale lookup failed', ['error' => $error->getMessage()]);
        }

        return $locales;
    }
}
