<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Décode un VIN (17 chars ISO 3779) et résout les correspondances en base locale.
 *
 * Chemin :
 *   VIN → char 10 (année, local, sans réseau)
 *       + NHTSA vPIC DecodeVinValues (marque, modèle, motorisation, transmission…)
 *       → lookup brand_id / vehicle_model_id / engine_type_id / drivetrain_id
 *
 * Utilisé par :
 *   GET /api/catalog/vin-decode/{vin}
 *   → pré-remplit le formulaire Flutter "Ajouter au garage" (OwnedVehicle)
 *   → alimente partsForCriteria() sans que le client saisisse chaque champ
 */
class VinDecodeService
{
    // -----------------------------------------------------------------------
    // Tables de décodage statiques
    // -----------------------------------------------------------------------

    /** Translitération alphanumériques → valeur (I, O, Q absents du VIN ISO). */
    private const TRANSLITERATION = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5,
        'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5,
        'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5,
        'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    /** Poids par position (1-indexé → tableau 0-indexé). */
    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Char 10 → années candidates.
     * Les lettres ont deux candidats (cycle 30 ans depuis 1980) ;
     * les chiffres 1-9 couvrent 2001-2009 (pas de cycle connu).
     * Ordre : année la plus récente en premier.
     */
    private const YEAR_MAP = [
        'A' => [2010, 1980], 'B' => [2011, 1981], 'C' => [2012, 1982],
        'D' => [2013, 1983], 'E' => [2014, 1984], 'F' => [2015, 1985],
        'G' => [2016, 1986], 'H' => [2017, 1987], 'J' => [2018, 1988],
        'K' => [2019, 1989], 'L' => [2020, 1990], 'M' => [2021, 1991],
        'N' => [2022, 1992], 'P' => [2023, 1993], 'R' => [2024, 1994],
        'S' => [2025, 1995], 'T' => [2026, 1996], 'V' => [2027, 1997],
        'W' => [2028, 1998], 'X' => [2029, 1999], 'Y' => [2030, 2000],
        '1' => [2001], '2' => [2002], '3' => [2003], '4' => [2004],
        '5' => [2005], '6' => [2006], '7' => [2007], '8' => [2008], '9' => [2009],
    ];

    // -----------------------------------------------------------------------
    // Point d'entrée
    // -----------------------------------------------------------------------

    /**
     * Décode le VIN et retourne un tableau structuré.
     *
     * @return array{
     *   vin:             string,
     *   valid:           bool,
     *   year_candidates: int[],
     *   engine_code:     string|null,
     *   nhtsa:           array|null,
     *   brand:           array|null,
     *   vehicle_model:   array|null,
     *   engine_type:     array|null,
     *   drivetrain:      array|null,
     *   confidence:      string,
     *   warnings:        string[],
     * }
     */
    public function decode(string $vin): array
    {
        $vin      = strtoupper(trim($vin));
        $warnings = [];

        // 1. Validation structurelle (longueur + charset ISO 3779)
        if (! $this->isValidFormat($vin)) {
            return $this->invalidResponse($vin, 'VIN invalide : 17 caractères alphanumériques requis (I, O, Q interdits).');
        }

        // 2. Vérification check digit (position 9) — non-bloquant :
        //    certains VIN étrangers (JDM notamment) ne respectent pas l'algo US.
        if (! $this->isCheckDigitValid($vin)) {
            $warnings[] = 'Check digit (position 9) invalide — VIN peut-être erroné ou non conforme ISO/US (fréquent sur véhicules JDM).';
        }

        // 3. Décodage local (aucun réseau requis)
        $yearCandidates = $this->decodeYear($vin);
        $engineCode     = $this->decodeEngineCode($vin);

        // 4. Appel NHTSA vPIC (peut échouer silencieusement)
        $nhtsa = $this->callNhtsa($vin);

        if ($nhtsa === null) {
            $warnings[] = 'API NHTSA inaccessible — décodage partiel (année locale uniquement).';
        }

        // 5. Fusion année : NHTSA confirme ou corrige le décodage local
        $nhtsaYear = isset($nhtsa['ModelYear']) && $nhtsa['ModelYear'] !== ''
            ? (int) $nhtsa['ModelYear']
            : null;

        if ($nhtsaYear) {
            if (! in_array($nhtsaYear, $yearCandidates, true)) {
                $warnings[] = "Année NHTSA ({$nhtsaYear}) diverge du décodage local — à vérifier.";
            }
            // NHTSA en tête car plus fiable que le décodage char 10 pour les VIN >2009
            $yearCandidates = array_values(array_unique(array_merge([$nhtsaYear], $yearCandidates)));
        }

        // 6. Résolution en base
        $nhtsaMake  = $nhtsa['Make']  ?? null;
        $nhtsaModel = $nhtsa['Model'] ?? null;

        $brand      = $nhtsaMake               ? $this->resolveBrand($nhtsaMake)                  : null;
        $model      = ($brand && $nhtsaModel)  ? $this->resolveModel($brand['id'], $nhtsaModel)   : null;
        $engineType = $this->resolveEngineType($nhtsa);
        $drivetrain = $this->resolveDrivetrain($nhtsa);

        // 7. Résumé NHTSA brut (utile pour pré-remplir des champs hors-base côté Flutter)
        $nhtsaSummary = $nhtsa ? [
            'make'               => $nhtsaMake,
            'model'              => $nhtsaModel,
            'year'               => $nhtsaYear,
            'body_class'         => $nhtsa['BodyClass']          ?? null,
            'fuel_type'          => $nhtsa['FuelTypePrimary']    ?? null,
            'drive_type'         => $nhtsa['DriveType']          ?? null,
            'displacement_cc'    => isset($nhtsa['DisplacementCC'])  && $nhtsa['DisplacementCC']  !== '' ? (int) $nhtsa['DisplacementCC']  : null,
            'cylinders'          => isset($nhtsa['EngineCylinders']) && $nhtsa['EngineCylinders'] !== '' ? (int) $nhtsa['EngineCylinders'] : null,
            'transmission_style' => $nhtsa['TransmissionStyle']  ?? null,
            'engine_code_nhtsa'  => $nhtsa['EngineCode']         ?? null,
            'error_code'         => $nhtsa['ErrorCode']          ?? null,
            'error_text'         => ($nhtsa['ErrorText'] ?? '') !== '0' ? ($nhtsa['ErrorText'] ?? null) : null,
        ] : null;

        return [
            'vin'             => $vin,
            'valid'           => true,
            'year_candidates' => $yearCandidates,
            'engine_code'     => $engineCode,
            'nhtsa'           => $nhtsaSummary,
            'brand'           => $brand,
            'vehicle_model'   => $model,
            'engine_type'     => $engineType,
            'drivetrain'      => $drivetrain,
            'confidence'      => $this->computeConfidence($brand, $model, $engineType, $drivetrain, $nhtsa),
            'warnings'        => $warnings,
        ];
    }

    // -----------------------------------------------------------------------
    // Validation VIN
    // -----------------------------------------------------------------------

    /** Format ISO 3779 : 17 chars, alphabet VIN (I, O, Q exclus). */
    private function isValidFormat(string $vin): bool
    {
        return strlen($vin) === 17 && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $vin);
    }

    /**
     * Vérifie le check digit (char 9) selon l'algorithme NHTSA.
     * Non-bloquant : un échec génère un warning, pas une erreur.
     */
    private function isCheckDigitValid(string $vin): bool
    {
        $sum = 0;

        foreach (str_split($vin) as $i => $char) {
            $value = is_numeric($char)
                ? (int) $char
                : (self::TRANSLITERATION[$char] ?? 0);

            $sum += $value * self::WEIGHTS[$i];
        }

        $remainder = $sum % 11;
        $expected  = $remainder === 10 ? 'X' : (string) $remainder;

        return $vin[8] === $expected;
    }

    // -----------------------------------------------------------------------
    // Décodage local (sans réseau)
    // -----------------------------------------------------------------------

    /**
     * Char 10 (index 9) → années candidates, ordre décroissant (plus récente d'abord).
     * Retourne [] si le caractère n'est pas dans la table (ne devrait pas arriver après isValidFormat).
     */
    private function decodeYear(string $vin): array
    {
        return self::YEAR_MAP[$vin[9]] ?? [];
    }

    /**
     * Char 8 (index 7) = code moteur constructeur (VDS).
     * Retourne null si indéfini ('0').
     */
    private function decodeEngineCode(string $vin): ?string
    {
        $code = $vin[7];

        return $code === '0' ? null : $code;
    }

    // -----------------------------------------------------------------------
    // API NHTSA vPIC
    // -----------------------------------------------------------------------

    /**
     * Appelle DecodeVinValues et retourne le premier résultat, ou null si KO.
     * Timeout court (8 s) : l'endpoint est public et généralement rapide.
     */
    private function callNhtsa(string $vin): ?array
    {
        try {
            $response = Http::timeout(8)->get(
                "https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/{$vin}",
                ['format' => 'json']
            );

            if (! $response->ok()) {
                return null;
            }

            $results = $response->json('Results', []);

            if (empty($results)) {
                return null;
            }

            $data = $results[0];

            // NHTSA retourne ErrorCode "0" pour succès ; "1" ou plus = échec/partiel
            if (($data['ErrorCode'] ?? '0') !== '0') {
                // On renvoie quand même les données partielles — ils peuvent contenir Make/Model
                // (ErrorCode "1" = VIN partiellement décodé, pas forcément vide)
            }

            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Résolution en base
    // -----------------------------------------------------------------------

    /**
     * Résout brand_id depuis le nom de marque NHTSA.
     * NHTSA renvoie souvent en majuscules ("TOYOTA", "MERCEDES-BENZ").
     * Stratégie : égalité exacte insensible à la casse, puis LIKE.
     *
     * @return array{id: int, name: string, slug: string}|null
     */
    private function resolveBrand(string $nhtsaMake): ?array
    {
        $lower = strtolower($nhtsaMake);

        $brand = Brand::where('is_active', true)
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(name) = ?', [$lower])
                  ->orWhereRaw('LOWER(name) LIKE ?', ["%{$lower}%"]);
            })
            ->orderByRaw("CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END", [$lower]) // exact d'abord
            ->first(['id', 'name', 'slug']);

        return $brand?->toArray();
    }

    /**
     * Résout vehicle_model_id depuis le nom de modèle NHTSA, dans le scope d'une marque.
     *
     * @return array{id: int, name: string, slug: string, generation: string|null, body_type: string|null}|null
     */
    private function resolveModel(int $brandId, string $nhtsaModel): ?array
    {
        $lower = strtolower($nhtsaModel);

        $model = VehicleModel::where('brand_id', $brandId)
            ->where('is_active', true)
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(name) = ?', [$lower])
                  ->orWhereRaw('LOWER(name) LIKE ?', ["%{$lower}%"]);
            })
            ->orderByRaw("CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END", [$lower])
            ->first(['id', 'name', 'slug', 'generation', 'body_type']);

        return $model?->toArray();
    }

    /**
     * Résout engine_type_id depuis FuelTypePrimary NHTSA.
     *
     * NHTSA renvoie : "Gasoline", "Diesel", "Electric", "Flex Fuel (FFET)",
     * "Natural Gas (NG)", "Plug-in Electric/Gas (PHEV)", "Hydrogen"…
     *
     * @return array{id: int, code: string, label: string, uses_fuel: bool, uses_battery: bool}|null
     */
    private function resolveEngineType(?array $nhtsa): ?array
    {
        if (! $nhtsa) {
            return null;
        }

        $fuel = strtolower((string) ($nhtsa['FuelTypePrimary'] ?? ''));

        if (blank($fuel)) {
            return null;
        }

        // Mots-clés ordonnés du plus spécifique au plus général
        $keywords = match (true) {
            str_contains($fuel, 'electric') && str_contains($fuel, 'gas'),
            str_contains($fuel, 'phev'),
            str_contains($fuel, 'plug-in')             => ['phev', 'hybrid rechargeable', 'plug-in', 'hybride rechargeable'],

            str_contains($fuel, 'electric')             => ['electric', 'électrique', 'electrique', 'ev'],

            str_contains($fuel, 'hybrid')               => ['hybrid', 'hybride'],

            str_contains($fuel, 'diesel')               => ['diesel', 'gasoil', 'gas-oil'],

            str_contains($fuel, 'gasoline'),
            str_contains($fuel, 'petrol'),
            str_contains($fuel, 'flex fuel'),
            str_contains($fuel, 'ffet')                => ['gasoline', 'essence', 'petrol'],

            str_contains($fuel, 'natural gas'),
            str_contains($fuel, 'cng'),
            str_contains($fuel, 'lpg'),
            str_contains($fuel, 'gpl')                 => ['gpl', 'gaz', 'cng', 'lpg', 'natural gas'],

            default                                     => [],
        };

        if (empty($keywords)) {
            return null;
        }

        $query = EngineType::where(function ($q) use ($keywords, $fuel) {
            foreach ($keywords as $kw) {
                $q->orWhereRaw('LOWER(code) LIKE ?',  ["%{$kw}%"])
                  ->orWhereRaw('LOWER(label) LIKE ?', ["%{$kw}%"]);
            }
            // Essai direct sur la valeur brute NHTSA en dernier recours
            $q->orWhereRaw('LOWER(label) LIKE ?', ["%{$fuel}%"]);
        });

        // Priorité : PHEV > hybride > électrique > essence/diesel
        // On préfère le résultat avec le code le plus court (souvent le plus précis)
        $engineType = $query->orderByRaw('LENGTH(code)')->first(['id', 'code', 'label', 'uses_fuel', 'uses_battery']);

        return $engineType?->toArray();
    }

    /**
     * Résout drivetrain_id depuis DriveType NHTSA.
     *
     * NHTSA renvoie : "FWD/Front-Wheel Drive", "RWD/Rear-Wheel Drive",
     * "AWD/All-Wheel Drive", "4WD/4-Wheel Drive", "4x2"…
     *
     * @return array{id: int, code: string, label: string}|null
     */
    private function resolveDrivetrain(?array $nhtsa): ?array
    {
        if (! $nhtsa) {
            return null;
        }

        $drive = strtolower((string) ($nhtsa['DriveType'] ?? ''));

        if (blank($drive)) {
            return null;
        }

        $keywords = match (true) {
            str_contains($drive, 'fwd') || str_contains($drive, 'front')
                => ['fwd', 'traction', 'front'],

            str_contains($drive, 'rwd') || str_contains($drive, 'rear')
                => ['rwd', 'propulsion', 'rear'],

            str_contains($drive, 'awd') || str_contains($drive, 'all-wheel')
                => ['awd', 'integral', 'intégral', '4x4'],

            str_contains($drive, '4wd') || str_contains($drive, '4-wheel') || str_contains($drive, '4x4')
                => ['4wd', '4x4', 'integral', 'intégral', 'awd'],

            default => [],
        };

        if (empty($keywords)) {
            return null;
        }

        $drivetrain = Drivetrain::where(function ($q) use ($keywords) {
            foreach ($keywords as $kw) {
                $q->orWhereRaw('LOWER(code) LIKE ?',  ["%{$kw}%"])
                  ->orWhereRaw('LOWER(label) LIKE ?', ["%{$kw}%"]);
            }
        })
        ->orderByRaw('LENGTH(code)')
        ->first(['id', 'code', 'label']);

        return $drivetrain?->toArray();
    }

    // -----------------------------------------------------------------------
    // Utilitaires
    // -----------------------------------------------------------------------

    /**
     * Niveau de confiance global du décodage.
     *
     * high   : marque + modèle résolus + au moins un champ technique
     * medium : marque + modèle résolus
     * low    : marque résolue seulement, ou année locale uniquement
     * none   : rien de résolu (NHTSA KO + pas de marque en base)
     */
    private function computeConfidence(
        ?array $brand,
        ?array $model,
        ?array $engineType,
        ?array $drivetrain,
        ?array $nhtsa
    ): string {
        if (! $nhtsa && ! $brand) {
            return 'none';
        }

        $score = 0;
        if ($brand)      $score += 2;
        if ($model)      $score += 3;
        if ($engineType) $score += 1;
        if ($drivetrain) $score += 1;

        return match (true) {
            $score >= 6 => 'high',
            $score >= 5 => 'medium',
            $score >= 2 => 'low',
            default     => 'none',
        };
    }

    private function invalidResponse(string $vin, string $message): array
    {
        return [
            'vin'             => $vin,
            'valid'           => false,
            'year_candidates' => [],
            'engine_code'     => null,
            'nhtsa'           => null,
            'brand'           => null,
            'vehicle_model'   => null,
            'engine_type'     => null,
            'drivetrain'      => null,
            'confidence'      => 'none',
            'warnings'        => [$message],
        ];
    }
}
