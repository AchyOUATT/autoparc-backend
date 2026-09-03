<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = ['iso2', 'iso3', 'name', 'nationality', 'currency_code', 'is_common_origin'];

    protected function casts(): array
    {
        return ['is_common_origin' => 'boolean'];
    }

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    /** Vehicules importes depuis ce pays (pays de provenance). */
    public function importedVehicles()
    {
        return $this->hasManyThrough(
            Vehicle::class,
            VehicleImportDetail::class,
            'origin_country_id',
            'id',
            'id',
            'vehicle_id'
        );
    }
}
