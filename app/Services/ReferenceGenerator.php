<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Genere des references sequentielles lisibles : VEH-2026-000123, LOC-2026-000045... */
class ReferenceGenerator
{
    public function next(string $prefix, ?string $table = null, string $column = 'reference'): string
    {
        $year = now()->format('Y');
        $base = "{$prefix}-{$year}-";

        $table ??= match ($prefix) {
            'VEH' => 'vehicles',
            'LOC' => 'rentals',
            'VTE' => 'sales',
            'CMD' => 'part_orders',
            'CLI' => 'customers',
            'PAY' => 'payments',
            default => 'vehicles',
        };

        $column = $prefix === 'CLI' ? 'code' : $column;

        $last = DB::table($table)
            ->where($column, 'like', $base.'%')
            ->orderByDesc($column)
            ->value($column);

        $sequence = $last ? ((int) substr($last, strlen($base))) + 1 : 1;

        return $base.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
