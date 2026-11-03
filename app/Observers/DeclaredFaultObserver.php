<?php

namespace App\Observers;

use App\Models\PrivateListingFault;
use App\Models\VehicleFault;
use App\Services\FaultDeclarations;
use App\Services\StaffVehicleInspections;

class DeclaredFaultObserver
{
    public function saved(PrivateListingFault|VehicleFault $fault): void
    {
        $target = $fault instanceof VehicleFault ? $fault->vehicle : $fault->listing;
        if (! $target) {
            return;
        }
        // Fault endpoints also keep the explicit declaration state coherent.
        $target->forceFill(['fault_declaration_state' => 'has_faults', 'fault_declared_at' => now()])->saveQuietly();
        $technicalFields = $fault instanceof VehicleFault
            ? array_merge(FaultDeclarations::FIELDS, ['detected_at', 'mileage_at_detection_km']) : FaultDeclarations::FIELDS;
        if ($fault->wasRecentlyCreated || $fault->wasChanged($technicalFields)) {
            app(StaffVehicleInspections::class)->invalidate($target, 'Declared vehicle faults changed after inspection.');
        }
    }

    public function deleted(PrivateListingFault|VehicleFault $fault): void
    {
        $target = $fault instanceof VehicleFault ? $fault->vehicle : $fault->listing;
        if (! $target) {
            return;
        }
        app(StaffVehicleInspections::class)->invalidate($target, 'A declared vehicle fault was removed after inspection.');
        if (! $target->faults()->exists()) {
            $target->forceFill(['fault_declaration_state' => 'unknown', 'fault_declared_at' => null])->saveQuietly();
        }
    }
}
