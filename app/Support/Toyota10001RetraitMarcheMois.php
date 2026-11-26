<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retire le marché JP et les mois de fabrication que la migration
 * 2026_11_26_210000 avait posés sur les cinq lignes CUSCO du filtre
 * plateforme 90915-10001.
 *
 * Pourquoi : `FitmentRestrictions` traite le marché et les mois comme des
 * critères ÉLIMINATOIRES (un véhicule d'un autre marché, ou d'un mois hors
 * plage dans l'année-borne, devient « exclu » et sort des listes). Or le
 * catalogue CUSCO est un catalogue japonais de pièces adaptables : rien ne
 * prouve qu'il soit restrictif ni exhaustif. Une exclusion sans preuve est
 * interdite (« aucune compatibilité ne doit être confirmée par extrapolation
 * non documentée » vaut aussi pour l'exclusion).
 *
 * Ce que fait `run()` : sur chaque ligne, pour chaque champ, le vide SEULEMENT
 * s'il vaut encore exactement la valeur posée par 210000 (`['JP']`, mois de la
 * période source) ET si la ligne est encore au niveau `aftermarket_crossref`
 * (une ligne promue à un niveau suffisant porte peut-être une restriction
 * justifiée : on n'y touche pas). Le niveau, l'URL CUSCO et les notes restent.
 * Le marché et la période restent lisibles dans les notes de la ligne.
 *
 * Ce que fait `rollback()` : remet ces valeurs SEULEMENT sur les champs encore
 * vides, sur les lignes encore au niveau `aftermarket_crossref`.
 *
 * Cible par SKU, slug, moteur et années, jamais par id.
 */
final class Toyota10001RetraitMarcheMois
{
    /** @return array{champs_retires: int} */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = ['champs_retires' => 0];
            $piece = Toyota10001PreuvesAftermarket::piecePlateforme();
            if ($piece === null) {
                return $rapport;
            }
            foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
                foreach (self::lignes((int) $piece->id, $cible) as $ligne) {
                    if (! self::estQualifiee($ligne)) {
                        continue;
                    }
                    $patch = [];
                    if (Toyota10001PreuvesAftermarket::codesEgaux($ligne->market_codes ?? null, ['JP'])) {
                        $patch['market_codes'] = null;
                    }
                    if ($ligne->month_from !== null && (int) $ligne->month_from === $cible['month_from']) {
                        $patch['month_from'] = null;
                    }
                    if ($ligne->month_to !== null && (int) $ligne->month_to === $cible['month_to']) {
                        $patch['month_to'] = null;
                    }
                    if ($patch === []) {
                        continue;
                    }
                    $patch['updated_at'] = now();
                    DB::table('part_fitments')->where('id', $ligne->id)->update($patch);
                    $rapport['champs_retires'] += count($patch) - 1;
                }
            }

            return $rapport;
        });
    }

    /** @return array{champs_remis: int} */
    public static function rollback(): array
    {
        return DB::transaction(function (): array {
            $rapport = ['champs_remis' => 0];
            $piece = Toyota10001PreuvesAftermarket::piecePlateforme();
            if ($piece === null) {
                return $rapport;
            }
            foreach (Toyota10001PreuvesAftermarket::cibles() as $cible) {
                foreach (self::lignes((int) $piece->id, $cible) as $ligne) {
                    if (! self::estQualifiee($ligne)) {
                        continue;
                    }
                    $patch = [];
                    if (Toyota10001PreuvesAftermarket::liste($ligne->market_codes ?? null) === []) {
                        $patch['market_codes'] = json_encode(['JP']);
                    }
                    if ($ligne->month_from === null) {
                        $patch['month_from'] = $cible['month_from'];
                    }
                    if ($ligne->month_to === null) {
                        $patch['month_to'] = $cible['month_to'];
                    }
                    if ($patch === []) {
                        continue;
                    }
                    $patch['updated_at'] = now();
                    DB::table('part_fitments')->where('id', $ligne->id)->update($patch);
                    $rapport['champs_remis'] += count($patch) - 1;
                }
            }

            return $rapport;
        });
    }

    /** Une ligne promue à un autre niveau n'est jamais modifiée. */
    private static function estQualifiee(object $ligne): bool
    {
        return ($ligne->evidence_level ?? null) === FitmentEvidence::AFTERMARKET_CROSSREF;
    }

    /**
     * @param  array{model_slug: string, engine_code: string, year_from: int, year_to: int}  $cible
     * @return Collection<int, object>
     */
    private static function lignes(int $partId, array $cible)
    {
        $modele = DB::table('vehicle_models')->where('slug', $cible['model_slug'])->first();
        if ($modele === null) {
            return collect();
        }

        return DB::table('part_fitments')->where([
            'part_id' => $partId,
            'vehicle_model_id' => $modele->id,
            'engine_code' => $cible['engine_code'],
            'year_from' => $cible['year_from'],
            'year_to' => $cible['year_to'],
            'source' => FitmentSource::Declared->value,
        ])->lockForUpdate()->get();
    }
}
