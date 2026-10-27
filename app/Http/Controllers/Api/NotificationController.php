<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private FcmService $fcm) {}

    // ── Staff ────────────────────────────────────────────────────────

    /**
     * Liste les notifications du staff connecté.
     * GET /notifications (auth:sanctum + staff)
     */
    public function indexStaff(Request $request): JsonResponse
    {
        $notifications = AppNotification::forStaff($request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($notifications);
    }

    // ── PARTENAIRE ────────────────────────────────────────────────────
    //
    // Mêmes quatre gestes que le personnel et que le client, sur la boîte du
    // partenaire. Elle n'existait pas : `recipient_type` ne connaissait que
    // `staff` et `client`, de sorte que rien ne pouvait leur être adressé.
    //
    // La boîte est désignée par l'identifiant de l'UTILISATEUR, et non par
    // celui du compte partenaire : c'est l'utilisateur qui se connecte et qui
    // lit, et `recipient_id` désigne partout celui qui lit.

    /**
     * Les notifications du partenaire connecté.
     * GET /partner/notifications (auth:sanctum + partner)
     */
    public function indexPartner(Request $request): JsonResponse
    {
        $notifications = AppNotification::forPartner($request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($notifications);
    }

    /**
     * Nombre de notifications non lues du partenaire connecté.
     * GET /partner/notifications/unread-count
     */
    public function unreadCountPartner(Request $request): JsonResponse
    {
        $count = AppNotification::forPartner($request->user()->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marque une notification du partenaire comme lue.
     *
     * Le contrôle d'appartenance est le MÊME que celui du personnel, et il
     * doit l'être : sans lui, un partenaire marquerait lue la notification
     * d'un autre — et, la route liant le modèle par son identifiant, il
     * apprendrait au passage combien il y en a.
     */
    public function markReadPartner(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless(
            $notification->recipient_type === 'partner' &&
            $notification->recipient_id === (string) $request->user()->id,
            403,
        );

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Lu.']);
    }

    /**
     * Marque toutes les notifications du partenaire comme lues.
     * POST /partner/notifications/read-all
     */
    public function markAllReadPartner(Request $request): JsonResponse
    {
        AppNotification::forPartner($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes lues.']);
    }

    /**
     * Nombre de notifications non lues du staff connecté.
     * GET /notifications/unread-count (auth:sanctum + staff)
     */
    public function unreadCountStaff(Request $request): JsonResponse
    {
        $count = AppNotification::forStaff($request->user()->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marque une notification comme lue.
     * POST /notifications/{notification}/read (auth:sanctum + staff)
     */
    public function markReadStaff(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless(
            $notification->recipient_type === 'staff' &&
            $notification->recipient_id === (string) $request->user()->id,
            403,
        );

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Lu.']);
    }

    /**
     * Marque toutes les notifications non lues du staff comme lues.
     * POST /notifications/read-all (auth:sanctum + staff)
     */
    public function markAllReadStaff(Request $request): JsonResponse
    {
        AppNotification::forStaff($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes marquées comme lues.']);
    }

    // ── Client (Firebase) ─────────────────────────────────────────────

    /**
     * Liste les notifications du client identifié par son firebase_uid.
     * GET /my/notifications (firebase middleware)
     */
    public function indexClient(Request $request): JsonResponse
    {
        $uid = $request->attributes->get('firebase_uid');

        $notifications = AppNotification::forClient($uid)
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($notifications);
    }

    /**
     * Nombre de notifications non lues du client.
     * GET /my/notifications/unread-count
     */
    public function unreadCountClient(Request $request): JsonResponse
    {
        $uid   = $request->attributes->get('firebase_uid');
        $count = AppNotification::forClient($uid)->unread()->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marque une notification client comme lue.
     * POST /my/notifications/{notification}/read
     */
    public function markReadClient(Request $request, AppNotification $notification): JsonResponse
    {
        $uid = $request->attributes->get('firebase_uid');

        abort_unless(
            $notification->recipient_type === 'client' &&
            $notification->recipient_id === $uid,
            403,
        );

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Lu.']);
    }

    /**
     * Marque toutes les notifications client comme lues.
     * POST /my/notifications/read-all
     */
    public function markAllReadClient(Request $request): JsonResponse
    {
        $uid = $request->attributes->get('firebase_uid');

        AppNotification::forClient($uid)->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes marquées comme lues.']);
    }

    // ── Broadcast staff → clients (tips, conseils) ────────────────────

    /**
     * Envoie un conseil / tip à tous les clients enregistrés.
     * POST /broadcast-tip (staff)
     */
    public function broadcastTip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body'  => ['required', 'string', 'max:500'],
        ]);

        // Persiste la notification pour tous les clients actifs
        AppNotification::broadcastToClients(
            type:  'tip',
            title: $data['title'],
            body:  $data['body'],
            data:  ['type' => 'tip'],
        );

        // Envoie le push FCM à tous les tokens clients connus
        $tokens = ClientFcmToken::allTokens();
        $this->fcm->sendToTokens(
            tokens: $tokens,
            title:  $data['title'],
            body:   $data['body'],
            data:   ['type' => 'tip'],
        );

        return response()->json([
            'message'      => 'Broadcast envoyé.',
            'tokens_count' => count($tokens),
        ]);
    }
}
