<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccessoryRequest;
use App\Http\Resources\AccessoryResource;
use App\Models\Accessory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessoryController extends Controller
{
    /* ------------------------------------------------------------------ */
    /* Catalogue public (lecture seule, actifs en stock)                   */
    /* ------------------------------------------------------------------ */

    /** GET /api/catalog/accessories */
    public function catalog(Request $request)
    {
        $accessories = Accessory::query()
            ->with(['manufacturer', 'location'])
            ->active()
            ->where('is_available', true)
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('category'), fn ($q) => $q->ofCategory($request->category))
            ->when($request->filled('manufacturer_id'), fn ($q) => $q->where('manufacturer_id', $request->manufacturer_id))
            ->when($request->filled('price_max'), fn ($q) => $q->where('selling_price', '<=', $request->price_max))
            ->when($request->filled('vehicle_model_id'), fn ($q) => $q->compatibleWithModel($request->integer('vehicle_model_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->orderBy($request->input('sort_by', 'name'), $request->input('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return AccessoryResource::collection($accessories);
    }

    /** GET /api/catalog/accessories/{accessory} */
    public function catalogShow(Accessory $accessory): AccessoryResource
    {
        abort_if(! $accessory->is_active, 404);

        return new AccessoryResource($accessory->load(['manufacturer', 'location', 'fitments.vehicleModel', 'fitments.trim']));
    }

    /* ------------------------------------------------------------------ */
    /* Back-office (staff, toutes les entrées)                             */
    /* ------------------------------------------------------------------ */

    /** GET /api/accessories */
    public function index(Request $request)
    {
        $accessories = Accessory::query()
            ->with(['manufacturer', 'location'])
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('category'), fn ($q) => $q->ofCategory($request->category))
            ->when($request->filled('manufacturer_id'), fn ($q) => $q->where('manufacturer_id', $request->manufacturer_id))
            ->when($request->boolean('in_stock'), fn ($q) => $q->inStock())
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->filled('price_max'), fn ($q) => $q->where('selling_price', '<=', $request->price_max))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->orderBy($request->input('sort_by', 'name'), $request->input('sort_dir', 'asc'))
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
                $accessory->fitments()->create($fitment);
            }

            return $accessory;
        });

        return (new AccessoryResource($accessory->load(['manufacturer', 'fitments'])))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/accessories/{accessory} */
    public function show(Accessory $accessory): AccessoryResource
    {
        return new AccessoryResource($accessory->load(['manufacturer', 'fitments.vehicleModel', 'fitments.trim']));
    }

    /** PUT /api/accessories/{accessory} */
    public function update(StoreAccessoryRequest $request, Accessory $accessory): AccessoryResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($accessory, $data) {
            $accessory->update(collect($data)->except('fitments')->all());

            // Remplacement complet des fitments si fournis
            if (array_key_exists('fitments', $data)) {
                $accessory->fitments()->delete();
                foreach ($data['fitments'] as $fitment) {
                    $accessory->fitments()->create($fitment);
                }
            }
        });

        return new AccessoryResource($accessory->refresh()->load(['manufacturer', 'fitments']));
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
                abort(422, "Stock insuffisant pour {$accessory->sku} : {$accessory->stock_quantity} disponible(s), {$data['quantity']} demande(s).");
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
