<?php

namespace App\Support;

/** Normalisation commune des critères restrictifs reçus ou stockés. */
final class FitmentRestrictionInput
{
    /**
     * Codes marché normalisés, sans doublon ; null si la liste est vide.
     * Un code inconnu est conservé tel quel pour que la validation le refuse
     * au lieu de l'effacer en silence.
     *
     * @return list<string>|null
     */
    public static function marketCodes(mixed $codes): ?array
    {
        if ($codes === null || $codes === '' || $codes === []) {
            return null;
        }
        if (is_string($codes)) {
            $decode = json_decode($codes, true);
            $codes = is_array($decode) ? $decode : (preg_split('/[\s,;\/]+/', $codes) ?: []);
        }
        if (! is_array($codes)) {
            return null;
        }

        $sortie = [];
        foreach ($codes as $code) {
            if (! is_string($code) || trim($code) === '') {
                continue;
            }
            $sortie[] = Market::normalize($code) ?? strtoupper(trim($code));
        }
        $sortie = array_values(array_unique($sortie));

        return $sortie === [] ? null : $sortie;
    }
}
