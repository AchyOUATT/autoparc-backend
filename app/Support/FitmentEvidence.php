<?php

namespace App\Support;

use App\Enums\FitmentSource;

/**
 * Niveau de preuve d'une COMPATIBILITE (ce véhicule reçoit cette pièce).
 *
 * À ne pas confondre avec la preuve d'un REMPLACEMENT de référence
 * (oem_number_relations.evidence_level) : qu'une référence en remplace une
 * autre ne dit rien des véhicules qui la reçoivent.
 *
 * Preuve suffisante pour « confirmé » :
 *  - ligne d'origine `generated` (FitmentPlanner) : jamais suffisante ;
 *  - ligne `declared` sans niveau (lignes historiques, preuve dans `notes`) :
 *    suffisante, pour ne rien changer aux 143 lignes en production ;
 *  - ligne `declared` avec niveau : suffisante si constructeur (fiche
 *    officielle ou EPC) ou catalogue concessionnaire de la marque ;
 *    insuffisante si recoupement aftermarket ou non vérifiée.
 */
final class FitmentEvidence
{
    public const MANUFACTURER_OFFICIAL = 'manufacturer_official';

    public const MANUFACTURER_EPC = 'manufacturer_epc';

    public const DEALER_CATALOG = 'dealer_catalog';

    public const AFTERMARKET_CROSSREF = 'aftermarket_crossref';

    public const UNVERIFIED = 'unverified';

    public const LEVELS = [
        self::MANUFACTURER_OFFICIAL, self::MANUFACTURER_EPC, self::DEALER_CATALOG,
        self::AFTERMARKET_CROSSREF, self::UNVERIFIED,
    ];

    public const SUFFICIENT = [self::MANUFACTURER_OFFICIAL, self::MANUFACTURER_EPC, self::DEALER_CATALOG];

    public static function isSufficient(?string $level, FitmentSource|string|null $source = FitmentSource::Declared): bool
    {
        $source = $source instanceof FitmentSource ? $source : FitmentSource::tryFrom((string) $source);
        if ($source === FitmentSource::Generated) {
            return false;
        }

        return $level === null || in_array($level, self::SUFFICIENT, true);
    }
}
