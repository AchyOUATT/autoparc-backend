<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Qualifie les cinq compatibilités déjà posées du filtre plateforme
 * 90915-10001 : preuve `aftermarket_crossref` (catalogue CUSCO, marché
 * Japon) et correction de la description. Les lignes restent candidates.
 *
 * Cible par clés stables (SKU, slug, moteur, années), jamais par id.
 * N'écrit que les champs vides ; ne pose jamais un niveau confirmable.
 * Le `down()` ne rétablit une ligne que si TOUS les champs posés ici valent
 * encore leur valeur d'origine ; sinon la ligne n'est pas touchée du tout.
 *
 * Le marché JP et les mois posés ici sont retirés par la migration suivante
 * (Toyota10001RetraitMarcheMois) : ils excluaient des véhicules sans preuve.
 */
final class Toyota10001PreuvesAftermarket
{
    public const SKU = '90915-10001';

    public const SOURCE_URL = 'https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf';

    /** Description lue en production le 26/11/2026, avant cette qualification. */
    public const DESCRIPTION_AVANT = 'Visse, ancienne reference remplacee par le 90915-YZZN1 sur les catalogues nord-americains. Applications anterieures a 2008, anterieures au referentiel.';

    public const DESCRIPTION_APRES = 'Visse, ancienne reference. Les catalogues de concessionnaires americains indiquent un remplacement par le 90915-YZZN1 ; ce n\'est pas un fait officiel Toyota, et ce remplacement ne confirme pas a lui seul une application.';

    /**
     * Mois repris des périodes CUSCO déjà écrites dans les notes
     * (06/1991–05/1995, 05/1995–07/2000, 11/1992–09/1996, 12/1996–08/2001,
     * 04/1994–05/2000). Ils ne s'appliquent qu'aux années-bornes.
     *
     * @return list<array{model_slug: string, engine_code: string, year_from: int, year_to: int, month_from: int, month_to: int}>
     */
    public static function cibles(): array
    {
        return [
            ['model_slug' => 'corolla-e100', 'engine_code' => '4A-FE', 'year_from' => 1991, 'year_to' => 1995, 'month_from' => 6, 'month_to' => 5],
            ['model_slug' => 'corolla-e110', 'engine_code' => '4A-FE', 'year_from' => 1995, 'year_to' => 2000, 'month_from' => 5, 'month_to' => 7],
            ['model_slug' => 'camry-xv10', 'engine_code' => '5S-FE', 'year_from' => 1992, 'year_to' => 1996, 'month_from' => 11, 'month_to' => 9],
            ['model_slug' => 'camry-xv20', 'engine_code' => '5S-FE', 'year_from' => 1996, 'year_to' => 2001, 'month_from' => 12, 'month_to' => 8],
            ['model_slug' => 'rav4-xa10', 'engine_code' => '3S-FE', 'year_from' => 1994, 'year_to' => 2000, 'month_from' => 4, 'month_to' => 5],
        ];
    }

    /** @return array{fitments_qualifies: int, description_corrigee: int} */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = ['fitments_qualifies' => 0, 'description_corrigee' => 0];
            $piece = self::piecePlateforme();
            if ($piece === null) {
                return $rapport;
            }

            $rapport['description_corrigee'] = self::corrigerDescription((int) $piece->id, self::DESCRIPTION_AVANT, self::DESCRIPTION_APRES);
            foreach (self::cibles() as $cible) {
                $rapport['fitments_qualifies'] += self::appliquerLigne((int) $piece->id, $cible, remplir: true);
            }

            return $rapport;
        });
    }

    /** @return array{fitments_retablis: int, description_retablie: int} */
    public static function rollback(): array
    {
        return DB::transaction(function (): array {
            $rapport = ['fitments_retablis' => 0, 'description_retablie' => 0];
            $piece = self::piecePlateforme();
            if ($piece === null) {
                return $rapport;
            }

            $rapport['description_retablie'] = self::corrigerDescription((int) $piece->id, self::DESCRIPTION_APRES, self::DESCRIPTION_AVANT);
            foreach (self::cibles() as $cible) {
                $rapport['fitments_retablis'] += self::appliquerLigne((int) $piece->id, $cible, remplir: false);
            }

            return $rapport;
        });
    }

    /** @internal Réutilisé par Toyota10001RetraitMarcheMois. */
    public static function piecePlateforme(): ?object
    {
        $pieces = DB::table('parts')->where('sku', self::SKU)
            ->whereNull('owner_partner_account_id')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($pieces->count() > 1) {
            throw new RuntimeException('Qualification 90915-10001 refusée : plusieurs articles plateforme portent ce SKU.');
        }

        return $pieces->first();
    }

    private static function corrigerDescription(int $partId, string $de, string $vers): int
    {
        return (int) DB::table('parts')->where('id', $partId)->where('description', $de)
            ->update(['description' => $vers, 'updated_at' => now()]);
    }

    /**
     * @param  array{model_slug: string, engine_code: string, year_from: int, year_to: int, month_from: int, month_to: int}  $cible
     */
    private static function appliquerLigne(int $partId, array $cible, bool $remplir): int
    {
        $modele = DB::table('vehicle_models')->where('slug', $cible['model_slug'])->first();
        if ($modele === null) {
            return 0;
        }

        $lignes = DB::table('part_fitments')->where([
            'part_id' => $partId,
            'vehicle_model_id' => $modele->id,
            'engine_code' => $cible['engine_code'],
            'year_from' => $cible['year_from'],
            'year_to' => $cible['year_to'],
            'source' => FitmentSource::Declared->value,
        ])->lockForUpdate()->get();

        $modifiees = 0;
        foreach ($lignes as $ligne) {
            $patch = $remplir
                ? self::patchRemplissage($ligne, $cible)
                : self::patchRetablissement($ligne, $cible);
            if ($patch === []) {
                continue;
            }
            $patch['updated_at'] = now();
            DB::table('part_fitments')->where('id', $ligne->id)->update($patch);
            $modifiees++;
        }

        return $modifiees;
    }

    /**
     * @param  array{month_from: int, month_to: int}  $cible
     * @return array<string, mixed>
     */
    private static function patchRemplissage(object $ligne, array $cible): array
    {
        $patch = [];
        if (self::estVide($ligne->evidence_level ?? null)) {
            $patch['evidence_level'] = FitmentEvidence::AFTERMARKET_CROSSREF;
        }
        if (self::listeVide($ligne->evidence_sources ?? null)) {
            $patch['evidence_sources'] = json_encode([self::SOURCE_URL], JSON_UNESCAPED_SLASHES);
        }
        if (self::listeVide($ligne->market_codes ?? null)) {
            $patch['market_codes'] = json_encode(['JP']);
        }
        if ($ligne->month_from === null) {
            $patch['month_from'] = $cible['month_from'];
        }
        if ($ligne->month_to === null) {
            $patch['month_to'] = $cible['month_to'];
        }

        // Filet : cette qualification ne doit jamais poser un niveau confirmable.
        if (isset($patch['evidence_level']) && in_array($patch['evidence_level'], FitmentEvidence::SUFFICIENT, true)) {
            unset($patch['evidence_level'], $patch['evidence_sources']);
        }

        return $patch;
    }

    /**
     * Tout ou rien : la ligne n'est rétablie que si CHAQUE champ posé par
     * `up()` vaut encore sa valeur d'origine (niveau, sources, marché, deux
     * mois). Dès qu'un seul a été modifié ou enrichi à la main, la ligne n'est
     * pas touchée du tout : on ne laisse jamais une ligne à moitié défaite.
     *
     * @param  array{month_from: int, month_to: int}  $cible
     * @return array<string, mixed>
     */
    private static function patchRetablissement(object $ligne, array $cible): array
    {
        $intacte = ($ligne->evidence_level ?? null) === FitmentEvidence::AFTERMARKET_CROSSREF
            && self::codesEgaux($ligne->evidence_sources ?? null, [self::SOURCE_URL])
            && self::codesEgaux($ligne->market_codes ?? null, ['JP'])
            && $ligne->month_from !== null && (int) $ligne->month_from === $cible['month_from']
            && $ligne->month_to !== null && (int) $ligne->month_to === $cible['month_to'];

        if (! $intacte) {
            return [];
        }

        return [
            'evidence_level' => null,
            'evidence_sources' => null,
            'market_codes' => null,
            'month_from' => null,
            'month_to' => null,
        ];
    }

    private static function estVide(mixed $valeur): bool
    {
        return $valeur === null || $valeur === '';
    }

    private static function listeVide(mixed $valeur): bool
    {
        return self::liste($valeur) === [];
    }

    /**
     * @param  list<string>  $attendu
     */
    /** @internal Réutilisé par Toyota10001RetraitMarcheMois. */
    public static function codesEgaux(mixed $valeur, array $attendu): bool
    {
        return self::liste($valeur) === $attendu;
    }

    /** @return list<string> */
    /** @internal Réutilisé par Toyota10001RetraitMarcheMois. */
    public static function liste(mixed $valeur): array
    {
        if ($valeur === null || $valeur === '' || $valeur === 'null') {
            return [];
        }
        if (is_string($valeur)) {
            $valeur = json_decode($valeur, true);
        }
        if (! is_array($valeur)) {
            return [];
        }

        return array_values($valeur);
    }
}
