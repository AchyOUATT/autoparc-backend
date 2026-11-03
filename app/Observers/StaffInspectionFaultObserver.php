<?php

namespace App\Observers;

use App\Enums\FaultStatus;
use App\Models\VehicleFault;
use App\Services\StaffVehicleInspections;

class StaffInspectionFaultObserver
{
    public function saved(VehicleFault $fault): void
    {
        // A new fault (including a deliberately unrepaired one), or a reopened/changed
        // diagnosis, makes the previous report insufficient. Resolving it never restores a badge.
        if ($fault->status !== FaultStatus::Repaired && ($fault->wasRecentlyCreated || $fault->wasChanged([
            'status', 'category', 'severity', 'affects_drivability', 'is_safety_critical',
            'title', 'description', 'detected_at', 'mileage_at_detection_km',
        ]))) {
            if ($vehicle = $fault->vehicle) {
                app(StaffVehicleInspections::class)->invalidate($vehicle, 'A vehicle fault was recorded or changed after inspection.');
            }
        }
    }
}
