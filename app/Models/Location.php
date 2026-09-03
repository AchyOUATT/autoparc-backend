<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'name', 'city', 'address', 'phone', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────────────

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(Accessory::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInCity($query, string $city)
    {
        return $query->where('city', $city);
    }
}
