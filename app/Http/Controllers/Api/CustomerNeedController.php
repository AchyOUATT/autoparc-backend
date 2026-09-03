<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerNeedRequest;
use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\CustomerNeed;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerNeedController extends Controller
{
    public function __construct(private FcmService $fcm) {}

    /**
     * POST /api/needs
     * Accessible à tous (clients connectés ou visiteurs anonymes).
     */
    public function store(StoreCustomerNeedRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $data['type'] = 'vehicle'; // formulaire client exclusivement pour les véhicules
        $need         = CustomerNeed::create($data);

        // ── Notifie tout le staff ─────────────────────────────────
        $typeLabels = [
            'vehicle'   => 'Véhicule',
            'part'      => 'Pièce détachée',
            'accessory' => 'Accessoire',
        ];
        $typeLabel = $typeLabels[$need->type] ?? $need->type;
        $title     = 'Nouveau besoin client';
        $body      = "Type : $typeLabel — " . mb_substr($need->description, 0, 80);
        $data      = ['type' => 'new_need', 'need_id' => (string) $need->id];

        AppNotification::notifyAllStaff('new_need', $title, $body, $data);

        $staffTokens = User::whereNotNull('fcm_token')->pluck('fcm_token')->all();
        $this->fcm->sendToTokens($staffTokens, $title, $body, $data);

        return response()->json([
            'message' => 'Besoin enregistré. Nous vous contacterons dès que possible.',
            'data'    => ['id' => $need->id, 'status' => $need->status],
        ], 201);
    }

    /**
     * GET /api/needs/mine?firebase_uid=xxx
     * Client — ses propres besoins uniquement.
     */
    public function mine(Request $request): JsonResponse
    {
        $uid = $request->query('firebase_uid');

        if (blank($uid)) {
            return response()->json(['data' => [], 'message' => 'firebase_uid manquant.'], 400);
        }

        $needs = CustomerNeed::query()
            ->where('firebase_uid', $uid)
            ->with('brand', 'vehicleModel')
            ->latest()
            ->get();

        return response()->json([
            'data' => $needs->map(fn ($n) => $this->format($n))->values(),
        ]);
    }

    /**
     * GET /api/needs
     * Staff uniquement — liste paginée avec filtres.
     */
    public function index(Request $request): JsonResponse
    {
        $needs = CustomerNeed::query()
            ->with('brand', 'vehicleModel')
            ->when($request->filled('type'),   fn ($q) => $q->where('type',   $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return response()->json([
            'data' => $needs->map(fn ($n) => $this->format($n)),
            'meta' => [
                'total'        => $needs->total(),
                'current_page' => $needs->currentPage(),
                'last_page'    => $needs->lastPage(),
            ],
        ]);
    }

    /**
     * PUT /api/needs/{need}
     * Staff — met à jour le statut et les notes.
     */
    public function update(Request $request, CustomerNeed $need): JsonResponse
    {
        $data = $request->validate([
            'status'      => ['sometimes', Rule::in(['pending', 'contacted', 'fulfilled', 'cancelled'])],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldStatus = $need->status;
        $need->update($data);
        $need->refresh();

        // ── Notifie le client si son statut a changé et qu'il a un UID ──
        if (isset($data['status']) && $data['status'] !== $oldStatus && $need->firebase_uid) {
            $statusLabels = [
                'pending'   => 'En attente',
                'contacted' => 'Contacté',
                'fulfilled' => 'Satisfait',
                'cancelled' => 'Annulé',
            ];
            $label   = $statusLabels[$need->status] ?? $need->status;
            $title   = 'Votre besoin a été mis à jour';
            $body    = "Statut : $label — " . mb_substr($need->description, 0, 60);
            $payload = [
                'type'    => 'need_status_update',
                'need_id' => (string) $need->id,
                'status'  => $need->status,
            ];

            AppNotification::notifyClient($need->firebase_uid, 'need_status_update', $title, $body, $payload);

            $tokens = ClientFcmToken::tokensForUid($need->firebase_uid);
            $this->fcm->sendToTokens($tokens, $title, $body, $payload);
        }

        return response()->json(['data' => $this->format($need)]);
    }

    private function format(CustomerNeed $n): array
    {
        return [
            'id'            => $n->id,
            'type'          => $n->type,
            'description'   => $n->description,
            'budget_max'    => $n->budget_max ? (float) $n->budget_max : null,
            'currency'      => $n->currency,
            // Critères structurés
            'brand_id'          => $n->brand_id,
            'brand_name'        => $n->brand?->name,
            'vehicle_model_id'  => $n->vehicle_model_id,
            'vehicle_model_name' => $n->vehicleModel?->name,
            'vehicle_type'      => $n->vehicle_type,
            'body_style'    => $n->body_style,
            'year_min'      => $n->year_min,
            'year_max'      => $n->year_max,
            // Contact
            'contact_name'  => $n->contact_name,
            'contact_phone' => $n->contact_phone,
            'contact_email' => $n->contact_email,
            'status'        => $n->status,
            'status_label'  => $n->statusLabel(),
            'staff_notes'   => $n->staff_notes,
            'created_at'    => $n->created_at?->toIso8601String(),
        ];
    }
}
