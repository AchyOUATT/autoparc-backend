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

    /** Liens qualifies sortants : cette reference est remplacee par... */
    public function replacementRelations()
    {
        return $this->hasMany(OemNumberRelation::class, 'from_oem_number_id');
    }

    /** Liens qualifies entrants : cette reference remplace... */
    public function replacedRelations()
    {
        return $this->hasMany(OemNumberRelation::class, 'to_oem_number_id');
    }

    /**
     * Remplacee par une autre reference, quelle que soit la preuve : drapeau
     * historique ou lien qualifie.
     */
    public function isReplaced(): bool
    {
        if ($this->is_superseded || $this->superseded_by_id !== null) {
            return true;
        }

        return $this->relationLoaded('replacementRelations')
            ? $this->replacementRelations->isNotEmpty()
            : $this->replacementRelations()->exists();
    }

    /**
     * Reference courante en ne suivant QUE les supersessions constructeur
     * qualifiees. Un remplacement atteste par un distributeur n'est pas suivi.
     */
    public function currentManufacturerReference(): self
    {
        $ref = $this;
        $vues = [$ref->id => true];

        for ($guard = 0; $guard < 10; $guard++) {
            $lien = $ref->replacementRelations()
                ->where('kind', OemNumberRelation::KIND_MANUFACTURER_SUPERSESSION)
                ->with('to')
                ->first();
            if (! $lien || ! $lien->to || isset($vues[$lien->to->id])) {
                break;
            }
            $ref = $lien->to;
            $vues[$ref->id] = true;
        }

        return $ref;
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
