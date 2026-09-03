<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Equipementier : Bosch, Denso, Valeo, Sachs... */
class Manufacturer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'country_id', 'is_oem_supplier'];

    protected function casts(): array
    {
        return ['is_oem_supplier' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Manufacturer $m) {
            $m->slug = $m->slug ?: Str::slug($m->name);
        });
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function parts()
    {
        return $this->hasMany(Part::class);
    }
}
