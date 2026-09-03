<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'country_id', 'logo_path', 'oem_prefix', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Brand $brand) {
            $brand->slug = $brand->slug ?: Str::slug($brand->name);
        });
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function vehicleModels()
    {
        return $this->hasMany(VehicleModel::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function oemNumbers()
    {
        return $this->hasMany(OemNumber::class);
    }
}
