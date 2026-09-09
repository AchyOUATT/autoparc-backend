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
        'technical_inspection_expiry', 'insurance_expiry',
        'last_service_date', 'last_service_mileage_km', 'service_interval_km',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_year'          => 'integer',
            'mileage_km'                  => 'integer',
            'technical_inspection_expiry' => 'date',
            'insurance_expiry'            => 'date',
            'last_service_date'           => 'date',
            'last_service_mileage_km'     => 'integer',
            'service_interval_km'         => 'integer',
        ];
    }

    /**
     * Echeances d'entretien du vehicule, de la plus urgente a la moins urgente.
     *
     * Une seule source de verite : la tache de rappel et l'API affichent la
     * meme liste, calculee ici. Chaque entree porte son type, son libelle, sa
     * date d'echeance quand elle en a une, et le nombre de jours restants,
     * negatif si l'echeance est depassee.
     *
     * @return array<int, array{kind:string,label:string,due_on:?string,days_left:?int,overdue:bool,detail:?string}>
     */
    public function deadlines(): array
    {
        $today = \Carbon\CarbonImmutable::today();
        $items = [];

        foreach ([
            'technical_inspection' => ['Visite technique', $this->technical_inspection_expiry],
            'insurance'            => ['Assurance',        $this->insurance_expiry],
        ] as $kind => [$label, $date]) {
            if ($date === null) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays(\Carbon\CarbonImmutable::parse($date), false);

            $items[] = [
                'kind'      => $kind,
                'label'     => $label,
                'due_on'    => $date->toDateString(),
                'days_left' => $daysLeft,
                'overdue'   => $daysLeft < 0,
                'detail'    => null,
            ];
        }

        // Vidange : suivi kilometrique, donc tributaire d'un kilometrage tenu
        // a jour par le proprietaire. Sans les trois valeurs, pas de rappel.
        if ($this->service_interval_km && $this->last_service_mileage_km !== null && $this->mileage_km !== null) {
            $remaining = $this->service_interval_km - ($this->mileage_km - $this->last_service_mileage_km);

            $items[] = [
                'kind'      => 'service',
                'label'     => 'Vidange',
                'due_on'    => null,
                'days_left' => null,
                'overdue'   => $remaining <= 0,
                'detail'    => $remaining > 0
                    ? "Dans {$remaining} km"
                    : 'Depassee de ' . abs($remaining) . ' km',
            ];
        }

        usort($items, fn ($a, $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));

        return $items;
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
