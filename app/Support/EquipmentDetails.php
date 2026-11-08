<?php

namespace App\Support;

use App\Models\Vehicle;
use App\Models\VehicleModel;

/** Owner-declared measurements, independent of a manufacturer's catalogue. */
final class EquipmentDetails
{
    public const KEYS = [
        'brand_label', 'model_label', 'serial_number', 'registration_number',
        'park_number', 'operating_hours', 'hour_reading_date', 'odometer_km',
        'power_kw', 'transmission', 'rated_load_kg', 'working_height_m',
        'outreach_m', 'lift_height_m', 'operating_weight_kg', 'bucket_capacity_m3',
        'pto_rpm', 'cabin', 'quick_coupler', 'attachments', 'engine_serial_number',
    ];

    public const DECIMALS = [
        'operating_hours', 'odometer_km', 'power_kw', 'rated_load_kg',
        'working_height_m', 'outreach_m', 'lift_height_m', 'operating_weight_kg',
        'bucket_capacity_m3',
    ];

    /** Keys are ordered so database JSON object ordering cannot change fingerprints. */
    public static function normalize(?array $details): ?array
    {
        $result = [];
        foreach (self::KEYS as $key) {
            $value = $details[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                continue;
            }
            $result[$key] = match (true) {
                in_array($key, self::DECIMALS, true) => (float) $value,
                $key === 'pto_rpm' => (int) $value,
                $key === 'quick_coupler' => (bool) $value,
                default => $value,
            };
        }

        return $result === [] ? null : $result;
    }

    public static function merge(?array $existing, ?array $changes): ?array
    {
        return $changes === null ? null : self::normalize(array_replace($existing ?? [], $changes));
    }

    /** Validate the final identity, also called against the locked row on PATCH. */
    public static function validationErrors(array $data, ?Vehicle $existing = null): array
    {
        $value = static fn (string $key) => array_key_exists($key, $data)
            ? $data[$key] : $existing?->getAttribute($key);
        $kind = $value('asset_kind') ?? 'road_vehicle';
        $errors = [];
        if ($existing !== null && $kind !== ($existing->asset_kind ?? 'road_vehicle')) {
            $errors['asset_kind'] = __('La famille du véhicule ou du matériel ne peut pas être changée.');

            return $errors;
        }
        $details = array_key_exists('equipment_details', $data)
            ? self::merge($existing?->equipment_details, $data['equipment_details'])
            : self::normalize($existing?->equipment_details);
        if ($kind !== 'equipment') {
            if ($value('equipment_type') !== null || $details !== null) {
                $errors['equipment_details'] = __('Les caractéristiques d’engin sont réservées aux engins et matériels.');
            }

            return $errors;
        }
        if ($value('equipment_type') === null) {
            $errors['equipment_type'] = __('Sélectionnez un type d’engin.');
        }
        if ($value('brand_id') === null && empty($details['brand_label'])) {
            $errors['equipment_details.brand_label'] = __('Renseignez une marque ou sélectionnez une référence.');
        }
        if ($value('vehicle_model_id') === null && empty($details['model_label'])) {
            $errors['equipment_details.model_label'] = __('Renseignez un modèle ou une désignation.');
        }
        if (($modelId = $value('vehicle_model_id')) !== null) {
            $model = VehicleModel::find($modelId);
            if ($model !== null && $model->equipment_category !== $value('equipment_type')) {
                $errors['vehicle_model_id'] = __('Ce modèle ne correspond pas au type d’engin sélectionné.');
            }
            if ($model !== null && (string) $model->brand_id !== (string) $value('brand_id')) {
                $errors['vehicle_model_id'] = __('Ce modèle n’appartient pas à la marque sélectionnée.');
            }
        }

        return $errors;
    }
}
