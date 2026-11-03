<?php

namespace App\Models;

use App\Enums\FaultCategory;
use App\Enums\FaultSeverity;
use App\Enums\FaultStatus;
use App\Observers\DeclaredFaultObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(DeclaredFaultObserver::class)]
class PrivateListingFault extends Model
{
    protected $fillable = ['category', 'title', 'description', 'severity', 'status',
        'affects_drivability', 'is_safety_critical', 'disclosed_to_buyer'];

    protected function casts(): array
    {
        return ['category' => FaultCategory::class, 'severity' => FaultSeverity::class, 'status' => FaultStatus::class,
            'affects_drivability' => 'boolean', 'is_safety_critical' => 'boolean', 'disclosed_to_buyer' => 'boolean'];
    }

    public function listing()
    {
        return $this->belongsTo(PrivateVehicleListing::class, 'private_vehicle_listing_id');
    }
}
