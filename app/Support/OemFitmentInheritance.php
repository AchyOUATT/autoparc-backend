<?php

namespace App\Support;

use App\Models\OemNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quelles références OEM d'une pièce lui transmettent leurs compatibilités.
 *
 * Une pièce peut porter d'anciens numéros (remplacés) pour qu'on la
 * retrouve par recherche. Ces numéros ne lui transmettent JAMAIS leurs
 * compatibilités : qu'une référence en remplace une autre ne prouve pas
 * qu'elle se monte sur les mêmes véhicules. Chaque compatibilité de la
 * référence actuelle doit être justifiée pour elle-même.
 *
 * Seule exception : le numéro PRINCIPAL de la pièce (`is_primary`). Une
 * pièce ancienne en stock, vendue sous son propre numéro remplacé, garde
 * les compatibilités de ce numéro.
 */
final class OemFitmentInheritance
{
    public static function passes(OemNumber $oem): bool
    {
        if ($oem->pivot !== null && (bool) ($oem->pivot->is_primary ?? false)) {
            return true;
        }

        return ! $oem->isReplaced();
    }

    /** Même règle, pour un `whereHas('oemNumbers', …)`. */
    public static function constrain(Builder $oemNumbers): Builder
    {
        return $oemNumbers->where(fn (Builder $q) => $q->where('oem_number_part.is_primary', true)
            ->orWhere(fn (Builder $r) => $r->where('oem_numbers.is_superseded', false)
                ->whereNull('oem_numbers.superseded_by_id')
                ->whereDoesntHave('replacementRelations')));
    }
}
