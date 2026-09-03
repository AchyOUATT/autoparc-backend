<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'user_id', 'type', 'first_name', 'last_name', 'company_name', 'tax_id',
        'id_document_type', 'id_document_number', 'driving_licence_number',
        'driving_licence_expiry', 'phone', 'phone_alt', 'email', 'address',
        'city', 'country_id', 'is_blacklisted', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type'                   => CustomerType::class,
            'driving_licence_expiry' => 'date',
            'is_blacklisted'         => 'boolean',
        ];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /** Compte self-service lie a cette fiche (optionnel, rattache par le staff ou a l'inscription). */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class);
    }

    public function partOrders()
    {
        return $this->hasMany(PartOrder::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->type === CustomerType::Individual
            ? trim("{$this->first_name} {$this->last_name}")
            : (string) $this->company_name;
    }

    /** Permis valide : condition prealable a toute location sans chauffeur. */
    public function canRent(): bool
    {
        return ! $this->is_blacklisted
            && $this->driving_licence_number !== null
            && ($this->driving_licence_expiry === null || $this->driving_licence_expiry->isFuture());
    }
}
