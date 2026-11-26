<?php

namespace App\Support;

use App\Enums\FitmentSource;
use App\Models\OemNumberFitment;
use App\Models\PartFitment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Niveau de preuve d'une COMPATIBILITE (ce véhicule reçoit cette pièce).
 *
 * À ne pas confondre avec la preuve d'un REMPLACEMENT de référence
 * (oem_number_relations.evidence_level) : qu'une référence en remplace une
 * autre ne dit rien des véhicules qui la reçoivent.
 *
 * Principe : aucune compatibilité n'est confirmée par une extrapolation non
 * documentée. Une ligne est DOCUMENTÉE, donc confirmable, si et seulement si :
 *  1. son origine est `declared` (une ligne `generated` ne l'est jamais) ;
 *  2. son `evidence_level` est l'un de :
 *       manufacturer_official (fiche ou guide officiel du constructeur),
 *       manufacturer_epc      (catalogue électronique de pièces constructeur),
 *       dealer_catalog        (catalogue concessionnaire de la marque),
 *       human_verified        (validation humaine documentée) ;
 *  3. `evidence_sources` contient au moins une URL http(s) ;
 *  4. pour human_verified seulement : `verified_by_id` (compte du personnel
 *     qui a validé) ET `verified_on` (date) sont renseignés.
 *
 * Ne suffisent JAMAIS : un niveau vide (déclaration nue, y compris les lignes
 * historiques), `aftermarket_crossref`, `unverified`, et le texte libre de
 * `notes`, même s'il cite une source ou contient une URL : seule la colonne
 * structurée `evidence_sources` compte.
 */
final class FitmentEvidence
{
    public const MANUFACTURER_OFFICIAL = 'manufacturer_official';

    public const MANUFACTURER_EPC = 'manufacturer_epc';

    public const DEALER_CATALOG = 'dealer_catalog';

    public const HUMAN_VERIFIED = 'human_verified';

    public const AFTERMARKET_CROSSREF = 'aftermarket_crossref';

    public const UNVERIFIED = 'unverified';

    public const LEVELS = [
        self::MANUFACTURER_OFFICIAL, self::MANUFACTURER_EPC, self::DEALER_CATALOG,
        self::HUMAN_VERIFIED, self::AFTERMARKET_CROSSREF, self::UNVERIFIED,
    ];

    /** Niveaux qui PEUVENT confirmer, à condition d'être documentés. */
    public const SUFFICIENT = [
        self::MANUFACTURER_OFFICIAL, self::MANUFACTURER_EPC, self::DEALER_CATALOG, self::HUMAN_VERIFIED,
    ];

    /** La ligne est-elle documentée au sens ci-dessus ? */
    public static function isDocumented(PartFitment|OemNumberFitment $fitment, bool $declared = true): bool
    {
        if (! $declared) {
            return false;
        }
        if ($fitment instanceof PartFitment) {
            $source = $fitment->source instanceof FitmentSource ? $fitment->source : FitmentSource::tryFrom((string) $fitment->source);
            if ($source === FitmentSource::Generated) {
                return false;
            }
        }

        $level = $fitment->evidence_level;
        if (! in_array($level, self::SUFFICIENT, true)) {
            return false;
        }
        if (self::urls($fitment->evidence_sources) === []) {
            return false;
        }
        if ($level === self::HUMAN_VERIFIED) {
            return $fitment->verified_by_id !== null && $fitment->verified_on !== null;
        }

        return true;
    }

    /**
     * Une ligne human_verified sans valideur reçoit le compte qui
     * l'enregistre ; un valideur déjà conservé n'est pas remplacé.
     *
     * @param  array<string, mixed>  $fitment
     * @return array<string, mixed>
     */
    public static function stampValidator(array $fitment, ?int $userId): array
    {
        if (($fitment['evidence_level'] ?? null) === self::HUMAN_VERIFIED
            && empty($fitment['verified_by_id']) && $userId !== null) {
            $fitment['verified_by_id'] = $userId;
        }

        return $fitment;
    }

    /** Même règle, en SQL (pré-filtre des listes confirmées). */
    public static function constrainDocumented(Builder $fitment): Builder
    {
        $table = $fitment->getModel()->getTable();
        $driver = $fitment->getConnection()->getDriverName();
        $longueur = $driver === 'pgsql'
            ? "(CASE WHEN json_typeof({$table}.evidence_sources) = 'array' THEN json_array_length({$table}.evidence_sources) ELSE 0 END)"
            : "(CASE WHEN json_type({$table}.evidence_sources) = 'array' THEN json_array_length({$table}.evidence_sources) ELSE 0 END)";

        return $fitment
            ->whereIn("{$table}.evidence_level", self::SUFFICIENT)
            ->whereNotNull("{$table}.evidence_sources")
            ->whereRaw("{$longueur} > 0")
            ->where(fn (Builder $q) => $q->where("{$table}.evidence_level", '!=', self::HUMAN_VERIFIED)
                ->orWhere(fn (Builder $h) => $h->whereNotNull("{$table}.verified_by_id")->whereNotNull("{$table}.verified_on")));
    }

    /**
     * URL http(s) d'une liste de sources (les autres entrées sont ignorées).
     *
     * @return list<string>
     */
    public static function urls(mixed $sources): array
    {
        if (is_string($sources)) {
            $sources = json_decode($sources, true);
        }
        if (! is_array($sources)) {
            return [];
        }

        return array_values(array_filter($sources, fn ($url) => is_string($url) && preg_match('~^https?://\S+$~i', $url) === 1));
    }

    /**
     * URL citées dans un texte libre (notes), dans l'ordre, sans doublon.
     * Sert aux imports et au rétro-remplissage proposé ; ne vaut jamais
     * preuve à lui seul.
     *
     * @return list<string>
     */
    public static function urlsInText(?string $texte): array
    {
        if ($texte === null || $texte === '') {
            return [];
        }
        preg_match_all('~https?://[^\s<>"«»()\[\],;]+~iu', $texte, $m);

        return array_values(array_unique(array_map(fn (string $u) => rtrim($u, '.:'), $m[0])));
    }
}
