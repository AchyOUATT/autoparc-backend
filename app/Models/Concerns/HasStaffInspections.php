<?php

namespace App\Models\Concerns;

use App\Models\StaffVehicleInspection;
use App\Models\Vehicle;
use App\Services\StaffVehicleInspections;
use App\Support\EquipmentDetails;
use App\Support\HeavyVehicleConfiguration;
use App\Support\TwoWheelerConfiguration;

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
            $technicalChanges = array_intersect(array_keys($model->getChanges()), StaffVehicleInspections::technicalFields($model));
            // An array cast considers object key order significant. Compare
            // the same canonical values used by the inspection fingerprint.
            if ($model instanceof Vehicle && in_array('heavy_configuration', $technicalChanges, true)
                && HeavyVehicleConfiguration::normalize($model->heavy_configuration)
                    === HeavyVehicleConfiguration::normalize($model->getOriginal('heavy_configuration'))) {
                $technicalChanges = array_diff($technicalChanges, ['heavy_configuration']);
            }
            if ($model instanceof Vehicle && in_array('two_wheeler_configuration', $technicalChanges, true)
                && TwoWheelerConfiguration::normalize($model->two_wheeler_configuration)
                    === TwoWheelerConfiguration::normalize($model->getOriginal('two_wheeler_configuration'))) {
                $technicalChanges = array_diff($technicalChanges, ['two_wheeler_configuration']);
            }
            if ($model instanceof Vehicle && in_array('equipment_details', $technicalChanges, true)
                && EquipmentDetails::normalize($model->equipment_details)
                    === EquipmentDetails::normalize($model->getOriginal('equipment_details'))) {
                $technicalChanges = array_diff($technicalChanges, ['equipment_details']);
            }
            if ($technicalChanges !== []) {
                app(StaffVehicleInspections::class)->invalidate($model, 'Vehicle technical information changed after inspection.');
            }
        });
    }
}
