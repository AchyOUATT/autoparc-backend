<?php

namespace App\Services;

use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;

/**
 * A selected source record is a comparison, not proof of the vehicle's version.
 *
 * `corresponding` is reserved for a future documented configuration match. The
 * current garage has no evidence of all source-discriminating attributes (in
 * particular gearbox, power and hybrid mode), so even a verified source and a
 * matching model/year cannot establish that level. VIN/generation alone cannot
 * fill those gaps. This policy is shared by candidate and selected references.
 */
class ConsumptionReference
{
    public function context(OwnedVehicle $vehicle): array
    {
        return $this->hydrateContext([
            'vehicle_model_id' => $vehicle->vehicle_model_id,
            'trim_id' => $vehicle->trim_id,
            'engine_code' => $vehicle->effective_engine_code,
            'engine_type_id' => $vehicle->effective_engine_type_id,
            'drivetrain_id' => $vehicle->effective_drivetrain_id,
            'model_year' => $vehicle->model_year,
            'specification_market' => $vehicle->specification_market,
            'requested_year' => $vehicle->manufacturing_year,
        ]);
    }

    /** Resolve identifiers once per search, not once per candidate. */
    public function hydrateContext(array $context): array
    {
        if (! array_key_exists('engine_type_code', $context)) {
            $context['engine_type_code'] = empty($context['engine_type_id']) ? null
                : EngineType::find($context['engine_type_id'])?->code;
        }
        if (! array_key_exists('drivetrain_code', $context)) {
            $context['drivetrain_code'] = empty($context['drivetrain_id']) ? null
                : Drivetrain::find($context['drivetrain_id'])?->code;
        }

        return $context;
    }

    /** @return list<string> Documented contradictions, not missing information. */
    public function contradictions(Motorisation $reference, array $context): array
    {
        $reasons = [];

        if (! empty($context['vehicle_model_id']) && (int) $reference->vehicle_model_id !== (int) $context['vehicle_model_id']) {
            $reasons[] = 'model_mismatch';
        }
        if ($reference->trim_id !== null && ! empty($context['trim_id']) && (int) $reference->trim_id !== (int) $context['trim_id']) {
            $reasons[] = 'trim_mismatch';
        }
        if ($reference->engine_code && ! empty($context['engine_code'])
            && strtoupper(trim($reference->engine_code)) !== strtoupper(trim($context['engine_code']))) {
            $reasons[] = 'engine_mismatch';
        }

        $context = $this->hydrateContext($context);
        $engineCode = $context['engine_type_code'];
        $fuel = strtoupper((string) $reference->fuel_code);
        if ($engineCode !== null) {
            $incompatibleFuel = match ($engineCode) {
                'petrol' => in_array($fuel, ['D', 'B', 'H'], true),
                'diesel' => in_array($fuel, ['X', 'Z', 'E', 'B', 'H'], true),
                'electric' => in_array($fuel, ['X', 'Z', 'E', 'D', 'N', 'L', 'H'], true),
                'hybrid' => $fuel === 'B',
                default => false,
            };
            if ($incompatibleFuel) {
                $reasons[] = 'fuel_mismatch';
            }

            $hybrid = $reference->hybrid_type;
            if ($hybrid !== null && (
                (in_array($engineCode, ['petrol', 'diesel'], true) && in_array($hybrid, ['hybrid', 'plug_in_hybrid', 'hev', 'phev'], true))
                || ($engineCode === 'hybrid' && in_array($hybrid, ['none', 'non_hybrid'], true))
            )) {
                $reasons[] = 'hybrid_mismatch';
            }
        }

        // A drivetrain stored as source provenance is only compared when both
        // sides have an explicit code; unknown never becomes a contradiction.
        $drivetrainCode = $reference->provenance['drivetrain_code'] ?? null;
        if ($drivetrainCode !== null && ! empty($context['drivetrain_code'])) {
            if (strtoupper($context['drivetrain_code']) !== strtoupper($drivetrainCode)) {
                $reasons[] = 'drivetrain_mismatch';
            }
        }

        $transmissionCode = strtoupper(trim((string) $reference->transmission_code));
        if (preg_match('/^(AV|AS|AM|A|M)([0-9]+)?$/', $transmissionCode, $sourceTransmission)) {
            $manual = $sourceTransmission[1] === 'M';
            $transmission = $context['transmission'] ?? null;
            if (($transmission === 'manual' && ! $manual)
                || (in_array($transmission, ['automatic', 'cvt', 'dual_clutch', 'single_speed'], true) && $manual)) {
                $reasons[] = 'transmission_mismatch';
            }

            // AV denotes a continuously variable transmission; a digit may
            // describe simulated ratios, not a comparable fixed gear count.
            if ($sourceTransmission[1] !== 'AV' && isset($sourceTransmission[2])
                && (int) $sourceTransmission[2] > 0 && ! empty($context['gear_count'])
                && (int) $sourceTransmission[2] !== (int) $context['gear_count']) {
                $reasons[] = 'gear_count_mismatch';
            }
        }

        return $reasons;
    }

    public function assess(Motorisation $reference, array $context = []): array
    {
        $reasons = $this->contradictions($reference, $context);
        $incomplete = $reasons !== [];

        if (($reference->reference_status ?? 'legacy') === 'legacy') {
            $reasons[] = 'legacy_reference';
            $incomplete = true;
        }
        if ($reference->reference_status === 'withdrawn') {
            $reasons[] = 'withdrawn_reference';
            $incomplete = true;
        }
        if ($reference->is_current === false) {
            $reasons[] = 'revision_superseded';
        }
        if ($reference->method === 'aggregation') {
            $reasons[] = 'aggregation';
        } elseif (empty($reference->method) || $reference->method === 'unknown') {
            $reasons[] = 'method_unknown';
            $incomplete = true;
        }
        if ($reference->year_kind === 'registration_year') {
            $reasons[] = 'registration_year';
        } elseif (empty($reference->year_kind) || $reference->year_kind === 'unknown') {
            $reasons[] = 'unknown_year_kind';
            $incomplete = true;
        }
        if (empty($context['model_year'])) {
            $reasons[] = 'model_year_unknown';
        } elseif ($reference->year_kind === 'model_year' && (int) $reference->model_year !== (int) $context['model_year']) {
            $reasons[] = 'model_year_mismatch';
        }
        if (empty($context['specification_market']) || empty($reference->market)) {
            $reasons[] = 'market_unknown';
        } elseif (strtoupper($context['specification_market']) !== strtoupper($reference->market)) {
            $reasons[] = 'market_mismatch';
        }
        if ($reference->verified_at === null) {
            $reasons[] = 'source_unverified';
        }

        // A source's verification and a user's selection are independent of a
        // verified match to that user's full technical configuration.
        $reasons[] = 'configuration_unverified';
        if ($reference->method !== 'aggregation') {
            // No current workflow documents the full engine/gearbox/power/hybrid
            // identity of the owned vehicle. Choosing one row cannot resolve
            // competing variants on its own. Aggregates may only be compared.
            $reasons[] = 'missing_configuration_identity';
            $incomplete = true;
        }

        return [
            'level' => $incomplete ? 'incomplete' : 'approximate',
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /** The source values remain inspectable even when no primary value is safe. */
    public function values(Motorisation $reference, array $match): array
    {
        $value = fn (string $field) => $reference->$field === null ? null : (float) $reference->$field;
        $corresponding = $match['level'] === 'corresponding';

        return [
            'city_l_100km' => $corresponding ? $value('consumption_city') : null,
            'highway_l_100km' => $corresponding ? $value('consumption_highway') : null,
            'combined_l_100km' => $corresponding ? $value('consumption_combined') : null,
            'reference_city_l_100km' => $value('consumption_city'),
            'reference_highway_l_100km' => $value('consumption_highway'),
            'reference_combined_l_100km' => $value('consumption_combined'),
        ];
    }

    public function metadata(Motorisation $reference, array $context = []): array
    {
        return [
            'model_year' => $reference->model_year,
            'model_raw' => $reference->model_raw,
            'make_raw' => $reference->make_raw,
            'year_kind' => $reference->year_kind ?? 'unknown',
            'requested_year' => $context['requested_year'] ?? null,
            'market' => $reference->market,
            'method' => $reference->method ?? 'unknown',
            'reference_status' => $reference->reference_status ?? 'legacy',
            'verified_at' => $reference->verified_at?->toIso8601String(),
            'provenance' => $reference->provenance ?? (object) [],
            'identity_key' => $reference->identity_key,
            'revision' => $reference->cle_source,
            'is_current' => $reference->is_current ?? true,
            'power_kw' => $reference->power_kw === null ? null : (float) $reference->power_kw,
            'displacement_cc' => $reference->displacement_cc,
            'hybrid_type' => $reference->hybrid_type,
            'trim_id' => $reference->trim_id,
            'trim_application_id' => $reference->trim_application_id,
            'engine_code' => $reference->engine_code,
            'transmission_code' => $reference->transmission_code,
            'cycle_code' => $reference->cycle,
        ];
    }
}
