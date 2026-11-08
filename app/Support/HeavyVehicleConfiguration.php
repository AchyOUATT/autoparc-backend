<?php

namespace App\Support;

/** Owner-declared configuration, independent of any manufacturer reference. */
final class HeavyVehicleConfiguration
{
    public const KEYS = [
        'version_label', 'cab_label', 'axle_configuration', 'chassis_type',
        'body_use', 'emission_standard', 'ptac_kg', 'ptra_kg',
    ];

    public static function normalize(?array $configuration): ?array
    {
        $normalized = [];

        // Stable order also makes inspection fingerprints independent of JSON
        // object ordering in the database (notably PostgreSQL jsonb).
        foreach (self::KEYS as $key) {
            $value = $configuration[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                continue;
            }
            $normalized[$key] = in_array($key, ['ptac_kg', 'ptra_kg'], true) ? (int) $value : $value;
        }

        return $normalized === [] ? null : $normalized;
    }

    /** A supplied null clears the object; missing nested keys are preserved. */
    public static function merge(?array $existing, ?array $changes): ?array
    {
        return $changes === null ? null : self::normalize(array_replace($existing ?? [], $changes));
    }

    public static function massesAreConsistent(?array $configuration): bool
    {
        return ! isset($configuration['ptac_kg'], $configuration['ptra_kg'])
            || $configuration['ptra_kg'] >= $configuration['ptac_kg'];
    }
}
