<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccessoryOrderRequest;
use App\Http\Resources\AccessoryOrderResource;
use App\Models\Accessory;
use App\Models\AccessoryOrder;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\FcmService;
use App\Services\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessoryOrderController extends Controller
{
    public function __construct(
        protected ReferenceGenerator $references,
        protected FcmService         $fcm,
    ) {}

    /** GET /api/accessory-orders */
    public function index(Request $request)
    {
        $orders = AccessoryOrder::query()
            ->with(['customer', 'items.accessory'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->latest('ordered_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return AccessoryOrderResource::collection($orders);
    }

    /** POST /api/accessory-orders */
    public function store(StoreAccessoryOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data) {
            $order = AccessoryOrder::create(array_merge(
                collect($data)->except('items')->all(),
                [
                    'reference'  => $this->references->next('ACC', 'accessory_orders'),
                    'status'     => OrderStatus::Draft,
                    'created_by' => auth()->id(),
                    'ordered_at' => $data['ordered_at'] ?? now()->toDateString(),
                    'currency'   => 'XOF',
                ]
            ));

            $subtotal = 0;
            $tax      = 0;

            foreach ($data['items'] as $line) {
                $accessory = Accessory::findOrFail($line['accessory_id']);
                $unitPrice = $line['unit_price'] ?? (float) $accessory->selling_price;
                $discount  = $line['discount'] ?? 0;
                $lineTotal = $unitPrice * $line['quantity'] - $discount;

                $order->items()->create([
                    'accessory_id' => $accessory->id,
                    'designation'  => $accessory->name,
                    'quantity'     => $line['quantity'],
                    'unit_price'   => $unitPrice,
                    'discount'     => $discount,
                    'line_total'   => $lineTotal,
                ]);

                $subtotal += $lineTotal;
                $tax      += $lineTotal * (float) $accessory->vat_rate / 100;
            }

            $order->update([
                'subtotal'     => $subtotal,
                'tax_amount'   => round($tax, 2),
                'total_amount' => round($subtotal - (float) ($data['discount'] ?? 0) + $tax, 2),
            ]);

            return $order;
        });

        // ── Notifie tout le staff ─────────────────────────────────────
        $title     = 'Nouvelle commande d\'accessoires';
        $body      = "Réf. {$order->reference} — {$order->items->count()} article(s)";
        $notifData = ['type' => 'new_order', 'order_type' => 'accessory', 'order_id' => (string) $order->id];

        AppNotification::notifyAllStaff('new_order', $title, $body, $notifData);
        $tokens = User::whereNotNull('fcm_token')->pluck('fcm_token')->all();
        $this->fcm->sendToTokens($tokens, $title, $body, $notifData);

        return (new AccessoryOrderResource($order->load(['customer', 'items.accessory'])))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/accessory-orders/{accessoryOrder} */
    public function show(AccessoryOrder $accessoryOrder): AccessoryOrderResource
    {
        return new AccessoryOrderResource(
            $accessoryOrder->load(['customer', 'vehicle', 'items.accessory', 'payments'])
        );
    }

    /**
     * POST /api/accessory-orders/{accessoryOrder}/confirm
     *
     * Confirme la commande et décrémente le stock de chaque accessoire.
     */
    public function confirm(AccessoryOrder $accessoryOrder): JsonResponse
    {
        if ($accessoryOrder->status !== OrderStatus::Draft) {
            return response()->json(['message' => 'Seule une commande en brouillon peut etre confirmee.'], 422);
        }

        DB::transaction(function () use ($accessoryOrder) {
            foreach ($accessoryOrder->items as $item) {
                $accessory = $item->accessory;
                if ($accessory->stock_quantity < $item->quantity) {
                    abort(
                        422,
                        "Stock insuffisant pour {$accessory->sku} : {$accessory->stock_quantity} disponible(s), {$item->quantity} demande(s)."
                    );
                }
                $accessory->decrement('stock_quantity', $item->quantity);
            }

            $accessoryOrder->update(['status' => OrderStatus::Confirmed]);
        });

        return response()->json([
            'message' => 'Commande confirmee et stock mis a jour.',
            'data'    => new AccessoryOrderResource($accessoryOrder->refresh()->load('items.accessory')),
        ]);
    }

    /**
     * POST /api/accessory-orders/{accessoryOrder}/cancel
     *
     * Annule la commande et réintègre le stock si elle était déjà confirmée.
     */
    public function cancel(AccessoryOrder $accessoryOrder): JsonResponse
    {
        if ($accessoryOrder->status === OrderStatus::Cancelled) {
            return response()->json(['message' => 'Commande deja annulee.'], 422);
        }

        DB::transaction(function () use ($accessoryOrder) {
            // Réintégration du stock uniquement si la commande avait déjà sorti du stock
            if (in_array($accessoryOrder->status, [OrderStatus::Confirmed, OrderStatus::Prepared], true)) {
                foreach ($accessoryOrder->items as $item) {
                    $item->accessory->increment('stock_quantity', $item->quantity);
                }
            }

            $accessoryOrder->update(['status' => OrderStatus::Cancelled]);
        });

        return response()->json(['message' => 'Commande annulee.']);
    }
}
