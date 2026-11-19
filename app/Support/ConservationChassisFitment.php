<?php

namespace App\Support;

/**
 * Conserve le code châssis quand une ancienne application renvoie la liste
 * sans cette clé.
 *
 * Le `PUT` remplace toute la liste. Une application qui ne connaît pas
 * `chassis_code` l'omet, et la colonne retomberait à null. Les cinq lignes
 * du 90915-10001, une fois renseignées depuis CUSCO, seraient donc vidées
 * au premier enregistrement depuis une version déjà installée.
 *
 * Si la clé est présente, y compris vide, c'est la valeur reçue qui gagne :
 * le personnel peut effacer le champ. Si elle est absente, on reprend le
 * code de la ligne existante de même identité.
 */
final class ConservationChassisFitment
{
    /**
     * @param  iterable<object>  $fitments
     * @return array<string, string>
     */
    public static function index(iterable $fitments, bool $avecPosition = true): array
    {
        $conserves = [];
        foreach ($fitments as $fitment) {
            $code = trim((string) ($fitment->chassis_code ?? ''));
            if ($code === '') {
                continue;
            }
            $conserves[self::cleDepuisModele($fitment, $avecPosition)] = $code;
        }

        return $conserves;
    }

    /**
     * @param  array<string, mixed>  $fitment
     * @param  array<string, string>  $conserves
     * @return array<string, mixed>
     */
    public static function appliquer(array $fitment, array $conserves, bool $avecPosition = true): array
    {
        if (array_key_exists('chassis_code', $fitment)) {
            $code = trim((string) $fitment['chassis_code']);
            $fitment['chassis_code'] = $code === '' ? null : $code;

            return $fitment;
        }

        $cle = self::cleDepuisTableau($fitment, $avecPosition);
        if (isset($conserves[$cle])) {
            $fitment['chassis_code'] = $conserves[$cle];
        }

        return $fitment;
    }

    public static function cleDepuisModele(object $fitment, bool $avecPosition = true): string
    {
        return self::composer(
            $fitment->vehicle_model_id ?? null,
            $fitment->trim_id ?? null,
            $fitment->engine_type_id ?? null,
            $fitment->drivetrain_id ?? null,
            $fitment->engine_code ?? null,
            $fitment->year_from ?? null,
            $fitment->year_to ?? null,
            $avecPosition ? ($fitment->position ?? null) : null,
            $avecPosition,
        );
    }

    /**
     * @param  array<string, mixed>  $fitment
     */
    public static function cleDepuisTableau(array $fitment, bool $avecPosition = true): string
    {
        return self::composer(
            $fitment['vehicle_model_id'] ?? null,
            $fitment['trim_id'] ?? null,
            $fitment['engine_type_id'] ?? null,
            $fitment['drivetrain_id'] ?? null,
            $fitment['engine_code'] ?? null,
            $fitment['year_from'] ?? null,
            $fitment['year_to'] ?? null,
            $avecPosition ? ($fitment['position'] ?? null) : null,
            $avecPosition,
        );
    }

    private static function composer(
        mixed $modele,
        mixed $finition,
        mixed $motorisation,
        mixed $transmission,
        mixed $moteur,
        mixed $de,
        mixed $a,
        mixed $position,
        bool $avecPosition,
    ): string {
        $parts = [
            (string) $modele,
            (string) ($finition ?? ''),
            (string) ($motorisation ?? ''),
            (string) ($transmission ?? ''),
            trim((string) ($moteur ?? '')),
            (string) ($de ?? ''),
            (string) ($a ?? ''),
        ];
        if ($avecPosition) {
            $parts[] = trim((string) ($position ?? ''));
        }

        return implode('|', $parts);
    }
}
