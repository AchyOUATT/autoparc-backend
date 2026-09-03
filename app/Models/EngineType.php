<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Type de motorisation : essence, gasoil, electrique, hybride, hybride rechargeable, GPL... */
class EngineType extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'label', 'uses_fuel', 'uses_battery'];

    protected function casts(): array
    {
        return [
            'uses_fuel'    => 'boolean',
            'uses_battery' => 'boolean',
        ];
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
