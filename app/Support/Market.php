<?php

namespace App\Support;

/**
 * Marché de destination COMMERCIALE d'origine (spécification constructeur),
 * à ne pas confondre avec le pays où le véhicule circule aujourd'hui
 * (`circulation_country`, affichage seulement, jamais comparé).
 *
 * Correspondance STRICTEMENT explicite : une compatibilité restreinte à des
 * marchés ne vaut que pour un véhicule dont le marché figure littéralement
 * dans la liste. Aucune hiérarchie n'est présumée faute de documentation
 * constructeur : GEN ne couvre ni AFR, ni MDE, ni AUS ; USA ne couvre pas
 * CAN. Une ligne qui vaut pour plusieurs marchés les énumère tous.
 *
 * `epc_region` n'est renseigné que pour les quatre catalogues EPC Toyota
 * eux-mêmes (JPN, EUR, GEN, USA) ; il est informatif et ne sert à aucune
 * comparaison.
 *
 * La table `markets` reprend exactement cette liste (un test le vérifie).
 */
final class Market
{
    /** @var array<string, array{name: string, epc_region: ?string}> */
    public const ALL = [
        'JPN' => ['name' => 'Japon', 'epc_region' => 'JPN'],
        'EUR' => ['name' => 'Europe', 'epc_region' => 'EUR'],
        'GEN' => ['name' => 'Général', 'epc_region' => 'GEN'],
        'USA' => ['name' => 'États-Unis', 'epc_region' => 'USA'],
        'CAN' => ['name' => 'Canada', 'epc_region' => null],
        'MDE' => ['name' => 'Moyen-Orient / Golfe', 'epc_region' => null],
        'AFR' => ['name' => 'Afrique', 'epc_region' => null],
        'AUS' => ['name' => 'Australie', 'epc_region' => null],
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

    /**
     * MATCH si le marché du véhicule figure littéralement dans la règle,
     * sinon MISMATCH. (AMBIGUOUS n'est plus produit : sans hiérarchie, un
     * marché connu est soit listé, soit non listé.)
     *
     * @param  list<string>  $ruleCodes
     */
    public static function compare(array $ruleCodes, string $vehicleCode): string
    {
        foreach ($ruleCodes as $regle) {
            if (self::normalize($regle) === $vehicleCode) {
                return self::MATCH;
            }
        }

        return self::MISMATCH;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }
}
