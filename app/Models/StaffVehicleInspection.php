<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A staff report is immutable. Only its publication lifecycle may change. */
class StaffVehicleInspection extends Model
{
    protected $guarded = ['id'];
    public Vehicle|PrivateVehicleListing|null $inspectionTarget = null;

    protected function casts(): array
    {
        return [
            'vehicle_id' => 'integer', 'private_vehicle_listing_id' => 'integer', 'source_id' => 'integer',
            'actor_id' => 'integer', 'withdrawn_by' => 'integer',
            'inspected_at' => 'datetime', 'valid_until' => 'datetime', 'withdrawn_at' => 'datetime',
            'mileage_km' => 'integer', 'items' => 'array',
            'checked_count' => 'integer', 'attention_count' => 'integer', 'blocking_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $inspection) {
            if (! $inspection->exists && (($inspection->vehicle_id === null) === ($inspection->private_vehicle_listing_id === null))) {
                throw new LogicException('An inspection requires exactly one vehicle source.');
            }
            if (! $inspection->exists && ($inspection->source !== ($inspection->vehicle_id !== null ? 'vehicle' : 'private_listing')
                || $inspection->source_id !== ($inspection->vehicle_id ?? $inspection->private_vehicle_listing_id))) {
                throw new LogicException('Inspection source must match its native vehicle foreign key.');
            }
            if ($inspection->exists && array_diff(array_keys($inspection->getDirty()), [
                'active_key', 'status', 'withdrawn_by', 'withdrawn_at', 'withdrawal_reason', 'updated_at',
            ])) {
                throw new LogicException('An inspection report cannot be edited; create a new report.');
            }
        });
        static::deleting(fn () => throw new LogicException('Inspection history cannot be deleted.'));
    }

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function withdrawnBy() { return $this->belongsTo(User::class, 'withdrawn_by'); }
    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function privateListing() { return $this->belongsTo(PrivateVehicleListing::class, 'private_vehicle_listing_id'); }

    public function source(): string
    {
        return $this->getAttribute('source');
    }

    public function sourceId(): int
    {
        return $this->getAttribute('source_id');
    }
}
