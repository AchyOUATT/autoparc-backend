<?php

namespace App\Http\Controllers\Api;

use App\Enums\FitmentSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccessoryRequest;
use App\Http\Resources\AccessoryResource;
use App\Models\Accessory;
use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\CustomerNeed;
use App\Services\FcmService;
use App\Support\TriPublic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessoryController extends Controller
{
    public function __construct(protected FcmService $fcm) {}

    /* ------------------------------------------------------------------ */
    /* Catalogue public (lecture seule, actifs en stock)                   */
    /* ------------------------------------------------------------------ */

    /** GET /api/catalog/accessories */
    public function catalog(Request $request)
    {
        $accessories = Accessory::query()
            ->with([
                'manufacturer', 'location', 'media',
                // La ligne de compatibilite des cartes lit la cle
                // `compatibility`, que AccessoryResource rend sous
                // whenLoaded : sans ce
                // chargement la cle DISPARAIT du JSON et la ligne reste vide
                // sur chaque carte, sans erreur. Ce depot a deja livre cinq
                // fois ce defaut-la.
                'fitments.vehicleModel.brand',
            ])
            ->active()
            ->where('is_available', true)
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('category'), fn ($q) => $q->ofCategory($request->category))
            ->when($request->filled('manufacturer_id'), fn ($q) => $q->where('manufacturer_id', $request->manufacturer_id))
            ->when($request->filled('price_max'), fn ($q) => $q->where('selling_price', '<=', $request->price_max))
            ->when($request->filled('vehicle_model_id'), fn ($q) => $q->compatibleWithModel($request->integer('vehicle_model_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->tap(fn ($q) => TriPublic::appliquer($q, $request, TriPublic::articles(), 'name', 'asc'))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return AccessoryResource::collection($accessories);
    }

    /** GET /api/catalog/accessories/{accessory} */
    public function catalogShow(Accessory $accessory): AccessoryResource
    {
        abort_if(! $accessory->is_active, 404);

        // 'media' etait absent de cette liste, et de celle de show() plus
        // bas, alors que catalog() le charge. whenLoaded() retire alors la
        // cle au lieu de rendre un tableau vide : la fiche d'un accessoire
        // n'avait aucune photo a afficher, pendant que la liste en montrait.
        // PartController::show le chargeait depuis le debut — c'est cette
        // asymetrie, et elle seule, qui faisait la difference entre les deux
        // catalogues.
        return new AccessoryResource($accessory->load(['manufacturer', 'location', 'media', 'fitments.vehicleModel', 'fitments.trim']));
    }

    /* ------------------------------------------------------------------ */
    /* Back-office (staff, toutes les entrées)                             */
    /* ------------------------------------------------------------------ */

    /** GET /api/accessories */
    public function index(Request $request)
    {
        $accessories = Accessory::query()
            ->with(['manufacturer', 'location', 'fitments.vehicleModel.brand'])
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('category'), fn ($q) => $q->ofCategory($request->category))
            ->when($request->filled('manufacturer_id'), fn ($q) => $q->where('manufacturer_id', $request->manufacturer_id))
            ->when($request->boolean('in_stock'), fn ($q) => $q->inStock())
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->filled('price_max'), fn ($q) => $q->where('selling_price', '<=', $request->price_max))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->tap(fn ($q) => TriPublic::appliquer($q, $request, TriPublic::articles(), 'name', 'asc'))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return AccessoryResource::collection($accessories);
    }

    /** POST /api/accessories */
    public function store(StoreAccessoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $accessory = DB::transaction(function () use ($data) {
            $accessory = Accessory::create(collect($data)->except('fitments')->all());

            foreach ($data['fitments'] ?? [] as $fitment) {
                $accessory->fitments()->create($this->declaree($fitment));
            }

            return $accessory;
        });

        // refresh() avant load() : `create()` rend l'instance telle qu'on l'a
        // construite, pas la ligne ecrite. Un champ absent de la requete y
        // reste null alors que la base lui a applique son defaut — et c'est
        // cette instance que la ressource serialise.
        //
        // Concretement, `stock_quantity` porte `->default(0)` depuis la
        // migration 000600. Omis par le formulaire, il valait 0 en base et
        // null dans la reponse de creation. L'application lisait
        // `quantity as int`, levait un _TypeError, et affichait « Une erreur
        // est survenue » sur un enregistrement pourtant reussi — puis
        // l'utilisateur reessayait. Quatre accessoires identiques ont ete
        // crees ainsi le 2026-10-16.
        //
        // Le client a ete rendu tolerant de son cote, mais c'est ici que le
        // defaut se corrige : une reponse de creation doit decrire ce qui a
        // ete ecrit, pas ce qu'on a demande d'ecrire.
        $accessory->refresh()->load(['manufacturer', 'fitments']);

        $this->notifyAccessoryNeedMatch($accessory);

        return (new AccessoryResource($accessory))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Notifie les clients qui ont un besoin de type 'accessory' correspondant
     * à la catégorie et/ou au fabricant de l'accessoire nouvellement créé.
     */
    protected function notifyAccessoryNeedMatch(Accessory $accessory): void
    {
        $categoryValue = $accessory->category?->value;

        $needs = CustomerNeed::query()
            ->where('type', 'accessory')
            ->whereIn('status', ['pending', 'contacted'])
            ->whereNotNull('firebase_uid')
            ->where(function ($q) use ($accessory, $categoryValue) {
                $q->where(function ($sub) use ($accessory, $categoryValue) {
                    if ($categoryValue) {
                        $sub->orWhere('accessory_category', $categoryValue);
                    }
                    if ($accessory->manufacturer_id) {
                        $sub->orWhere('need_manufacturer_id', $accessory->manufacturer_id);
                    }
                });
            })
            ->when($accessory->selling_price, fn ($q) =>
                $q->where(function ($sub) use ($accessory) {
                    $sub->whereNull('budget_max')
                        ->orWhere('budget_max', '>=', $accessory->selling_price);
                })
            )
            ->get();

        if ($needs->isEmpty()) {
            return;
        }

        $categoryLabel = $accessory->category?->label() ?? 'accessoire';
        $title         = 'Accessoire disponible !';
        $body          = "Un accessoire {$categoryLabel} vient d'être ajouté : {$accessory->name}";
        $payload       = [
            'type'         => 'accessory_match',
            'accessory_id' => (string) $accessory->id,
        ];

        foreach ($needs as $need) {
            AppNotification::notifyClient($need->firebase_uid, 'accessory_match', $title, $body, $payload);
            $tokens = ClientFcmToken::tokensForUid($need->firebase_uid);
            $this->fcm->sendToTokens($tokens, $title, $body, $payload);
        }
    }

    /** GET /api/accessories/{accessory} */
    public function show(Accessory $accessory): AccessoryResource
    {
        return new AccessoryResource($accessory->load(['manufacturer', 'location', 'media', 'fitments.vehicleModel', 'fitments.trim']));
    }

    /** PUT /api/accessories/{accessory} */
    public function update(StoreAccessoryRequest $request, Accessory $accessory): AccessoryResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($accessory, $data) {
            $accessory->update(collect($data)->except('fitments')->all());

            // Remplacement complet des fitments si fournis. L'ecran d'edition
            // affiche la liste entiere : ce qui y reste a l'enregistrement
            // devient une declaration, y compris les lignes fabriquees.
            if (array_key_exists('fitments', $data)) {
                $accessory->fitments()->delete();
                foreach ($data['fitments'] as $fitment) {
                    $accessory->fitments()->create($this->declaree($fitment));
                }
            }
        });

        return new AccessoryResource($accessory->refresh()->load(['manufacturer', 'fitments']));
    }

    /**
     * Marque une ligne de compatibilite comme saisie par un humain.
     *
     * Voir PartController::declaree() : l'origine est imposee apres les
     * donnees recues pour qu'aucune requete ne puisse faire passer sa saisie
     * pour une fabrication, et la rendre effacable par le recalcul.
     *
     * @param  array<string, mixed>  $fitment
     * @return array<string, mixed>
     */
    private function declaree(array $fitment): array
    {
        return array_merge($fitment, ['source' => FitmentSource::Declared]);
    }

    /** DELETE /api/accessories/{accessory} — soft delete */
    public function destroy(Accessory $accessory): JsonResponse
    {
        $accessory->delete();

        return response()->json(['message' => 'Accessoire archive.']);
    }

    /** Bascule la disponibilité (disponible ↔ indisponible). */
    public function toggleAvailability(Accessory $accessory): JsonResponse
    {
        $accessory->update(['is_available' => ! $accessory->is_available]);

        return response()->json([
            'id'           => $accessory->id,
            'is_available' => $accessory->is_available,
        ]);
    }

    /**
     * POST /api/accessories/{accessory}/stock
     *
     * Déplace le stock sans passer par StockService (table stock_movements
     * ne supporte que les pièces détachées pour l'instant).
     *
     * Body : { "operation": "increase|decrease|set", "quantity": 5, "reason": "..." }
     */
    public function adjustStock(Request $request, Accessory $accessory): AccessoryResource
    {
        $data = $request->validate([
            'operation' => ['required', 'in:increase,decrease,set'],
            'quantity'  => ['required', 'integer', 'min:0'],
            'reason'    => ['nullable', 'string', 'max:180'],
        ]);

        DB::transaction(function () use ($accessory, $data) {
            $accessory->refresh();

            if ($data['operation'] === 'decrease' && $accessory->stock_quantity < $data['quantity']) {
                abort(422, "Stock insuffisant pour {$accessory->display_reference} : {$accessory->stock_quantity} disponible(s), {$data['quantity']} demande(s).");
            }

            $newQty = match ($data['operation']) {
                'increase' => $accessory->stock_quantity + $data['quantity'],
                'decrease' => $accessory->stock_quantity - $data['quantity'],
                'set'      => $data['quantity'],
            };

            $accessory->update(['stock_quantity' => $newQty]);
        });

        return new AccessoryResource($accessory->refresh());
    }
}
