<?php

namespace App\Observers;

use App\Models\VehicleImportDetail;
use App\Models\VehicleRegistrationDetail;
use App\Services\StaffVehicleInspections;

class StaffInspectionDetailObserver
{
    public function saved(VehicleImportDetail|VehicleRegistrationDetail $detail): void
    {
        $fields = $detail instanceof VehicleRegistrationDetail
            ? ['mileage_km', 'has_accident_history', 'plate_number', 'first_registration_date']
            : ['odometer_at_import_km', 'steering_side', 'foreign_plate'];
        if (($detail->wasRecentlyCreated && array_intersect($fields, array_keys($detail->getAttributes())))
            || $detail->wasChanged($fields)) {
            if ($vehicle = $detail->vehicle) {
                app(StaffVehicleInspections::class)->invalidate($vehicle, 'Vehicle mileage or technical history changed after inspection.');
            }
        }
    }

    public function deleted(VehicleImportDetail|VehicleRegistrationDetail $detail): void
    {
        if ($vehicle = $detail->vehicle) {
            app(StaffVehicleInspections::class)->invalidate($vehicle, 'Vehicle technical details were removed after inspection.');
        }
    }
}
