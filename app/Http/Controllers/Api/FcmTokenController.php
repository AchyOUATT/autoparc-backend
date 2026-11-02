<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientFcmToken;
use App\Models\User;
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
        $data = $request->validate([
            'fcm_token' => ['required', 'string'],
            'locale' => ['sometimes', 'nullable', 'string', 'in:fr,en'],
        ]);

        $values = ['fcm_token' => $data['fcm_token']];
        if (isset($data['locale'])) {
            $values['fcm_locale'] = $data['locale'];
        }
        $request->user()->update($values);
        if (isset($data['locale'])) {
            // The same device may have registered both profiles. Its current
            // language must not be shadowed by an older customer registration.
            ClientFcmToken::where('fcm_token', $data['fcm_token'])->update(['locale' => $data['locale']]);
        }

        return response()->json(['message' => __('Token FCM enregistré.')]);
    }

    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token'   => ['required', 'string'],
            'firebase_uid' => ['required', 'string'],
            'locale' => ['sometimes', 'nullable', 'string', 'in:fr,en'],
        ]);

        ClientFcmToken::upsertToken($data['firebase_uid'], $data['fcm_token'], $data['locale'] ?? null);
        if (isset($data['locale'])) {
            User::where('fcm_token', $data['fcm_token'])->update(['fcm_locale' => $data['locale']]);
        }

        return response()->json(['message' => __('Token FCM client enregistré.')]);
    }
}
