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
        // Criteres restrictifs (null = aucune restriction). Voir FitmentRestrictions.
        'chassis_code', 'chassis_restricted', 'market_codes', 'month_from', 'month_to',
        // Preuve de la compatibilite (voir FitmentEvidence).
        'evidence_level', 'evidence_sources', 'verified_by_id', 'verified_on',
    ];

    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to' => 'integer',
            'chassis_restricted' => 'boolean',
            'market_codes' => 'array',
            'month_from' => 'integer',
            'month_to' => 'integer',
            'evidence_sources' => 'array',
            'verified_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OemNumberFitment $fitment) {
            $fitment->market_codes = \App\Support\FitmentRestrictionInput::marketCodes($fitment->market_codes);
        });
    }

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
