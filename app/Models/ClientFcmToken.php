<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientFcmToken extends Model
{
    protected $fillable = ['firebase_uid', 'fcm_token'];

    /**
     * Enregistre ou met a jour le token FCM d'un client.
     * Un token peut changer ; un firebase_uid peut avoir plusieurs appareils.
     */
    public static function upsertToken(string $firebaseUid, string $token): void
    {
        // Supprime les éventuels doublon sur d'autres UIDs (token transféré d'un compte à un autre)
        self::where('fcm_token', $token)
            ->where('firebase_uid', '!=', $firebaseUid)
            ->delete();

        self::updateOrCreate(
            ['fcm_token'    => $token],
            ['firebase_uid' => $firebaseUid],
        );
    }

    /**
     * Retourne tous les tokens FCM associés à un firebase_uid.
     */
    public static function tokensForUid(string $firebaseUid): array
    {
        return self::where('firebase_uid', $firebaseUid)
            ->pluck('fcm_token')
            ->all();
    }

    /**
     * Retourne tous les tokens FCM des clients (pour un broadcast).
     */
    public static function allTokens(): array
    {
        return self::pluck('fcm_token')->all();
    }
}
