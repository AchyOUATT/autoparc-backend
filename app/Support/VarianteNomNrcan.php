<?php

namespace App\Support;

/**
 * Ce que le nom de modèle de Ressources naturelles Canada dit de la version.
 *
 * La source n'a pas de colonne pour la motricité, la suralimentation ou la
 * désactivation de cylindres : elle les écrit dans le nom — « CX-5 4WD »,
 * « CX-5 Turbo 4WD », « CX-5 (Cylinder Deactivation) », « Ram 1500 (MDS) »,
 * « Silverado 4WD (Without DFM) ». Sans les lire, cinq CX-5 2,5 l automatiques
 * restent à égalité pour n'importe quel CX-5.
 *
 * Règle unique : on ne retient que ce que le nom ÉCRIT. Un nom muet donne
 * null, jamais « traction » ou « atmosphérique » : la source ne documente pas
 * qu'une version sans mention est à deux roues motrices, et un null n'exclut
 * aucune cote, alors qu'une valeur déduite à tort en cacherait une juste.
 * Deux mentions contradictoires donnent aussi null.
 */
final class VarianteNomNrcan
{
    /** Codes de motricité écrits par la source, ramenés aux codes du référentiel. */
    private const MOTRICITE = [
        // Quatre roues motrices, avec ou sans boîte de transfert.
        '/(?<![A-Za-z0-9])(?:4WD|4X4|4x4)(?![A-Za-z0-9])/' => '4WD',
        // Intégrale, y compris les appellations de marque qui ne désignent
        // qu'une transmission intégrale.
        '/(?<![A-Za-z0-9])(?:SH-AWD|e-AWD|AWD)(?![A-Za-z0-9])/' => 'AWD',
        '/(?<![A-Za-z0-9])4MATIC\+?(?![A-Za-z0-9])/i' => 'AWD',
        '/(?<![A-Za-z0-9])xDrive/' => 'AWD',
        '/(?<![A-Za-z0-9])quattro(?![A-Za-z0-9])/i' => 'AWD',
        '/(?<![A-Za-z0-9])4MOTION(?![A-Za-z0-9])/i' => 'AWD',
        '/(?<![A-Za-z0-9])ALL4(?![A-Za-z0-9])/' => 'AWD',
        // Deux roues motrices, quand la source le dit.
        '/(?<![A-Za-z0-9])(?:2WD|4X2|4x2)(?![A-Za-z0-9])/' => '2WD',
        '/(?<![A-Za-z0-9])FWD(?![A-Za-z0-9])/' => 'FWD',
        '/(?<![A-Za-z0-9])RWD(?![A-Za-z0-9])/' => 'RWD',
        // « sDrive » est volontairement absent : chez BMW et MINI il désigne
        // la propulsion sur certaines générations et la traction sur d'autres
        // (X1 F48, X2). Le nom seul ne tranche pas.
    ];

    private const TURBO = [
        '/(?<![A-Za-z])turbo(?![A-Za-z])/i',   // Turbo, TURBO, « Turbo 4WD »
        '/turbodiesel/i',
        '/bi-?turbo/i',
        '/(?<![A-Za-z0-9])TDI(?![A-Za-z0-9])/', // Turbocharged Direct Injection
        '/ecoboost/i',                          // marque Ford du moteur turbo
    ];

    // « Kompressor » : appellation Mercedes du compresseur mécanique.
    private const COMPRESSEUR = '/supercharged|kompressor/i';

    private const SANS_DESACTIVATION = '/(?<![A-Za-z])(?:without|no)\s+(?:AFM|DFM|MDS|CDA|cylinder\s+deactivation)(?![A-Za-z])/i';

    private const DESACTIVATION = '/cylinder\s+deactivation|(?<![A-Za-z])(?:CDA|MDS|AFM|DFM)(?![A-Za-z])/i';

    /**
     * @return array{drivetrain_code: ?string, aspiration: ?string, cylinder_deactivation: ?bool}
     */
    public static function lire(?string $nom, ?string $carburant = null): array
    {
        $nom = trim((string) $nom);

        return [
            'drivetrain_code' => self::motricite($nom),
            'aspiration' => self::suralimentation($nom, $carburant),
            'cylinder_deactivation' => self::desactivation($nom),
        ];
    }

    private static function motricite(string $nom): ?string
    {
        $trouves = [];
        foreach (self::MOTRICITE as $motif => $code) {
            if (preg_match($motif, $nom)) {
                $trouves[$code] = true;
            }
        }

        // « 4WD » et « AWD » ensemble, ou « 4X4 » et « 2WD » : la source se
        // contredit ou décrit deux versions, on ne choisit pas.
        return count($trouves) === 1 ? array_key_first($trouves) : null;
    }

    private static function suralimentation(string $nom, ?string $carburant): ?string
    {
        // Une électrique peut s'appeler « Taycan Turbo » : c'est un nom de
        // finition, il n'y a pas de turbocompresseur.
        if (strtoupper(trim((string) $carburant)) === 'B') {
            return null;
        }

        $turbo = false;
        foreach (self::TURBO as $motif) {
            if (preg_match($motif, $nom)) {
                $turbo = true;
                break;
            }
        }
        $compresseur = (bool) preg_match(self::COMPRESSEUR, $nom);

        return match (true) {
            $turbo && $compresseur => null,
            $turbo => 'turbo',
            $compresseur => 'supercharged',
            default => null,
        };
    }

    private static function desactivation(string $nom): ?bool
    {
        // « Without DFM », « No DFM » : la source dit explicitement que cette
        // version n'a pas le système — c'est une information, pas un vide.
        if (preg_match(self::SANS_DESACTIVATION, $nom)) {
            return false;
        }

        return preg_match(self::DESACTIVATION, $nom) ? true : null;
    }

    /**
     * Deux motricités sont-elles compatibles ? 4WD et AWD sont une même
     * famille — la source écrit « 4WD » pour l'i-Activ AWD de Mazda —, et
     * « 2WD » couvre la traction comme la propulsion.
     */
    public static function motricitesCompatibles(string $a, string $b): bool
    {
        $a = strtoupper(trim($a));
        $b = strtoupper(trim($b));
        if ($a === $b) {
            return true;
        }
        $famille = static fn (string $code) => match ($code) {
            'AWD', '4WD' => 'toutes',
            'FWD', 'RWD', '2WD' => 'deux',
            default => $code,
        };
        if ($famille($a) !== $famille($b)) {
            return false;
        }

        // Dans la famille deux roues, seule la mention générique « 2WD » est
        // compatible avec les deux ; traction et propulsion se contredisent.
        return $famille($a) === 'toutes' || $a === '2WD' || $b === '2WD';
    }
}
