<?php

namespace App\Support;

use App\Models\AccessoryFitment;
use App\Models\PartFitment;

/**
 * Le resume des vehicules compatibles, pour l'apercu de partage.
 *
 * og:description portait jusqu'ici la description catalogue de la piece —
 * souvent une note interne du genre « Visse, ancienne reference remplacee
 * par… ». C'est ce texte que WhatsApp affiche sous la vignette, et ce n'est
 * pas ce qui decide le destinataire. Les compatibilites le sont.
 *
 * Le corps de la page publique continue d'afficher la description telle
 * quelle : seul l'apercu change.
 */
final class CompatibilitesPourApercu
{
    /** Combien de vehicules distincts montrer avant « et N autres ». */
    public const LIMITE = 5;

    /**
     * @param  iterable<PartFitment|AccessoryFitment>  $fitments
     */
    public static function texte(iterable $fitments, int $limite = self::LIMITE): ?string
    {
        $uniques = [];

        foreach ($fitments as $fitment) {
            $ligne = self::formater($fitment);
            if ($ligne !== null && ! isset($uniques[$ligne])) {
                $uniques[$ligne] = true;
            }
        }

        $liste = array_keys($uniques);
        if ($liste === []) {
            return null;
        }

        $affichees = array_slice($liste, 0, $limite);
        $reste = count($liste) - count($affichees);
        $texte = implode(', ', $affichees);

        if ($reste > 0) {
            $texte .= ' et '.$reste.' autres';
        }

        return 'Compatible : '.$texte;
    }

    public static function formater(PartFitment|AccessoryFitment $fitment): ?string
    {
        $modele = $fitment->vehicleModel;
        if ($modele === null) {
            return null;
        }

        $nom = trim($modele->full_name);
        if ($nom === '') {
            return null;
        }

        $details = [];
        $annees = self::annees(
            $fitment->year_from !== null ? (int) $fitment->year_from : null,
            $fitment->year_to !== null ? (int) $fitment->year_to : null,
        );
        if ($annees !== null) {
            $details[] = $annees;
        }

        $code = trim((string) ($fitment->engine_code ?? ''));
        if ($code !== '') {
            $details[] = $code;
        }

        $chassis = trim((string) ($fitment->chassis_code ?? ''));
        if ($chassis !== '') {
            $details[] = $chassis;
        }

        return $details === [] ? $nom : $nom.' ('.implode(', ', $details).')';
    }

    private static function annees(?int $de, ?int $a): ?string
    {
        if ($de !== null && $a !== null) {
            return $de === $a ? (string) $de : $de.'-'.$a;
        }
        if ($de !== null) {
            return 'depuis '.$de;
        }
        if ($a !== null) {
            return "jusqu'à ".$a;
        }

        return null;
    }
}
