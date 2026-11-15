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
        'affects_drivability', 'is_safety_critical', 'disclosed_to_buyer',
        'repair_necessity', 'repair_cost_min', 'repair_cost_max', 'repair_estimate_source', 'repair_estimated_at'];

    protected function casts(): array
    {
        return ['category' => FaultCategory::class, 'severity' => FaultSeverity::class, 'status' => FaultStatus::class,
            'affects_drivability' => 'boolean', 'is_safety_critical' => 'boolean', 'disclosed_to_buyer' => 'boolean',
            'repair_cost_min' => 'integer', 'repair_cost_max' => 'integer', 'repair_estimated_at' => 'date'];
    }


    protected static function booted(): void
    {
        static::deleting(function ($fault) {
            $fault->repairQuotes()->get()->each(function (FaultRepairQuote $quote) {
                try {
                    \Illuminate\Support\Facades\Storage::disk($quote->disk)->delete($quote->path);
                } catch (\Throwable) {
                    // The row must still disappear so a leftover path cannot be served.
                }
                $quote->delete();
            });
        });
    }

    public function listing()
    {
        return $this->belongsTo(PrivateVehicleListing::class, 'private_vehicle_listing_id');
    }

    public function repairQuotes()
    {
        return $this->morphMany(FaultRepairQuote::class, 'fault');
    }

    public function privateQuote()
    {
        return $this->morphOne(FaultRepairQuote::class, 'fault')->where('kind', FaultRepairQuote::KIND_PRIVATE);
    }

    public function publishedQuote()
    {
        return $this->morphOne(FaultRepairQuote::class, 'fault')->where('kind', FaultRepairQuote::KIND_PUBLISHED);
    }
}
