<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VehicleModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'name', 'slug', 'generation', 'body_type',
        'segment', 'production_start', 'production_end', 'is_active',
        'default_vehicle_type', 'default_seats', 'default_doors',
        'default_transmission', 'default_power_hp',
    ];

    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            'production_start'    => 'integer',
            'production_end'      => 'integer',
            'default_seats'       => 'integer',
            'default_doors'       => 'integer',
            'default_power_hp'    => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (VehicleModel $model) {
            $model->slug = $model->slug ?: Str::slug($model->name.'-'.$model->generation);
        });
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function trims()
    {
        return $this->hasMany(Trim::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function partFitments()
    {
        return $this->hasMany(PartFitment::class);
    }

    public function oemFitments()
    {
        return $this->hasMany(OemNumberFitment::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->brand?->name ?? '').' '.$this->name.' '.($this->generation ?? ''));
    }
}
