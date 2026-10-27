<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerNeedRequest;
use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\CustomerNeed;
use App\Enums\UserRole;
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
        $data['type'] = $data['type'] ?? 'vehicle'; // défaut véhicule si omis

        // ── LE PREMIER NUMERO RESTE DANS L'ANCIENNE COLONNE ──────────────
        //
        // Ce n'est pas une redondance paresseuse. L'application PUBLIEE lit
        // `contact_phone` et ignore `contact_phones` : cesser de l'ecrire
        // ferait disparaitre le numero de l'ecran du personnel aujourd'hui
        // installe — et c'est precisement l'ecran qui sert a rappeler
        // quelqu'un.
        if (! empty($data['contact_phones'])) {
            $data['contact_phone'] = $data['contact_phones'][0];
        }
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
            ->with('brand', 'vehicleModel', 'partCategory', 'needManufacturer')
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
            ->with('brand', 'vehicleModel', 'partCategory', 'needManufacturer')
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
            // `approved` : le personnel a lu la demande et la juge bonne a
            // diffuser. C'est ce passage, et lui seul, qui l'envoie a tous les
            // partenaires — voir `diffuserAuxPartenaires()`.
            'status'      => ['sometimes', Rule::in(['pending', 'approved', 'contacted', 'fulfilled', 'cancelled'])],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldStatus = $need->status;
        $need->update($data);
        $need->refresh();

        // ── Notifie le client si son statut a changé et qu'il a un UID ──
        if (isset($data['status']) && $data['status'] !== $oldStatus && $need->firebase_uid) {
            $statusLabels = [
                'pending'   => 'En attente',
                'approved'  => 'Transmise aux partenaires',
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

        // ── LA DIFFUSION AUX PARTENAIRES ──────────────────────────────────
        //
        // Apres la notification du client, et HORS de sa condition : un besoin
        // saisi par le personnel au comptoir n'a pas de `firebase_uid`, et il
        // doit se diffuser comme les autres.
        if (($data['status'] ?? null) === 'approved' && $oldStatus !== 'approved') {
            $this->diffuserAuxPartenaires($need);
        }

        return response()->json(['data' => $this->format($need->refresh())]);
    }

    /**
     * Envoie la demande a tous les partenaires approuves.
     *
     * ── CE QUE LES PARTENAIRES RECOIVENT, ET CE QU'ILS NE RECOIVENT PAS ──
     *
     * Ce qu'il faut pour savoir s'ils ont la piece : le type, la description,
     * le budget, les criteres structures.
     *
     * PAS LE CONTACT DU CLIENT. Ni son nom, ni son numero, ni son courriel. Ce
     * client s'est adresse a la boutique, pas a quinze vendeurs : leur
     * transmettre son numero le ferait appeler par tous, et la boutique aurait
     * donne son marche en meme temps que sa demande. Le partenaire repond a la
     * boutique, qui rappelle le client.
     *
     * ── POURQUOI `broadcast_at` ───────────────────────────────────────────
     *
     * Pour ne diffuser QU'UNE FOIS. Repasser un besoin par `approved` — un
     * aller-retour de statut, une double soumission du formulaire, un clic
     * nerveux — rediffuserait a tout le monde. Quinze partenaires et trois
     * clics font quarante-cinq notifications pour une seule demande, et c'est
     * ainsi qu'on apprend aux gens a ignorer leurs notifications.
     *
     * La trace est posee AVANT l'envoi : si celui-ci echoue a mi-chemin, mieux
     * vaut une diffusion incomplete qu'une double diffusion au prochain clic.
     */
    private function diffuserAuxPartenaires(CustomerNeed $need): void
    {
        if ($need->broadcast_at !== null) {
            return;
        }

        $need->forceFill(['broadcast_at' => now()])->save();

        $typeLabels = [
            'vehicle'   => 'Véhicule',
            'part'      => 'Pièce détachée',
            'accessory' => 'Accessoire',
        ];
        $typeLabel = $typeLabels[$need->type] ?? $need->type;

        // Le budget groupé par trois, comme partout ailleurs : « 4 500 000 »
        // se relit, « 4500000 » non — et c'est là que se glisse le zéro de
        // trop quand un vendeur jauge s'il peut répondre.
        $budget = $need->budget_max
            ? ' — budget '.number_format((float) $need->budget_max, 0, ',', ' ').' FCFA'
            : '';

        $titre = 'Demande client à pourvoir';
        $corps = "$typeLabel$budget — ".mb_substr($need->description, 0, 90);

        AppNotification::notifyAllPartners('need_broadcast', $titre, $corps, [
            'type'    => 'need_broadcast',
            'need_id' => (string) $need->id,
            'besoin'  => $this->pourPartenaire($need),
        ]);

        $jetons = User::where('role', UserRole::Partner)
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->whereHas('partnerAccount', fn ($q) => $q->where('status', 'approved'))
            ->pluck('fcm_token')->all();

        $this->fcm->sendToTokens($jetons, $titre, $corps, [
            'type'    => 'need_broadcast',
            'need_id' => (string) $need->id,
        ]);
    }

    /**
     * La demande telle qu'un partenaire a le droit de la lire.
     *
     * Liste BLANCHE, et non `Arr::except()` sur le format complet : le jour ou
     * un champ s'ajoutera au besoin — une adresse, une piece jointe — il
     * n'entrera pas ici tout seul. Une liste noire laisse passer ce qu'on a
     * oublie d'y mettre, et ce qu'on oublie est precisement ce qui vient
     * d'arriver.
     *
     * @return array<string, mixed>
     */
    private function pourPartenaire(CustomerNeed $n): array
    {
        return [
            'id'                     => $n->id,
            'type'                   => $n->type,
            'description'            => $n->description,
            'budget_max'             => $n->budget_max ? (float) $n->budget_max : null,
            'currency'               => $n->currency,
            'brand_name'             => $n->brand?->name,
            'vehicle_model_name'     => $n->vehicleModel?->name,
            'vehicle_type'           => $n->vehicle_type,
            'body_style'             => $n->body_style,
            'year_min'               => $n->year_min,
            'year_max'               => $n->year_max,
            'part_category_name'     => $n->partCategory?->name,
            'oem_number'             => $n->oem_number,
            'accessory_category'     => $n->accessory_category,
            'need_manufacturer_name' => $n->needManufacturer?->name,
            'created_at'             => $n->created_at?->toIso8601String(),
        ];
    }

    private function format(CustomerNeed $n): array
    {
        return [
            'id'            => $n->id,
            'type'          => $n->type,
            'description'   => $n->description,
            'budget_max'    => $n->budget_max ? (float) $n->budget_max : null,
            'currency'      => $n->currency,
            // Critères véhicule
            'brand_id'           => $n->brand_id,
            'brand_name'         => $n->brand?->name,
            'vehicle_model_id'   => $n->vehicle_model_id,
            'vehicle_model_name' => $n->vehicleModel?->name,
            'vehicle_type'       => $n->vehicle_type,
            'body_style'         => $n->body_style,
            'year_min'           => $n->year_min,
            'year_max'           => $n->year_max,
            // Critères pièce détachée
            'part_category_id'   => $n->part_category_id,
            'part_category_name' => $n->partCategory?->name,
            'oem_number'         => $n->oem_number,
            // Critères accessoire
            'accessory_category' => $n->accessory_category,
            // Commun pièce + accessoire
            'need_manufacturer_id'   => $n->need_manufacturer_id,
            'need_manufacturer_name' => $n->needManufacturer?->name,
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
