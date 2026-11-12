<?php

namespace App\Support;

/** Owner-declared configuration, independent of any manufacturer reference. */
final class HeavyVehicleConfiguration
{
    /** Carrosseries poids lourd, distinctes de `vehicles.body_style`. */
    public const BODY_TYPES = [
        'tanker', 'tipper', 'tractor_unit', 'flatbed', 'box', 'mixer', 'other',
    ];

    public const USEFUL_VOLUME_MIN = 0.01;
    public const USEFUL_VOLUME_MAX = 500.0;
    public const TANK_CAPACITY_MIN = 1;
    public const TANK_CAPACITY_MAX = 200000;
    public const PAYLOAD_MIN = 1;
    public const PAYLOAD_MAX = 1000000;

    public const KEYS = [
        'version_label', 'cab_label', 'axle_configuration', 'chassis_type',
        'body_type', 'body_use', 'emission_standard', 'ptac_kg', 'ptra_kg',
        'useful_volume_m3', 'tank_capacity_liters', 'payload_kg',
    ];

    private const INTEGER_KEYS = ['ptac_kg', 'ptra_kg', 'tank_capacity_liters', 'payload_kg'];

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
            if (in_array($key, self::INTEGER_KEYS, true)) {
                $normalized[$key] = (int) $value;
            } elseif ($key === 'useful_volume_m3') {
                $normalized[$key] = round((float) $value, 2);
            } else {
                $normalized[$key] = $value;
            }
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
