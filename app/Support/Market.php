<?php

namespace App\Support;

/**
 * Marchés de destination structurés.
 *
 * Les quatre catalogues de l'EPC Toyota sont Japon (JPN), Europe (EUR),
 * Général (GEN) et USA/Canada (USA). Le catalogue Général couvre les
 * destinations hors Japon, Europe et Amérique du Nord : Moyen-Orient,
 * Afrique, Australie, etc. Ces destinations sont donc rattachées à GEN.
 *
 * Règle de comparaison (voir docs/proposition-moteur-compatibilite-chassis-marche.md) :
 *  - le marché du véhicule, ou l'un de ses parents, figure dans la règle → satisfait ;
 *  - le véhicule est connu par un parent d'un marché de la règle (GEN contre
 *    AFR) → à vérifier ;
 *  - sinon → la règle est exclue.
 *
 * La table `markets` reprend exactement cette liste (un test le vérifie).
 */
final class Market
{
    /** @var array<string, array{name: string, epc_region: string, parent: ?string}> */
    public const ALL = [
        'JPN' => ['name' => 'Japon', 'epc_region' => 'JPN', 'parent' => null],
        'EUR' => ['name' => 'Europe', 'epc_region' => 'EUR', 'parent' => null],
        'GEN' => ['name' => 'Général', 'epc_region' => 'GEN', 'parent' => null],
        'USA' => ['name' => 'États-Unis', 'epc_region' => 'USA', 'parent' => null],
        'CAN' => ['name' => 'Canada', 'epc_region' => 'USA', 'parent' => null],
        'MDE' => ['name' => 'Moyen-Orient / Golfe', 'epc_region' => 'GEN', 'parent' => 'GEN'],
        'AFR' => ['name' => 'Afrique', 'epc_region' => 'GEN', 'parent' => 'GEN'],
        'AUS' => ['name' => 'Australie', 'epc_region' => 'GEN', 'parent' => 'GEN'],
    ];

    /** Codes déjà rencontrés dans la base (consommations, notes) ou usuels. */
    private const ALIASES = [
        'JP' => 'JPN', 'JDM' => 'JPN', 'JAPAN' => 'JPN',
        'EU' => 'EUR', 'EUROPE' => 'EUR',
        'GR' => 'GEN', 'GENERAL' => 'GEN',
        'US' => 'USA', 'USDM' => 'USA',
        'CA' => 'CAN', 'CANADA' => 'CAN',
        'ME' => 'MDE', 'GCC' => 'MDE', 'MIDDLEEAST' => 'MDE', 'MIDDLE_EAST' => 'MDE', 'MIDDLE-EAST' => 'MDE',
        'AF' => 'AFR', 'AFRICA' => 'AFR',
        'AU' => 'AUS', 'AUSTRALIA' => 'AUS',
    ];

    public const MATCH = 'match';

    public const AMBIGUOUS = 'ambiguous';

    public const MISMATCH = 'mismatch';

    /** Code structuré, ou null si inconnu : un code inconnu ne se compare pas. */
    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper(preg_replace('/\s+/u', '', $code) ?? '');
        if ($code === '') {
            return null;
        }

        $code = self::ALIASES[$code] ?? $code;

        return isset(self::ALL[$code]) ? $code : null;
    }

    /** @return list<string> le code et ses parents. */
    public static function lineage(string $code): array
    {
        $chaine = [];
        $guard = 0;
        while ($code !== null && isset(self::ALL[$code]) && $guard++ < 5) {
            $chaine[] = $code;
            $code = self::ALL[$code]['parent'];
        }

        return $chaine;
    }

    /** @param list<string> $ruleCodes */
    public static function compare(array $ruleCodes, string $vehicleCode): string
    {
        $lignee = self::lineage($vehicleCode);
        $ambigu = false;

        foreach ($ruleCodes as $regle) {
            $regle = self::normalize($regle);
            if ($regle === null) {
                continue;
            }
            if (in_array($regle, $lignee, true)) {
                return self::MATCH;
            }
            if (in_array($vehicleCode, self::lineage($regle), true)) {
                $ambigu = true;
            }
        }

        return $ambigu ? self::AMBIGUOUS : self::MISMATCH;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }
}
