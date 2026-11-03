<?php

namespace App\Services;

use App\Enums\FaultStatus;
use App\Models\PrivateVehicleListing;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFault;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class FaultDeclarations
{
    public const FIELDS = ['category', 'title', 'description', 'severity', 'status', 'affects_drivability', 'is_safety_critical', 'disclosed_to_buyer'];

    public static function requested(array $data): bool
    {
        return array_key_exists('fault_declaration_state', $data) || array_key_exists('faults', $data);
    }

    public static function canManageStock(Request $request, Vehicle $vehicle): bool
    {
        if ($request->is('api/catalog/*')) {
            return false;
        }
        $user = $request->user();

        return $user !== null && ($user->isStaff() || ($user->isPartner()
            && $vehicle->owner_partner_account_id !== null && (int) $vehicle->sellerAccount?->user_id === (int) $user->id));
    }

    public static function editable($fault, ?User $actor, Vehicle|PrivateVehicleListing $target): bool
    {
        if ($actor === null) {
            return false;
        }
        if ($actor->role->canManageFaults()) {
            return true;
        }
        if ($target instanceof PrivateVehicleListing) {
            return (int) $target->user_id === (int) $actor->id;
        }

        return $actor->isPartner() && (int) $fault->declared_by_user_id === (int) $actor->id
            && $fault->staff_managed_at === null
            && in_array($fault->status?->value, ['declared', 'repaired', 'wont_fix'], true)
            && $fault->disclosed_to_buyer
            && collect(['assigned_to', 'code', 'reported_by', 'estimated_repair_cost', 'actual_repair_cost', 'repaired_at'])
                ->every(fn ($field) => $fault->getAttribute($field) === null);
    }

    /** Caller owns the target transaction/lock. Omitted fields are deliberately a no-op. */
    public function sync(Vehicle|PrivateVehicleListing $target, array $data, User $actor): void
    {
        if (! self::requested($data)) {
            return;
        }
        $existing = $target->faults()->lockForUpdate()->get();
        $state = $data['fault_declaration_state'] ?? ($existing->isNotEmpty() || ! empty($data['faults']) ? 'has_faults' : ($target->fault_declaration_state ?? 'unknown'));
        $incoming = $data['faults'] ?? null;

        if ($target instanceof Vehicle && ! $actor->role->canManageFaults() && ! $actor->isPartner()) {
            if ($state !== 'unknown' || ! empty($incoming) || $existing->isNotEmpty() || ($target->fault_declaration_state ?? 'unknown') !== 'unknown') {
                abort(403, __('Vous ne pouvez pas modifier la déclaration des défauts.'));
            }

            return;
        }

        $retained = $existing;
        if ($incoming !== null) {
            $ids = collect($incoming)->pluck('id')->filter()->map(fn ($id) => (int) $id);
            foreach ($ids as $id) {
                $fault = $existing->firstWhere('id', $id);
                if (! $fault || ! self::editable($fault, $actor, $target)) {
                    throw ValidationException::withMessages(['faults' => __('Un défaut ne peut être modifié que sur son véhicule et par son déclarant autorisé.')]);
                }
            }
            $retained = $existing->filter(fn ($fault) => ! self::editable($fault, $actor, $target));
        }
        $total = $incoming === null ? $existing->count() : $retained->count() + count($incoming);
        if (($state === 'none' && $total !== 0) || ($state === 'unknown' && $total !== 0) || ($state === 'has_faults' && $total === 0)) {
            throw ValidationException::withMessages(['fault_declaration_state' => __('La déclaration des défauts et la liste renseignée sont incohérentes.')]);
        }
        if ($incoming !== null) {
            // Compare the persisted technical snapshot, not mutable form DTOs or array ordering.
            $before = self::technicalSnapshot($existing);
            foreach ($existing as $fault) {
                if (self::editable($fault, $actor, $target) && ! $ids->contains((int) $fault->id)) {
                    $fault->delete();
                }
            }
            foreach ($incoming as $row) {
                $original = isset($row['id']) ? $existing->firstWhere('id', (int) $row['id']) : null;
                $values = array_intersect_key($row, array_fill_keys(self::FIELDS, true));
                $values += ['status' => $original?->status?->value ?? 'declared', 'severity' => $original?->severity?->value ?? 'minor',
                    'affects_drivability' => $original?->affects_drivability ?? false,
                    'is_safety_critical' => $original?->is_safety_critical ?? false, 'description' => $original?->description];
                $values['disclosed_to_buyer'] = $target instanceof Vehicle && $actor->role->canManageFaults()
                    ? ($row['disclosed_to_buyer'] ?? $original?->disclosed_to_buyer ?? true) : true;
                if ($target instanceof Vehicle && $actor->role->canManageFaults()) {
                    $values['staff_managed_at'] = now();
                }
                if (isset($row['id'])) {
                    $existing->firstWhere('id', (int) $row['id'])->forceFill($values)->save();
                } else {
                    $staffManagedAt = $values['staff_managed_at'] ?? null;
                    unset($values['staff_managed_at']);
                    $new = $target->faults()->make($values);
                    if ($new instanceof VehicleFault) {
                        $new->forceFill(['declared_by_user_id' => $actor->id, 'staff_managed_at' => $staffManagedAt]);
                    }
                    $new->save();
                }
            }
            $after = $target->faults()->get();
            if ($before !== self::technicalSnapshot($after)) {
                app(StaffVehicleInspections::class)->invalidate($target, 'Declared vehicle faults changed after inspection.');
            }
        }
        $changedState = $target->fault_declaration_state !== $state;
        $target->forceFill(['fault_declaration_state' => $state, 'fault_declared_at' => $state === 'unknown' ? null : now()])->save();
        if ($changedState) {
            app(StaffVehicleInspections::class)->invalidate($target, 'Vehicle fault declaration changed after inspection.');
        }
        $target->load('faults');
    }

    public static function requireComplete(PrivateVehicleListing $target): void
    {
        $count = $target->faults()->count();
        if (! in_array($target->fault_declaration_state, ['none', 'has_faults'], true)
            || ($target->fault_declaration_state === 'none' && $count !== 0)
            || ($target->fault_declaration_state === 'has_faults' && $count === 0)) {
            throw ValidationException::withMessages(['fault_declaration_state' => __('Déclarez explicitement les défauts connus ou l’absence de défaut connu avant de soumettre cette annonce.')]);
        }
    }

    public static function row($fault, bool $management = false, ?User $actor = null, Vehicle|PrivateVehicleListing|null $target = null): array
    {
        $data = ['id' => $fault->id, 'category' => $fault->category->value, 'title' => $fault->title,
            'description' => $fault->description, 'severity' => $fault->severity->value,
            'status' => $fault->status->value, 'is_open' => $fault->status->isOpen(),
            'affects_drivability' => (bool) $fault->affects_drivability,
            'is_safety_critical' => (bool) $fault->is_safety_critical, 'disclosed_to_buyer' => (bool) $fault->disclosed_to_buyer];
        if ($management && $target !== null) {
            $data['editable'] = self::editable($fault, $actor, $target);
        }

        return $data;
    }

    public static function present(Vehicle|PrivateVehicleListing $target, Request $request, bool $management): array
    {
        $all = $target->relationLoaded('faults') ? $target->getRelation('faults') : collect();
        $visible = $management ? $all : $all->where('disclosed_to_buyer', true);
        $state = $all->isNotEmpty() ? 'has_faults' : ($target->fault_declaration_state ?? 'unknown');

        // Hidden rows still establish that a diagnostic exists; only its technical details are withheld.
        return ['state' => $state, 'declared_at' => $target->fault_declared_at?->toIso8601String(),
            'faults' => $visible->map(fn ($fault) => self::row($fault, $management, $request->user(), $target))->values()->all(),
            'unresolved_count' => $all->filter(fn ($fault) => $fault->status !== FaultStatus::Repaired)->count()];
    }

    public static function technicalSnapshot(Collection $faults): array
    {
        return $faults->sortBy('id')->map(function ($fault) {
            $row = self::row($fault);
            if ($fault instanceof VehicleFault) {
                // Part of the inspection's evidence, never the public declaration payload.
                $row['detected_at'] = $fault->detected_at?->format('Y-m-d');
                $row['mileage_at_detection_km'] = $fault->mileage_at_detection_km === null ? null : (string) $fault->mileage_at_detection_km;
            }

            return $row;
        })->values()->all();
    }
}
