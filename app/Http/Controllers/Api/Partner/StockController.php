<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Part;
use App\Services\StockService;
use App\Support\PartnerOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function __construct(private StockService $stock) {}

    public function adjustPart(Request $request, Part $part): JsonResponse
    {
        PartnerOwnership::authorize($part, $request->user());
        $data = $this->validateAdjustment($request);

        return DB::transaction(function () use ($request, $part, $data) {
            $part = Part::whereKey($part->getKey())->lockForUpdate()->firstOrFail();
            PartnerOwnership::authorize($part, $request->user());
            $this->targetQuantity($part, $data);

            // Retain the existing stock ledger and authenticated author. The
            // outer row lock protects both its availability check and movement.
            match ($data['operation']) {
                'increase' => $this->stock->increase($part, $data['quantity'], $data['reason'] ?? null),
                'decrease' => $this->stock->decrease($part, $data['quantity'], $data['reason'] ?? null),
                'set' => $this->stock->adjust($part, $data['quantity'], $data['reason'] ?? null),
            };

            return $this->response($part->refresh());
        });
    }

    public function adjustAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        PartnerOwnership::authorize($accessory, $request->user());
        $data = $this->validateAdjustment($request);

        return DB::transaction(function () use ($request, $accessory, $data) {
            $accessory = Accessory::whereKey($accessory->getKey())->lockForUpdate()->firstOrFail();
            PartnerOwnership::authorize($accessory, $request->user());
            $accessory->update(['stock_quantity' => $this->targetQuantity($accessory, $data)]);

            // stock_movements currently references parts only, just as on the
            // existing staff accessory endpoint. Do not fabricate a part ID.
            return $this->response($accessory->refresh());
        });
    }

    public function togglePartAvailability(Request $request, Part $part): JsonResponse
    {
        return $this->toggle($request, $part);
    }

    public function toggleAccessoryAvailability(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->toggle($request, $accessory);
    }

    private function toggle(Request $request, Model $product): JsonResponse
    {
        PartnerOwnership::authorize($product, $request->user());

        return DB::transaction(function () use ($request, $product) {
            $product = $product->newQuery()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            PartnerOwnership::authorize($product, $request->user());
            $product->update(['is_available' => ! $product->is_available]);

            return $this->response($product);
        });
    }

    private function validateAdjustment(Request $request): array
    {
        return $request->validate([
            'operation' => ['required', 'in:increase,decrease,set'],
            'quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'reason' => ['nullable', 'string', 'max:180'],
        ]);
    }

    private function targetQuantity(Model $product, array $data): int
    {
        $quantity = match ($data['operation']) {
            'increase' => $product->stock_quantity + $data['quantity'],
            'decrease' => $product->stock_quantity - $data['quantity'],
            'set' => $data['quantity'],
        };

        if ($quantity < 0 || $quantity > 2147483647) {
            throw ValidationException::withMessages([
                'quantity' => $quantity < 0
                    ? 'Stock insuffisant pour cette sortie.'
                    : 'La quantité de stock est trop élevée.',
            ]);
        }

        return $quantity;
    }

    private function response(Model $product): JsonResponse
    {
        return response()->json([
            'id' => $product->getKey(),
            'stock_quantity' => $product->stock_quantity,
            'is_available' => $product->is_available,
        ]);
    }
}
