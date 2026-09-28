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
        'engine_type_id', 'motorisation_id', 'drivetrain_id', 'color_id',
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
     * La vidange ne se compte pas en jours mais en kilometres : elle porte
     * km_left la ou les autres portent days_left, et c'est la seule entree dans
     * ce cas. Ce nombre n'etait auparavant lisible qu'a travers le libelle
     * detail, que la tache de rappel relisait par expression reguliere — une
     * reformulation du libelle suffisait donc a eteindre le rappel.
     *
     * @return array<int, array{kind:string,label:string,due_on:?string,days_left:?int,km_left:?int,overdue:bool,detail:?string}>
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
                'km_left'   => null,
                'overdue'   => $daysLeft < 0,
                'detail'    => null,
            ];
        }

        // Vidange : suivi kilometrique, donc tributaire d'un kilometrage tenu
        // a jour par le proprietaire. Sans les trois valeurs, pas de rappel.
        $remaining = $this->kilometresAvantVidange();

        if ($remaining !== null) {
            $items[] = [
                'kind'      => 'service',
                'label'     => 'Vidange',
                'due_on'    => null,
                'days_left' => null,
                'km_left'   => $remaining,
                'overdue'   => $remaining <= 0,
                'detail'    => $remaining > 0
                    ? "Dans {$remaining} km"
                    : 'Depassee de ' . abs($remaining) . ' km',
            ];
        }

        usort($items, fn ($a, $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));

        return $items;
    }

    /**
     * Kilometres restants avant la prochaine vidange, negatif si elle est
     * depassee. Null quand l'une des trois valeurs manque.
     *
     * Ce nombre existait deja, mais seulement a l'interieur de la chaine
     * « Dans 4000 km » construite par deadlines() — et la tache de rappel le
     * relisait par une expression reguliere sur cette chaine. Toute
     * reformulation du libelle cassait donc silencieusement le declenchement du
     * rappel de vidange. Le nombre se lit desormais ici, et le libelle n'est
     * plus qu'un affichage.
     */
    public function kilometresAvantVidange(): ?int
    {
        if (! $this->service_interval_km || $this->last_service_mileage_km === null || $this->mileage_km === null) {
            return null;
        }

        return (int) $this->service_interval_km - ((int) $this->mileage_km - (int) $this->last_service_mileage_km);
    }

    /**
     * Code de motorisation retenu : celui saisi, sinon celui que la cote
     * officielle laisse deduire.
     *
     * Deux sources parce que les deux existent en pratique : le proprietaire
     * choisit un type de moteur a la saisie, ou choisit une motorisation pour
     * connaitre sa consommation — et l'un n'implique pas l'autre.
     */
    public function codeMotorisation(): ?string
    {
        $type = $this->engineType ?? $this->trim?->defaultEngineType;

        if ($type?->code !== null) {
            return $type->code;
        }

        // Codes carburant des sources officielles : D pour le gasoil, X et Z
        // pour l'essence ordinaire et super, E pour l'ethanol — dont le moteur
        // reste un moteur a essence.
        return match ($this->motorisation?->fuel_code) {
            'D'            => 'diesel',
            'X', 'Z', 'E'  => 'petrol',
            default        => null,
        };
    }

    public function libelleMotorisation(): ?string
    {
        return ($this->engineType ?? $this->trim?->defaultEngineType)?->label;
    }

    /**
     * Carrosserie du vehicule, en minuscules : berline, suv, pick-up...
     *
     * Elle n'est pas portee par le vehicule du garage mais par le modele du
     * catalogue, dans une colonne libre dont la casse varie d'un seeder a
     * l'autre (« SUV » ici, « suv » la) — d'ou le passage en minuscules, deja
     * fait ailleurs dans le projet. Elle peut rester nulle : un point de
     * controle qui en depend ne doit alors simplement pas apparaitre.
     */
    public function carrosserie(): ?string
    {
        $type = $this->vehicleModel?->body_type;

        return $type === null || trim($type) === '' ? null : strtolower(trim($type));
    }

    public function checks()
    {
        return $this->hasMany(VehicleCheck::class);
    }

    /**
     * Le dernier controle en date.
     *
     * latestOfMany plutot qu'un orderBy sur la relation : l'historique du garage
     * affiche une ligne par vehicule, et sans cela la liste ferait une requete
     * par vehicule pour trouver son dernier passage.
     */
    public function dernierControle()
    {
        return $this->hasOne(VehicleCheck::class)->latestOfMany('performed_at');
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

    /**
     * La motorisation choisie par le proprietaire, et sa cote officielle.
     *
     * Nulle tant qu'il ne l'a pas choisie : le catalogue connait le modele,
     * mais rien ne dit lequel des moteurs disponibles se trouve sous le capot.
     */
    public function motorisation()
    {
        return $this->belongsTo(Motorisation::class);
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
