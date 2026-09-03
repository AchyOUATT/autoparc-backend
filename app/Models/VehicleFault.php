<?php

namespace App\Models;

use App\Enums\FaultCategory;
use App\Enums\FaultSeverity;
use App\Enums\FaultStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Panne declaree sur un vehicule (surtout pertinent pour l'occasion immatriculee). */
class VehicleFault extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'code', 'category', 'title', 'description', 'severity', 'status',
        'affects_drivability', 'is_safety_critical', 'disclosed_to_buyer', 'detected_at',
        'mileage_at_detection_km', 'reported_by', 'estimated_repair_cost',
        'actual_repair_cost', 'repaired_at', 'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'category'              => FaultCategory::class,
            'severity'              => FaultSeverity::class,
            'status'                => FaultStatus::class,
            'affects_drivability'   => 'boolean',
            'is_safety_critical'    => 'boolean',
            'disclosed_to_buyer'    => 'boolean',
            'detected_at'           => 'date',
            'repaired_at'           => 'date',
            'estimated_repair_cost' => 'decimal:2',
            'actual_repair_cost'    => 'decimal:2',
        ];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Pieces necessaires a la reparation. */
    public function parts()
    {
        return $this->belongsToMany(Part::class, 'fault_part')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [FaultStatus::Repaired->value, FaultStatus::WontFix->value]);
    }

    public function scopeCritical(Builder $q): Builder
    {
        return $q->whereIn('severity', [FaultSeverity::Major->value, FaultSeverity::Critical->value]);
    }
}
