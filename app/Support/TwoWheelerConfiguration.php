<?php

namespace App\Support;

/**
 * Configuration declaree pour un deux-roues, distincte de `vehicles.body_style`.
 *
 * On n'etend PAS `body_style` : une valeur inconnue y serait affichee brute,
 * voire ferait echouer le menu d'edition des applications deja installees
 * (v131 / build 132). Les anciennes apps ignorent simplement ces cles JSON
 * et lisent `vehicle_type` comme une chaine ; `two_wheeler` n'est pas
 * reclasse en voiture particuliere.
 */
final class TwoWheelerConfiguration
{
    /** Types de caisse du socle : pas de tricycle (reporte). */
    public const BODY_TYPES = ['motorcycle', 'scooter', 'moped', 'other'];

    public const STROKES = ['two_stroke', 'four_stroke'];

    public const COOLINGS = ['air', 'liquid'];

    public const STARTERS = ['kick', 'electric', 'both'];

    public const DRIVES = ['chain', 'belt', 'shaft'];

    public const TRANSMISSIONS = ['manual', 'semi_automatic', 'cvt'];

    public const KEYS = [
        'body_type', 'stroke', 'cooling', 'starter', 'drive', 'transmission',
    ];

    public const VIN_MIN = 5;

    public const VIN_MAX = 32;

    public static function normalize(?array $configuration): ?array
    {
        $normalized = [];

        foreach (self::KEYS as $key) {
            $value = $configuration[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                continue;
            }
            $normalized[$key] = $value;
        }

        return $normalized === [] ? null : $normalized;
    }

    /** A supplied null clears the object; missing nested keys are preserved. */
    public static function merge(?array $existing, ?array $changes): ?array
    {
        return $changes === null ? null : self::normalize(array_replace($existing ?? [], $changes));
    }

    public static function isTwoWheeler(?string $vehicleType): bool
    {
        return $vehicleType === 'two_wheeler';
    }
}
