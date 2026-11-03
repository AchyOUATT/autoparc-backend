<?php

namespace App\Models\Concerns;

use App\Models\StaffVehicleInspection;
use App\Models\Vehicle;
use App\Services\StaffVehicleInspections;

trait HasStaffInspections
{
    public function initializeHasStaffInspections(): void
    {
        // One eager query per model batch, including partner and unified catalogue paths.
        $this->with[] = 'activeStaffInspection';
        if ($this instanceof Vehicle) {
            // Mileage/history live in these one-to-one tables and are part of the fingerprint.
            $this->with[] = 'registrationDetail';
            $this->with[] = 'importDetail';
        }
    }

    public function staffInspections()
    {
        return $this->hasMany(StaffVehicleInspection::class, $this instanceof Vehicle ? 'vehicle_id' : 'private_vehicle_listing_id');
    }

    public function activeStaffInspection()
    {
        return $this->hasOne(StaffVehicleInspection::class, $this instanceof Vehicle ? 'vehicle_id' : 'private_vehicle_listing_id')
            ->where('status', 'active')->whereNotNull('active_key');
    }

    protected static function bootHasStaffInspections(): void
    {
        static::updated(function ($model) {
            if (array_intersect(array_keys($model->getChanges()), StaffVehicleInspections::technicalFields($model))) {
                app(StaffVehicleInspections::class)->invalidate($model, 'Vehicle technical information changed after inspection.');
            }
        });
    }
}
