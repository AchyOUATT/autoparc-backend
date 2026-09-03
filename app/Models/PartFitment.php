<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Compatibilite declaree directement entre une piece et un modele de vehicule. */
class PartFitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_id', 'vehicle_model_id', 'trim_id', 'engine_type_id',
        'drivetrain_id', 'engine_code', 'year_from', 'year_to', 'position', 'notes',
    ];

    public function part()
    {
        return $this->belongsTo(Part::class);
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
