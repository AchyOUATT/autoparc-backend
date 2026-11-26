<?php

namespace App\Support;

/**
 * Codes châssis (« model code » / « frame code ») au sens des EPC constructeur.
 *
 * Vocabulaire employé ici :
 *  - code complet   : ACV30L-AEPNKA, tel qu'imprimé sur la plaque constructeur ;
 *  - code avant tiret : ACV30L (le suffixe après le tiret décrit l'équipement
 *    et la destination, pas la structure du véhicule) ;
 *  - code de base   : ACV30, les lettres et chiffres du châssis, sans les
 *    lettres finales (L/R de direction, W de break, etc.).
 *
 * Rien n'est déduit d'un code moteur. Une famille (TRN28*, MCV3*) n'est
 * reconnue que si la compatibilité la DÉCLARE avec une étoile : TRN280 et
 * TRN285 restent deux codes distincts tant que la règle ne dit pas le
 * contraire.
 */
final class ChassisCode
{
    public const MATCH = 'match';

    /** Le véhicule est connu moins précisément que la règle (ACV30 contre ACV30L). */
    public const AMBIGUOUS = 'ambiguous';

    public const MISMATCH = 'mismatch';

    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper(preg_replace('/\s+/u', '', $code) ?? '');

        return $code === '' ? null : $code;
    }

    /**
     * Découpe une liste saisie (« TRN280/TRN285 », « MCV30, MCV36 »).
     *
     * @return list<string>
     */
    public static function parseList(?string $codes): array
    {
        if ($codes === null) {
            return [];
        }

        $parts = preg_split('#[/,;|\s]+#u', strtoupper($codes)) ?: [];

        return array_values(array_unique(array_filter(
            array_map(fn (string $p) => self::normalize($p), $parts),
            fn (?string $p) => $p !== null,
        )));
    }

    public static function beforeDash(string $code): string
    {
        return explode('-', $code, 2)[0];
    }

    /** ACV30L-AEPNKA → ACV30 ; SXV20W → SXV20 ; NZE121 → NZE121. */
    public static function base(string $code): string
    {
        $avant = self::beforeDash($code);

        return preg_match('/^([A-Z]+[0-9]+)[A-Z]*$/', $avant, $m) === 1 ? $m[1] : $avant;
    }

    /**
     * Numéro de châssis japonais (« frame number ») : NZE121-3123456.
     * Le code châssis en est le préfixe. Un VIN de 17 caractères n'est pas
     * décodé ici : sa structure varie par constructeur et par marché.
     */
    public static function fromFrameNumber(?string $vin): ?string
    {
        $vin = self::normalize($vin);
        if ($vin === null) {
            return null;
        }

        if (preg_match('/^([A-Z]{1,5}[0-9]{2,3}[A-Z]{0,2})-[0-9]{4,8}$/', $vin, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Compare le code d'un véhicule aux codes déclarés par une règle.
     *
     * @param  list<string>  $ruleCodes
     */
    public static function compare(array $ruleCodes, string $vehicleCode): string
    {
        $vehicleCode = self::normalize($vehicleCode) ?? '';
        $variantes = array_unique([$vehicleCode, self::beforeDash($vehicleCode), self::base($vehicleCode)]);
        $ambigu = false;

        foreach ($ruleCodes as $regle) {
            $regle = self::normalize($regle);
            if ($regle === null) {
                continue;
            }

            if (str_ends_with($regle, '*')) {
                $prefixe = rtrim($regle, '*');
                if ($prefixe === '') {
                    continue;
                }
                foreach ($variantes as $v) {
                    if (str_starts_with($v, $prefixe)) {
                        return self::MATCH;
                    }
                }
                // Véhicule connu par un code plus court que la famille déclarée.
                if (str_starts_with($prefixe, self::beforeDash($vehicleCode))) {
                    $ambigu = true;
                }

                continue;
            }

            if (in_array($regle, $variantes, true)) {
                return self::MATCH;
            }

            // La règle vise une variante (ACV30L) et le véhicule n'est connu
            // que par sa base (ACV30) : impossible de trancher.
            if (self::base($regle) === self::beforeDash($vehicleCode) && $regle !== self::beforeDash($vehicleCode)) {
                $ambigu = true;
            }
        }

        return $ambigu ? self::AMBIGUOUS : self::MISMATCH;
    }
}
