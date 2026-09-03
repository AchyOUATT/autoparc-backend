<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Applicabilite d'un numero OEM a un modele / finition / motorisation / plage d'annees. */
class OemNumberFitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'oem_number_id', 'vehicle_model_id', 'trim_id', 'engine_type_id',
        'drivetrain_id', 'engine_code', 'year_from', 'year_to', 'position', 'notes',
    ];

    public function oemNumber()
    {
        return $this->belongsTo(OemNumber::class);
    }

    public function vehicleModel()
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function trim()
    {
        return $this->belongsTo(Trim::class);
    }

    public function engineType()
    {
        return $this->belongsTo(EngineType::class);
    }

    public function drivetrain()
    {
        return $this->belongsTo(Drivetrain::class);
    }

    public function coversYear(int $year): bool
    {
        return ($this->year_from === null || $year >= $this->year_from)
            && ($this->year_to === null || $year <= $this->year_to);
    }
}
