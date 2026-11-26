<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lien qualifié entre deux références OEM.
 *
 *  - manufacturer_supersession : le constructeur déclare que `to` remplace
 *    `from` (fiche officielle, EPC) ;
 *  - distributor_replacement   : le remplacement n'est attesté que par un
 *    concessionnaire, un distributeur ou un recoupement aftermarket.
 *
 * La référence d'origine reste celle que porte la pièce (`oem_number_part`).
 * Un remplacement distributeur n'autorise jamais à hériter d'une
 * compatibilité « confirmée » : au mieux « à vérifier ».
 */
class OemNumberRelation extends Model
{
    public const KIND_MANUFACTURER_SUPERSESSION = 'manufacturer_supersession';

    public const KIND_DISTRIBUTOR_REPLACEMENT = 'distributor_replacement';

    public const KINDS = [self::KIND_MANUFACTURER_SUPERSESSION, self::KIND_DISTRIBUTOR_REPLACEMENT];

    public const EVIDENCE_LEVELS = ['manufacturer_official', 'manufacturer_epc', 'dealer_catalog', 'aftermarket_crossref'];

    /** Niveaux de preuve admissibles pour chaque nature de lien. */
    public const EVIDENCE_BY_KIND = [
        self::KIND_MANUFACTURER_SUPERSESSION => ['manufacturer_official', 'manufacturer_epc'],
        self::KIND_DISTRIBUTOR_REPLACEMENT => ['dealer_catalog', 'aftermarket_crossref'],
    ];

    protected $fillable = [
        'from_oem_number_id', 'to_oem_number_id', 'kind', 'evidence_level',
        'sources', 'source_note', 'verified_on',
    ];

    protected function casts(): array
    {
        return ['verified_on' => 'date', 'sources' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (OemNumberRelation $relation) {
            if (! in_array($relation->kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("Nature de lien OEM inconnue : {$relation->kind}");
            }
            if (! in_array($relation->evidence_level, self::EVIDENCE_BY_KIND[$relation->kind], true)) {
                throw new \InvalidArgumentException(
                    "Niveau de preuve {$relation->evidence_level} incompatible avec {$relation->kind}"
                );
            }
            if ((int) $relation->from_oem_number_id === (int) $relation->to_oem_number_id) {
                throw new \InvalidArgumentException('Une référence ne peut pas se remplacer elle-même.');
            }
        });
    }

    public function from()
    {
        return $this->belongsTo(OemNumber::class, 'from_oem_number_id');
    }

    public function to()
    {
        return $this->belongsTo(OemNumber::class, 'to_oem_number_id');
    }

    public function isManufacturerSupersession(): bool
    {
        return $this->kind === self::KIND_MANUFACTURER_SUPERSESSION;
    }
}
