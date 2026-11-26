<?php

namespace App\Support;

/**
 * Conserve les critères restrictifs quand une application renvoie la liste
 * des compatibilités sans ces clés.
 *
 * Même mécanisme que ConservationChassisFitment, et pour la même raison :
 * le `PUT` d'une pièce remplace toute la liste, et l'éditeur mobile actuel
 * ne connaît ni `chassis_restricted`, ni `market_codes`, ni `month_from` /
 * `month_to`. Sans cette conservation, le premier enregistrement depuis
 * l'application effacerait ces restrictions et rendrait « confirmées » des
 * lignes qui ne le sont pas.
 *
 * Clé présente, même vide : la valeur reçue gagne. Clé absente : on reprend
 * celle de la ligne existante de même identité.
 */
final class ConservationRestrictionsFitment
{
    public const CHAMPS = ['chassis_restricted', 'market_codes', 'month_from', 'month_to', 'evidence_level', 'evidence_sources'];

    /**
     * @param  iterable<object>  $fitments
     * @return array<string, array<string, mixed>>
     */
    public static function index(iterable $fitments): array
    {
        $conserves = [];
        foreach ($fitments as $fitment) {
            $valeurs = [];
            foreach (self::CHAMPS as $champ) {
                $valeur = $fitment->{$champ} ?? null;
                if ($valeur !== null && $valeur !== [] && $valeur !== false) {
                    $valeurs[$champ] = $valeur;
                }
            }
            if ($valeurs !== []) {
                $conserves[ConservationChassisFitment::cleDepuisModele($fitment)] = $valeurs;
            }
        }

        return $conserves;
    }

    /**
     * @param  array<string, mixed>  $fitment
     * @param  array<string, array<string, mixed>>  $conserves
     * @return array<string, mixed>
     */
    public static function appliquer(array $fitment, array $conserves): array
    {
        $cle = ConservationChassisFitment::cleDepuisTableau($fitment);
        foreach (self::CHAMPS as $champ) {
            if (! array_key_exists($champ, $fitment) && isset($conserves[$cle][$champ])) {
                $fitment[$champ] = $conserves[$cle][$champ];
            }
        }

        return $fitment;
    }
}
