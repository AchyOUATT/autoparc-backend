<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleDealType;
use App\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'vin', 'registration_status', 'vehicle_type', 'body_style',
        'brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id',
        'manufacturing_year', 'transmission', 'gear_count', 'engine_displacement_cc',
        'power_hp', 'power_kw', 'torque_nm', 'cylinders', 'seats', 'doors', 'engine_code',
        'consumption_urban', 'consumption_extra_urban', 'consumption_combined',
        'electric_consumption_kwh', 'battery_capacity_kwh', 'electric_range_km',
        'co2_g_km', 'fuel_tank_liters',
        'condition', 'status', 'availability',
        'purchase_price', 'sale_price', 'rental_daily_rate', 'rental_weekly_rate',
        'rental_monthly_rate', 'rental_deposit', 'rental_mileage_limit_per_day',
        'currency', 'price_negotiable', 'deal_type', 'site', 'location_id', 'description',
        'created_by', 'published_at', 'partner_id',
    ];

    protected function casts(): array
    {
        return [
            'registration_status'      => RegistrationStatus::class,
            'status'                   => VehicleStatus::class,
            'availability'             => VehicleAvailability::class,
            'condition'                => VehicleCondition::class,
            'transmission'             => Transmission::class,
            'manufacturing_year'       => 'integer',
            'consumption_urban'        => 'decimal:2',
            'consumption_extra_urban'  => 'decimal:2',
            'consumption_combined'     => 'decimal:2',
            'electric_consumption_kwh' => 'decimal:2',
            'battery_capacity_kwh'     => 'decimal:2',
            'purchase_price'           => 'decimal:2',
            'sale_price'               => 'decimal:2',
            'rental_daily_rate'        => 'decimal:2',
            'rental_weekly_rate'       => 'decimal:2',
            'rental_monthly_rate'      => 'decimal:2',
            'rental_deposit'           => 'decimal:2',
            'price_negotiable'         => 'boolean',
            'deal_type'                => VehicleDealType::class,
            'published_at'             => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |--------------------------------------------------------------------*/

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
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

    public function features()
    {
        return $this->belongsToMany(Feature::class);
    }

    /** Bloc import : renseigne uniquement si le vehicule n'est pas immatricule. */
    public function importDetail()
    {
        return $this->hasOne(VehicleImportDetail::class);
    }

    /** Bloc immatriculation : renseigne uniquement si le vehicule est deja immatricule. */
    public function registrationDetail()
    {
        return $this->hasOne(VehicleRegistrationDetail::class);
    }

    public function faults()
    {
        return $this->hasMany(VehicleFault::class);
    }

    public function openFaults()
    {
        return $this->faults()->whereNotIn('status', ['repaired', 'wont_fix']);
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('position');
    }

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class);
    }

    public function partOrders()
    {
        return $this->hasMany(PartOrder::class);
    }

    /** Pieces demontees sur ce vehicule (cas d'un vehicule de casse). */
    public function donatedParts()
    {
        return $this->hasMany(Part::class, 'donor_vehicle_id');
    }

    /* ---------------------------------------------------------------------
     | Accesseurs
     |--------------------------------------------------------------------*/

    public function isRegistered(): bool
    {
        return $this->registration_status === RegistrationStatus::Registered;
    }

    public function isImported(): bool
    {
        return $this->registration_status === RegistrationStatus::Unregistered;
    }

    /** Pays de provenance (import) ou pays d'immatriculation selon le cas. */
    public function getOriginCountryAttribute(): ?Country
    {
        return $this->isImported()
            ? $this->importDetail?->originCountry
            : $this->registrationDetail?->registrationCountry;
    }

    public function getMileageKmAttribute(): ?int
    {
        return $this->isRegistered()
            ? $this->registrationDetail?->mileage_km
            : $this->importDetail?->odometer_at_import_km;
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

    /** Indice d'etat mecanique base sur les pannes ouvertes (0 = sain). */
    public function getFaultScoreAttribute(): int
    {
        return $this->faults
            ->filter(fn (VehicleFault $f) => $f->status->isOpen())
            ->sum(fn (VehicleFault $f) => $f->severity->weight());
    }

    public function isAvailableForRentBetween(string $start, string $end): bool
    {
        if (! $this->availability->allowsRent()) {
            return false;
        }

        return ! $this->rentals()
            ->whereIn('status', ['reserved', 'ongoing', 'overdue'])
            ->where('start_at', '<', $end)
            ->where('expected_return_at', '>', $start)
            ->exists();
    }

    /* ---------------------------------------------------------------------
     | Scopes de filtrage (utilises par l'API de recherche)
     |--------------------------------------------------------------------*/

    public function scopeRegistered(Builder $q): Builder
    {
        return $q->where('registration_status', RegistrationStatus::Registered);
    }

    public function scopeUnregistered(Builder $q): Builder
    {
        return $q->where('registration_status', RegistrationStatus::Unregistered);
    }

    public function scopeInStock(Builder $q): Builder
    {
        return $q->where('status', VehicleStatus::InStock);
    }

    public function scopeForSale(Builder $q): Builder
    {
        return $q->whereIn('availability', [VehicleAvailability::Sale, VehicleAvailability::Both]);
    }

    public function scopeForRent(Builder $q): Builder
    {
        return $q->whereIn('availability', [VehicleAvailability::Rent, VehicleAvailability::Both]);
    }

    /** Filtre par pays de provenance (vehicules non immatricules). */
    public function scopeFromCountry(Builder $q, int $countryId): Builder
    {
        return $q->whereHas('importDetail', fn ($d) => $d->where('origin_country_id', $countryId));
    }

    public function scopeWithoutOpenFaults(Builder $q): Builder
    {
        return $q->whereDoesntHave('faults', fn ($f) => $f->whereNotIn('status', ['repaired', 'wont_fix']));
    }

    public function scopeWithFaultCategory(Builder $q, string $category): Builder
    {
        return $q->whereHas('faults', fn ($f) => $f->where('category', $category));
    }

    // ── « Full option » ──────────────────────────────────────────────────────
    //
    // Terme du marche de l'import, pas une finition : aucun constructeur ne
    // fabrique une version « full option ». C'est une appreciation du vendeur,
    // souvent genereuse. On la calcule donc a partir des equipements reellement
    // rattaches au vehicule, pour que le badge soit verifiable par l'acheteur
    // juste en dessous, dans la liste des equipements.

    /** La dotation standard (cric, gilet, triangle) equipe tout le parc et ne departage rien. */
    private const OPTION_CATEGORIES = ['confort', 'securite', 'multimedia', 'aide_conduite', 'autre'];

    /**
     * Seuil d'equipements hors dotation.
     *
     * Le parc en compte de 7 a 25 : a 20, le badge distingue reellement (environ
     * un vehicule sur dix) au lieu de recompenser la moyenne. Un badge qui
     * promet « tout » et se pose sur la moitie du parc ne vaut rien pour
     * l'acheteur — quitte a l'abaisser plus tard, mieux vaut commencer strict.
     */
    private const FULL_OPTION_MIN = 20;

    /** Familles dont au moins un equipement est exige, pour eviter un total gonfle par un seul rayon. */
    private const FULL_OPTION_REQUIRED = ['confort', 'multimedia', 'aide_conduite'];

    /**
     * Nombre d'equipements hors dotation standard.
     *
     * Suppose la relation `features` chargee : les accesseurs ne sont exposes
     * que dans ce cas, la liste du catalogue ne chargeant pas les equipements.
     */
    public function getOptionalFeatureCountAttribute(): int
    {
        return $this->features
            ->whereIn('category', self::OPTION_CATEGORIES)
            ->count();
    }

    public function getIsFullOptionAttribute(): bool
    {
        $options = $this->features->whereIn('category', self::OPTION_CATEGORIES);

        if ($options->count() < self::FULL_OPTION_MIN) {
            return false;
        }

        foreach (self::FULL_OPTION_REQUIRED as $category) {
            if ($options->where('category', $category)->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (blank($term)) {
            return $q;
        }

        return $q->where(function (Builder $sub) use ($term) {
            $sub->where('reference', 'like', "%{$term}%")
                ->orWhere('vin', 'like', "%{$term}%")
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$term}%"))
                ->orWhereHas('vehicleModel', fn ($m) => $m->where('name', 'like', "%{$term}%"))
                ->orWhereHas('registrationDetail', fn ($r) => $r->where('plate_number', 'like', "%{$term}%"));
        });
    }
}
