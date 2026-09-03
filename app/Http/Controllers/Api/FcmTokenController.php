<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientFcmToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    /**
     * Enregistre ou rafraichit le token FCM d'un utilisateur.
     *
     * - Staff (auth:sanctum) : sauvegarde dans users.fcm_token
     * - Client (firebase)    : sauvegarde dans client_fcm_tokens
     *
     * Le token est envoyé dans les deux cas depuis Flutter au démarrage de l'app.
     * La route est protégée par le middleware approprié selon le profil (voir api.php).
     */
    public function storeStaff(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string']]);

        $request->user()->update(['fcm_token' => $data['fcm_token']]);

        return response()->json(['message' => 'Token FCM enregistré.']);
    }

    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token'   => ['required', 'string'],
            'firebase_uid' => ['required', 'string'],
        ]);

        ClientFcmToken::upsertToken($data['firebase_uid'], $data['fcm_token']);

        return response()->json(['message' => 'Token FCM client enregistré.']);
    }
}
