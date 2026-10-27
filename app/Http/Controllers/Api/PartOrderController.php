<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartOrderRequest;
use App\Http\Resources\PartOrderResource;
use App\Models\AppNotification;
use App\Support\PrixEffectif;
use App\Models\Part;
use App\Models\PartOrder;
use App\Models\User;
use App\Services\FcmService;
use App\Services\ReferenceGenerator;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartOrderController extends Controller
{
    public function __construct(
        protected ReferenceGenerator $references,
        protected StockService       $stock,
        protected FcmService         $fcm,
    ) {}

    public function index(Request $request)
    {
        $orders = PartOrder::query()
            ->with(['customer', 'items.part'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->latest('ordered_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return PartOrderResource::collection($orders);
    }

    public function store(StorePartOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data) {
            $order = PartOrder::create(array_merge(
                collect($data)->except('items')->all(),
                [
                    'reference'  => $this->references->next('CMD'),
                    'status'     => OrderStatus::Draft,
                    'created_by' => auth()->id(),

                    // ── POURQUOI CETTE LIGNE EXISTE ────────────────────────
                    //
                    // La colonne a un DEFAUT en base, et un defaut SQL ne
                    // remonte pas dans le modele en memoire : `create()` ne
                    // relit pas la ligne. `payment_status` restait donc nul
                    // sur l'objet rendu, et `PartOrderResource` fait
                    // `$this->payment_status->value` — soit 500 sur CHAQUE
                    // creation de commande, alors meme que la commande etait
                    // bien enregistree. Le client concluait a un echec et
                    // pouvait recommencer.
                    //
                    // Meme famille que le defaut de `UserFactory` sur
                    // `is_active`, qui avait rendu 119 bancs rouges.
                    'payment_status' => PaymentStatus::Pending,
                ]
            ));

            $subtotal = 0;
            $tax = 0;

            foreach ($data['items'] as $line) {
                $part = Part::with('primaryOemNumber')->findOrFail($line['part_id']);
                // ── LE PRIX PAR DEFAUT EST LE PRIX DU JOUR ──────────────────
                //
                // La colonne porte le prix NORMAL : pendant une promotion,
                // elle ne dit plus ce que l'acheteur doit payer. La laisser
                // ici facturerait le plein tarif sur une piece dont la
                // carte annonce une remise — c'est-a-dire un ecart d'argent
                // entre ce qui est montre et ce qui est encaisse.
                //
                // `unit_price` envoye prime toujours : le personnel fixe un
                // prix de ligne quand il le veut, et c'est son metier.
                $unitPrice = $line['unit_price']
                    ?? PrixEffectif::de($part, 'selling_price')
                    ?? 0.0;
                $discount  = $line['discount'] ?? 0;
                $lineTotal = $unitPrice * $line['quantity'] - $discount;

                $order->items()->create([
                    'part_id'       => $part->id,
                    'designation'   => $part->name,
                    'oem_reference' => $part->primaryOemNumber->first()?->number,
                    'quantity'      => $line['quantity'],
                    'unit_price'    => $unitPrice,
                    'discount'      => $discount,
                    'line_total'    => $lineTotal,
                ]);

                $subtotal += $lineTotal;
                $tax += $lineTotal * (float) $part->vat_rate / 100;
            }

            $order->update([
                'subtotal'     => $subtotal,
                'tax_amount'   => round($tax, 2),
                'total_amount' => round($subtotal - (float) $order->discount + $tax, 2),
            ]);

            return $order;
        });

        // ── Notifie tout le staff d'une nouvelle commande ────────────
        $title       = 'Nouvelle commande de pièces';
        $body        = "Réf. {$order->reference} — {$order->items->count()} article(s)";
        $notifData   = ['type' => 'new_order', 'order_type' => 'part', 'order_id' => (string) $order->id];

        AppNotification::notifyAllStaff('new_order', $title, $body, $notifData);
        $tokens = User::whereNotNull('fcm_token')->pluck('fcm_token')->all();
        $this->fcm->sendToTokens($tokens, $title, $body, $notifData);

        return (new PartOrderResource($order->load(['customer', 'items.part'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PartOrder $partOrder): PartOrderResource
    {
        return new PartOrderResource($partOrder->load(['customer', 'vehicle', 'items.part', 'payments']));
    }

    /** Confirmation : la sortie de stock est effectuee ici. */
    public function confirm(PartOrder $partOrder): JsonResponse
    {
        if ($partOrder->status !== OrderStatus::Draft) {
            return response()->json(['message' => 'Seule une commande en brouillon peut etre confirmee.'], 422);
        }

        DB::transaction(function () use ($partOrder) {
            foreach ($partOrder->items as $item) {
                $this->stock->decrease(
                    $item->part,
                    $item->quantity,
                    "Commande {$partOrder->reference}",
                    $partOrder
                );
            }

            $partOrder->update(['status' => OrderStatus::Confirmed]);
        });

        return response()->json([
            'message' => 'Commande confirmee et stock mis a jour.',
            'data'    => new PartOrderResource($partOrder->refresh()->load('items.part')),
        ]);
    }

    /** Annulation : reintegration du stock si la commande etait confirmee. */
    public function cancel(PartOrder $partOrder): JsonResponse
    {
        DB::transaction(function () use ($partOrder) {
            if (in_array($partOrder->status, [OrderStatus::Confirmed, OrderStatus::Prepared], true)) {
                foreach ($partOrder->items as $item) {
                    $this->stock->increase(
                        $item->part,
                        $item->quantity,
                        "Annulation commande {$partOrder->reference}",
                        $partOrder
                    );
                }
            }

            $partOrder->update(['status' => OrderStatus::Cancelled]);
        });

        return response()->json(['message' => 'Commande annulee.']);
    }
}
