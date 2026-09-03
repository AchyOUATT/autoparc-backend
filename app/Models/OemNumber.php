<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Numero OEM (reference constructeur).
 * C'est le pivot metier entre le catalogue de pieces et le parc de vehicules.
 */
class OemNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'normalized_number', 'brand_id', 'label',
        'is_superseded', 'superseded_by_id',
    ];

    protected function casts(): array
    {
        return ['is_superseded' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (OemNumber $oem) {
            $oem->normalized_number = self::normalize($oem->number);
        });
    }

    /** Retire tirets, espaces et points pour une comparaison fiable. */
    public static function normalize(string $number): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function parts()
    {
        return $this->belongsToMany(Part::class, 'oem_number_part')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    /** Modeles / annees couverts par ce numero OEM. */
    public function fitments()
    {
        return $this->hasMany(OemNumberFitment::class);
    }

    /** Reference qui remplace celle-ci (evolution constructeur). */
    public function supersededBy()
    {
        return $this->belongsTo(OemNumber::class, 'superseded_by_id');
    }

    public function supersedes()
    {
        return $this->hasMany(OemNumber::class, 'superseded_by_id');
    }

    /** Suit la chaine de remplacement jusqu'a la reference courante. */
    public function currentReference(): self
    {
        $ref = $this;
        $guard = 0;

        while ($ref->is_superseded && $ref->supersededBy && $guard++ < 10) {
            $ref = $ref->supersededBy;
        }

        return $ref;
    }
}
