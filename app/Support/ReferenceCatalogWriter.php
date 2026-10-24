<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Writes a reference without invalidating every client cache on each seed. */
class ReferenceCatalogWriter
{
    public static function upsert(string $table, array $identity, array $attributes): int
    {
        return DB::transaction(function () use ($table, $identity, $attributes) {
            $query = DB::table($table)->where($identity);
            $existing = (clone $query)->lockForUpdate()->first();

            if ($existing === null) {
                return (int) DB::table($table)->insertGetId(array_merge(
                    $identity,
                    $attributes,
                    ['created_at' => now(), 'updated_at' => now()],
                ));
            }

            $changes = [];

            foreach ($attributes as $column => $value) {
                if (! self::equivalent($existing->{$column}, $value)) {
                    $changes[$column] = $value;
                }
            }

            // Query Builder upserts historically left these dates NULL. Repair
            // them on replay so incremental sync can discover the references.
            if ($changes !== [] || $existing->updated_at === null) {
                $query->update(array_merge($changes, ['updated_at' => now()]));
            }

            return (int) $existing->id;
        });
    }

    private static function equivalent(mixed $stored, mixed $expected): bool
    {
        if ($stored === null || $expected === null) {
            return $stored === $expected;
        }

        // PDO drivers differ in how they return integer and boolean columns.
        if (is_bool($expected)) {
            return in_array($stored, [true, 1, '1', 't', 'true'], true) === $expected;
        }

        return (string) $stored === (string) $expected;
    }
}
