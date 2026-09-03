<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Donnees propres a un vehicule DEJA IMMATRICULE. */
class VehicleRegistrationDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'plate_number', 'registration_country_id', 'registration_certificate_no',
        'first_registration_date', 'last_transfer_date', 'mileage_km', 'previous_owners_count',
        'technical_inspection_expiry', 'insurance_expiry', 'insurance_company',
        'service_book_available', 'last_service_date', 'last_service_mileage_km',
        'has_accident_history', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'first_registration_date'     => 'date',
            'last_transfer_date'          => 'date',
            'technical_inspection_expiry' => 'date',
            'insurance_expiry'            => 'date',
            'last_service_date'           => 'date',
            'service_book_available'      => 'boolean',
            'has_accident_history'        => 'boolean',
            'mileage_km'                  => 'integer',
        ];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function registrationCountry()
    {
        return $this->belongsTo(Country::class, 'registration_country_id');
    }

    public function getVehicleAgeYearsAttribute(): ?int
    {
        return $this->first_registration_date?->diffInYears(now());
    }

    public function isInspectionExpired(): bool
    {
        return $this->technical_inspection_expiry !== null
            && $this->technical_inspection_expiry->isPast();
    }
}
