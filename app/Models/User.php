<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'phone', 'is_active', 'firebase_uid', 'fcm_token'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'role'              => UserRole::class,
            'is_active'         => 'boolean',
        ];
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'sold_by');
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class, 'handled_by');
    }

    /** Fiche CRM liee, si le compte a ete rattache a un client existant par le staff. */
    public function customer()
    {
        return $this->hasOne(Customer::class);
    }

    /** Vehicules personnels enregistres par ce client ("mon garage"). */
    public function ownedVehicles()
    {
        return $this->hasMany(OwnedVehicle::class);
    }
}
