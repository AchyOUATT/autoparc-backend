<?php

namespace App\Services;

use App\Models\PrivateVehicleListing;
use App\Models\StaffVehicleInspection;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
use Illuminate\Support\Facades\DB;

final class StaffVehicleInspections
{
    public static function technicalFields(Vehicle|PrivateVehicleListing $target): array
    {
        $common = ['brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id', 'transmission'];

        return array_merge($common, $target instanceof Vehicle ? [
            'vin', 'registration_status', 'vehicle_type', 'body_style', 'manufacturing_year',
            'gear_count', 'engine_displacement_cc', 'power_hp', 'power_kw', 'torque_nm',
            'cylinders', 'seats', 'doors', 'engine_code', 'condition',
            'battery_capacity_kwh', 'fuel_tank_liters',
        ] : ['model_libre', 'year', 'mileage_km', 'fuel_type']);
    }

    public static function fingerprint(Vehicle|PrivateVehicleListing $target): string
    {
        $data = [];
        foreach (self::technicalFields($target) as $field) {
            $value = $target->getAttribute($field);
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }
            // SQLite/PostgreSQL and fresh/unsaved Eloquent values must have identical hashes.
            $data[$field] = $value === null ? null : (string) $value;
        }
        if ($target instanceof Vehicle) {
            foreach ([
                'registrationDetail' => ['mileage_km', 'has_accident_history', 'plate_number', 'first_registration_date'],
                'importDetail' => ['odometer_at_import_km', 'steering_side', 'foreign_plate'],
            ] as $relation => $fields) {
                // These relations are eager-loaded by HasStaffInspections. Never query per card.
                $detail = $target->relationLoaded($relation) ? $target->getRelation($relation) : null;
                $data[$relation] = [];
                foreach ($fields as $field) {
                    $value = $detail?->getAttribute($field);
                    $data[$relation][$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d')
                        : ($value === null ? null : (string) $value);
                }
            }
        }

        $declaredFaults = FaultDeclarations::technicalSnapshot($target->relationLoaded('faults') ? $target->getRelation('faults') : collect());
        // Preserve legacy report hashes only when no declaration or fault has ever been recorded.
        if (($target->fault_declaration_state ?? 'unknown') !== 'unknown' || $declaredFaults !== []) {
            $data['fault_declaration_state'] = $target->fault_declaration_state ?? 'unknown';
            $data['declared_faults'] = $declaredFaults;
        }
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function target(string $source, int $id, bool $lock = false): Vehicle|PrivateVehicleListing
    {
        abort_unless(in_array($source, ['vehicle', 'private_listing'], true), 404);
        abort_if($source === 'private_listing' && ! config('private_sales.enabled'), 404);
        $query = $source === 'vehicle' ? Vehicle::query() : PrivateVehicleListing::query();

        return $query->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function create(string $source, int $id, array $data, User $actor): StaffVehicleInspection
    {
        return DB::transaction(function () use ($source, $id, $data, $actor) {
            // Serialize creation against other inspections of this same source, never numeric ID alone.
            $target = $this->target($source, $id, lock: true);
            $key = $source.':'.$id;
            $target->staffInspections()->where('status', 'active')->lockForUpdate()->get()
                ->each(fn ($old) => $old->forceFill(['status' => 'superseded', 'active_key' => null])->save());

            $items = array_map(fn ($item) => [
                'label' => trim($item['label']), 'status' => $item['status'], 'note' => $item['note'] ?? null,
            ], $data['items']);
            $counts = array_count_values(array_column($items, 'status'));
            $blocking = $counts['blocking'] ?? 0;
            $attention = $counts['attention'] ?? 0;

            return $target->staffInspections()->create([
                'source' => $source, 'source_id' => $id,
                'active_key' => $key, 'actor_id' => $actor->id,
                'inspected_at' => $data['inspected_at'], 'mileage_km' => $data['mileage_km'],
                'valid_until' => $data['valid_until'] ?? null, 'summary' => trim($data['summary']),
                'items' => $items, 'result' => $blocking ? 'blocked' : ($attention ? 'attention' : 'clear'),
                'checked_count' => count($items) - ($counts['not_checked'] ?? 0),
                'attention_count' => $attention, 'blocking_count' => $blocking,
                'status' => 'active', 'technical_fingerprint' => self::fingerprint($target),
            ])->load(['actor', 'withdrawnBy']);
        });
    }

    public function withdraw(StaffVehicleInspection $inspection, string $reason, ?User $actor = null): StaffVehicleInspection
    {
        return DB::transaction(function () use ($inspection, $reason, $actor) {
            $locked = StaffVehicleInspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 409, __('Seule une inspection active peut être retirée.'));
            $locked->forceFill([
                'status' => 'withdrawn', 'active_key' => null, 'withdrawn_by' => $actor?->id,
                'withdrawn_at' => now(), 'withdrawal_reason' => $reason,
            ])->save();

            return $locked->load(['actor', 'withdrawnBy']);
        });
    }

    public function invalidate(Vehicle|PrivateVehicleListing $target, string $reason): void
    {
        DB::transaction(function () use ($target, $reason) {
            // Use the same target -> report lock order as inspection creation.
            $target->newQuery()->without('activeStaffInspection')->whereKey($target->id)->lockForUpdate()->first();
            $target->staffInspections()->where('status', 'active')->lockForUpdate()->get()
                ->each(fn ($inspection) => $this->withdraw($inspection, $reason));
        });
        $target->unsetRelation('activeStaffInspection');
    }

    public static function isCurrent(StaffVehicleInspection $inspection, Vehicle|PrivateVehicleListing $target): bool
    {
        return $inspection->status === 'active'
            && $inspection->source() === ($target instanceof Vehicle ? 'vehicle' : 'private_listing')
            && $inspection->sourceId() === $target->id
            && ($target instanceof Vehicle ? $inspection->vehicle_id : $inspection->private_vehicle_listing_id) === $target->id
            && $inspection->active_key === $inspection->source().':'.$inspection->sourceId()
            && ($inspection->valid_until === null || $inspection->valid_until->isFuture())
            && hash_equals($inspection->technical_fingerprint, self::fingerprint($target));
    }

    public static function summary(Vehicle|PrivateVehicleListing $target): ?array
    {
        // Never silently introduce one query per card if a new presentation path forgets eager loading.
        $inspection = $target->relationLoaded('activeStaffInspection') ? $target->getRelation('activeStaffInspection') : null;

        return $inspection && self::isCurrent($inspection, $target) ? self::summaryFields($inspection) : null;
    }

    public static function summaryFields(StaffVehicleInspection $inspection): array
    {
        return [
            'id' => $inspection->id, 'inspected_at' => $inspection->inspected_at->toIso8601String(),
            'mileage_km' => $inspection->mileage_km, 'result' => $inspection->result,
            'checked_count' => $inspection->checked_count, 'attention_count' => $inspection->attention_count,
            'blocking_count' => $inspection->blocking_count,
            'valid_until' => $inspection->valid_until?->toIso8601String(),
        ];
    }
}
