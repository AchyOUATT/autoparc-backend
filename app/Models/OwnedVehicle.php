<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OwnedVehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'brand_id', 'vehicle_model_id', 'trim_id',
        'engine_type_id', 'drivetrain_id', 'color_id',
        'manufacturing_year', 'vin', 'engine_code', 'plate_number', 'nickname', 'mileage_km',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_year' => 'integer',
            'mileage_km'         => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
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

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    /**
     * Moteur retenu pour le calcul de compatibilite : celui saisi par le client,
     * sinon celui par defaut de la finition (peut rester null si aucun des deux).
     */
    public function getEffectiveEngineTypeIdAttribute(): ?int
    {
        return $this->engine_type_id ?? $this->trim?->default_engine_type_id;
    }

    public function getEffectiveDrivetrainIdAttribute(): ?int
    {
        return $this->drivetrain_id ?? $this->trim?->default_drivetrain_id;
    }

    /**
     * Code moteur effectif pour la compatibilite (ex: "1NZ", "K20", "OM651").
     * Fourni par le decodage VIN (NHTSA) ou saisi manuellement.
     * Optionnel : null si l'utilisateur ne l'a pas renseigne.
     */
    public function getEffectiveEngineCodeAttribute(): ?string
    {
        return $this->engine_code ?: null;
    }

    /** Le vehicule a un moteur precis (saisi ou deduit) : la compatibilite sera plus fiable. */
    public function hasPreciseEngineData(): bool
    {
        return $this->effective_engine_type_id !== null || $this->effective_engine_code !== null;
    }

    public function getDesignationAttribute(): string
    {
        return trim(sprintf(
            '%s %s %s %d',
            $this->brand?->name,
            $this->vehicleModel?->name,
            $this->trim?->name ?? '',
            $this->manufacturing_year
        ));
    }

    public function scopeOwnedBy(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }
}
