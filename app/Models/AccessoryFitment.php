<?php

namespace App\Models;

use App\Enums\FitmentSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessoryFitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'accessory_id', 'vehicle_model_id', 'trim_id', 'engine_type_id',
        'drivetrain_id', 'year_from', 'year_to', 'notes',
        // Voir PartFitment : renseignee par les controleurs, pas par la requete.
        'source',
    ];

    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to'   => 'integer',
            'source'    => FitmentSource::class,
        ];
    }

    /** Voir PartFitment::scopeFabriquees() : ce que le recalcul peut detruire. */
    public function scopeFabriquees($query)
    {
        return $query->where('source', FitmentSource::Generated);
    }

    public function accessory()
    {
        return $this->belongsTo(Accessory::class);
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
}
