<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    /** Entree de stock. */
    public function increase(Part $part, int $quantity, ?string $reason = null, ?Model $source = null): StockMovement
    {
        return $this->move($part, StockMovementType::In, abs($quantity), $reason, $source);
    }

    /** Sortie de stock avec controle de disponibilite. */
    public function decrease(Part $part, int $quantity, ?string $reason = null, ?Model $source = null): StockMovement
    {
        $quantity = abs($quantity);

        if ($part->stock_quantity < $quantity) {
            throw new RuntimeException(
                "Stock insuffisant pour la piece {$part->sku} : {$part->stock_quantity} disponible(s), {$quantity} demande(s)."
            );
        }

        return $this->move($part, StockMovementType::Out, -$quantity, $reason, $source);
    }

    /** Correction d'inventaire : on fixe la quantite cible. */
    public function adjust(Part $part, int $newQuantity, ?string $reason = null): StockMovement
    {
        $delta = $newQuantity - $part->stock_quantity;

        return $this->move($part, StockMovementType::Adjustment, $delta, $reason ?? 'Inventaire');
    }

    protected function move(
        Part $part,
        StockMovementType $type,
        int $signedQuantity,
        ?string $reason = null,
        ?Model $source = null
    ): StockMovement {
        return DB::transaction(function () use ($part, $type, $signedQuantity, $reason, $source) {
            $part->refresh();
            $part->stock_quantity += $signedQuantity;
            $part->save();

            return StockMovement::create([
                'part_id'     => $part->id,
                'type'        => $type,
                'quantity'    => $signedQuantity,
                'stock_after' => $part->stock_quantity,
                'source_type' => $source?->getMorphClass(),
                'source_id'   => $source?->getKey(),
                'reason'      => $reason,
                'user_id'     => auth()->id(),
            ]);
        });
    }
}
