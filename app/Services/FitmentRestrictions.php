<?php

namespace App\Services;

use App\Models\OemNumberFitment;
use App\Models\PartFitment;
use App\Support\ChassisCode;
use App\Support\Market;
use Illuminate\Database\Eloquent\Builder;

/**
 * Critères restrictifs ajoutés aux compatibilités : châssis, marché de
 * destination et mois de fabrication aux bornes.
 *
 * Une colonne vide ne restreint rien : les règles existantes gardent
 * exactement leur comportement. Une règle restrictive :
 *  - satisfaite → ne change rien ;
 *  - incompatible → exclut la règle ;
 *  - inconnue ou ambiguë côté véhicule → la règle devient « à vérifier »
 *    (candidate), jamais « confirmée ».
 *
 * Toute la logique tient ici, en PHP, et les requêtes SQL n'en reçoivent que
 * le résultat (des identifiants de lignes). Il n'y a donc pas deux versions
 * de la règle qui pourraient diverger.
 */
class FitmentRestrictions
{
    public const MISSING_CHASSIS = 'chassis_code';

    public const MISSING_MARKET = 'specification_market';

    public const MISSING_MONTH = 'manufacturing_month';

    /**
     * Null exclut la ligne ; une liste vide la laisse intacte ; sinon, les
     * critères à renseigner.
     *
     * @return list<string>|null
     */
    public function evaluate(PartFitment|OemNumberFitment $fitment, array $criteria): ?array
    {
        $detail = $this->evaluateDetailed($fitment, $criteria);

        return $detail['excluded'] === null ? $detail['missing'] : null;
    }

    /**
     * Comme evaluate(), en nommant le critère qui exclut la ligne.
     *
     * @return array{excluded: ?string, missing: list<string>}
     */
    public function evaluateDetailed(PartFitment|OemNumberFitment $fitment, array $criteria): array
    {
        $missing = [];

        // Châssis : seulement si la règle le déclare restrictif.
        if ($fitment->chassis_restricted) {
            $codes = ChassisCode::parseList($fitment->chassis_code);
            if ($codes !== []) {
                $vehicule = ChassisCode::normalize($criteria['chassis_code'] ?? null);
                if ($vehicule === null) {
                    $missing[] = self::MISSING_CHASSIS;
                } else {
                    $resultat = ChassisCode::compare($codes, $vehicule);
                    if ($resultat === ChassisCode::MISMATCH) {
                        return ['excluded' => self::MISSING_CHASSIS, 'missing' => []];
                    }
                    if ($resultat === ChassisCode::AMBIGUOUS) {
                        $missing[] = self::MISSING_CHASSIS;
                    }
                }
            }
        }

        // Marché de destination.
        $marches = array_values(array_filter(array_map(
            fn ($c) => Market::normalize(is_string($c) ? $c : null),
            (array) ($fitment->market_codes ?? []),
        )));
        if ($marches !== []) {
            $vehicule = Market::normalize($criteria['specification_market'] ?? null);
            if ($vehicule === null) {
                // Absent, ou code que le référentiel ne connaît pas.
                $missing[] = self::MISSING_MARKET;
            } else {
                $resultat = Market::compare($marches, $vehicule);
                if ($resultat === Market::MISMATCH) {
                    return ['excluded' => self::MISSING_MARKET, 'missing' => []];
                }
                if ($resultat === Market::AMBIGUOUS) {
                    $missing[] = self::MISSING_MARKET;
                }
            }
        }

        // Mois aux bornes. L'année elle-même est déjà jugée par le moteur
        // historique ; seul le mois de l'année-borne est examiné ici.
        $annee = $criteria['year'] ?? null;
        $mois = $criteria['month'] ?? null;
        $mois = ($mois === null || $mois === '') ? null : (int) $mois;
        if ($annee !== null && $annee !== '') {
            $annee = (int) $annee;
            $bornes = [
                [$fitment->year_from, $fitment->month_from, fn (int $m, int $b) => $m >= $b],
                [$fitment->year_to, $fitment->month_to, fn (int $m, int $b) => $m <= $b],
            ];
            foreach ($bornes as [$borneAnnee, $borneMois, $respecte]) {
                if ($borneAnnee === null || $borneMois === null || (int) $borneAnnee !== $annee) {
                    continue;
                }
                if ($mois === null) {
                    $missing[] = self::MISSING_MONTH;
                } elseif (! $respecte($mois, (int) $borneMois)) {
                    return ['excluded' => self::MISSING_MONTH, 'missing' => []];
                }
            }
        }

        return ['excluded' => null, 'missing' => array_values(array_unique($missing))];
    }

    public static function isRestricted(PartFitment|OemNumberFitment $fitment): bool
    {
        return (bool) $fitment->chassis_restricted
            || ! empty($fitment->market_codes)
            || $fitment->month_from !== null
            || $fitment->month_to !== null;
    }

    /** Lignes portant au moins un critère restrictif. */
    public static function scopeRestricted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('chassis_restricted', true)
            ->orWhereNotNull('market_codes')
            ->orWhereNotNull('month_from')
            ->orWhereNotNull('month_to'));
    }

    /**
     * Identifiants des lignes restrictives d'un modèle à écarter.
     *
     * $mode = 'excluded'      : lignes incompatibles (pré-filtre des listes) ;
     * $mode = 'not_confirmed' : lignes incompatibles OU à vérifier (filtre des
     *                           seules confirmations).
     *
     * @param  class-string<PartFitment|OemNumberFitment>  $model
     * @return list<int>
     */
    public function idsToDrop(string $model, ?int $vehicleModelId, array $criteria, string $mode): array
    {
        if ($vehicleModelId === null) {
            return [];
        }

        $ids = [];
        $query = $model::query()->where('vehicle_model_id', $vehicleModelId);
        self::scopeRestricted($query);

        foreach ($query->get() as $fitment) {
            $resultat = $this->evaluate($fitment, $criteria);
            if ($resultat === null || ($mode === 'not_confirmed' && $resultat !== [])) {
                $ids[] = (int) $fitment->id;
            }
        }

        return $ids;
    }
}
