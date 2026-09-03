<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Niveau de finition : LE, SE, XLE, Limited, GLS... */
class Trim extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_model_id', 'name', 'code', 'description', 'rank',
        'default_engine_type_id', 'default_drivetrain_id',
    ];

    public function vehicleModel()
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Motorisation la plus courante pour cette finition (nullable : certaines
     * finitions existent avec plusieurs moteurs, auquel cas on laisse le client
     * preciser lui-meme sur son vehicule enregistre).
     */
    public function defaultEngineType()
    {
        return $this->belongsTo(EngineType::class, 'default_engine_type_id');
    }

    public function defaultDrivetrain()
    {
        return $this->belongsTo(Drivetrain::class, 'default_drivetrain_id');
    }
}
