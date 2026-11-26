<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire les 4 compatibilités de filtre à huile portées par la ligne nue
 * `sienna` (décision du propriétaire du 26/11/2026), une fois chacune
 * remplacée par des compatibilités documentées au niveau génération
 * (`ToyotaSiennaReferentielImport::applications()`).
 *
 * Règles :
 *  - chaque ancienne ligne est retrouvée par SKU, slug, code moteur et
 *    bornes, jamais par identifiant ;
 *  - elle n'est retirée que si TOUTES ses lignes de remplacement existent
 *    (même SKU, slug de génération, moteur, bornes) et si leurs bornes
 *    réunies couvrent les siennes ; sinon elle reste, et le rapport le dit ;
 *  - avant suppression, la ligne complète est copiée dans
 *    `part_fitment_archives` avec la correspondance ancien → nouveau ;
 *  - idempotent : une ligne déjà retirée est simplement « absente ».
 */
final class SiennaLigneNueCompatibilitesRetrait
{
    public const MIGRATION = '2026_11_26_180100_retirer_les_compatibilites_de_la_sienna_nue';

    public const SLUG_NU = 'sienna';

    /**
     * Ancienne ligne → lignes de génération qui la remplacent. Les bornes
     * sont des années de fabrication EPC ; les anciennes étaient des
     * années-modèle du catalogue de correspondance du 18/10/2026.
     *
     * @return list<array{sku: string, engine_code: string, year_from: int, year_to: ?int, replaced_by: list<array{model_slug: string, year_from: int, year_to: ?int}>, reason: string}>
     */
    public static function correspondances(): array
    {
        return [
            [
                'sku' => '04152-YZZA1', 'engine_code' => '2GR-FE', 'year_from' => 2007, 'year_to' => 2016,
                'replaced_by' => [
                    ['model_slug' => 'sienna-xl20', 'year_from' => 2006, 'year_to' => 2010],
                    ['model_slug' => 'sienna-xl30', 'year_from' => 2010, 'year_to' => 2016],
                ],
                'reason' => 'À cheval sur XL20 et XL30 : l\'EPC sépare GSL2# (12/2006-01/2010, catalogue 622420, variantes) et GSL3# (01/2010-08/2016, catalogue 622430) ; janvier 2010 est partagé.',
            ],
            [
                'sku' => '04152-YZZA1', 'engine_code' => '1AR-FE', 'year_from' => 2011, 'year_to' => 2013,
                'replaced_by' => [
                    ['model_slug' => 'sienna-xl30', 'year_from' => 2010, 'year_to' => 2013],
                ],
                'reason' => 'XL30 ASL30 1AR-FE, fabrication 05/2010-08/2013 (EPC 622430).',
            ],
            [
                'sku' => '04152-YZZA1', 'engine_code' => '2GR-FKS', 'year_from' => 2017, 'year_to' => 2020,
                'replaced_by' => [
                    ['model_slug' => 'sienna-xl30', 'year_from' => 2016, 'year_to' => 2020],
                ],
                'reason' => 'XL30 GSL3# 2GR-FKS, fabrication 08/2016-09/2020 (EPC 622430).',
            ],
            [
                'sku' => '90915-YZZN1', 'engine_code' => 'A25A-FXS', 'year_from' => 2021, 'year_to' => null,
                'replaced_by' => [
                    ['model_slug' => 'sienna-xl40', 'year_from' => 2020, 'year_to' => null],
                ],
                'reason' => 'XL40 AXLH40/AXLH45 A25A-FXS, fabrication dès 11/2020 (EPC 622440 ; YZZN1 donnée par le PDF Toyota).',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function run(): array
    {
        $rapport = ['removed' => [], 'absent' => [], 'kept_replacement_missing' => [], 'kept_ambiguous' => []];

        if (! Schema::hasTable('part_fitment_archives')) {
            $rapport['error'] = 'table part_fitment_archives absente';

            return $rapport;
        }

        return DB::transaction(function () use ($rapport): array {
            $nue = DB::table('vehicle_models')->where('slug', self::SLUG_NU)->first(['id', 'slug']);
            if ($nue === null) {
                $rapport['bare_model'] = 'absent';

                return $rapport;
            }

            foreach (self::correspondances() as $c) {
                $cle = self::cle($c['sku'], self::SLUG_NU, $c['engine_code'], $c['year_from'], $c['year_to']);
                $partId = self::partId($c['sku']);
                if ($partId === null) {
                    $rapport['kept_replacement_missing'][] = $cle.' (article introuvable)';

                    continue;
                }

                $anciennes = DB::table('part_fitments')
                    ->where('part_id', $partId)
                    ->where('vehicle_model_id', $nue->id)
                    ->whereNull('trim_id')
                    ->where('engine_code', $c['engine_code'])
                    ->where('year_from', $c['year_from'])
                    ->where(fn ($q) => $c['year_to'] === null ? $q->whereNull('year_to') : $q->where('year_to', $c['year_to']))
                    ->get();

                if ($anciennes->isEmpty()) {
                    $rapport['absent'][] = $cle;

                    continue;
                }
                if ($anciennes->count() > 1) {
                    // Deux lignes identiques : ne pas choisir, laisser à une revue.
                    $rapport['kept_ambiguous'][] = $cle;

                    continue;
                }

                $remplacantes = [];
                $manquantes = [];
                foreach ($c['replaced_by'] as $r) {
                    $id = DB::table('part_fitments')
                        ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
                        ->where('part_fitments.part_id', $partId)
                        ->where('vehicle_models.slug', $r['model_slug'])
                        ->whereNull('part_fitments.trim_id')
                        ->where('part_fitments.engine_code', $c['engine_code'])
                        ->where('part_fitments.year_from', $r['year_from'])
                        ->where(fn ($q) => $r['year_to'] === null ? $q->whereNull('part_fitments.year_to') : $q->where('part_fitments.year_to', $r['year_to']))
                        ->value('part_fitments.id');
                    $nouvelle = self::cle($c['sku'], $r['model_slug'], $c['engine_code'], $r['year_from'], $r['year_to']);
                    if ($id === null) {
                        $manquantes[] = $nouvelle;
                    } else {
                        $remplacantes[] = $nouvelle;
                    }
                }

                if ($manquantes !== [] || ! self::couvre($c['year_from'], $c['year_to'], $c['replaced_by'])) {
                    $rapport['kept_replacement_missing'][] = $cle.($manquantes !== [] ? ' (manque : '.implode(', ', $manquantes).')' : ' (couverture incomplète)');

                    continue;
                }

                $ancienne = $anciennes->first();
                DB::table('part_fitment_archives')->updateOrInsert(
                    ['migration' => self::MIGRATION, 'fitment_key' => $cle],
                    [
                        'original_id' => $ancienne->id,
                        'part_sku' => $c['sku'],
                        'vehicle_model_slug' => self::SLUG_NU,
                        'attributes' => json_encode((array) $ancienne, JSON_UNESCAPED_UNICODE),
                        'replaced_by' => json_encode($remplacantes, JSON_UNESCAPED_UNICODE),
                        'reason' => $c['reason'],
                        'archived_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                DB::table('part_fitments')->where('id', $ancienne->id)->delete();
                DB::table('parts')->where('id', $partId)->update(['updated_at' => now()]);
                $rapport['removed'][] = $cle.' → '.implode(' + ', $remplacantes);
            }

            return $rapport;
        });
    }

    /**
     * Recrée les lignes archivées par cette migration (SKU et slug relus,
     * identifiants d'origine non réutilisés), puis vide leur archive.
     */
    public static function restaurer(): int
    {
        if (! Schema::hasTable('part_fitment_archives')) {
            return 0;
        }
        $restaurees = 0;
        foreach (DB::table('part_fitment_archives')->where('migration', self::MIGRATION)->get() as $archive) {
            $partId = self::partId($archive->part_sku);
            $modeleId = DB::table('vehicle_models')->where('slug', $archive->vehicle_model_slug)->value('id');
            if ($partId === null || $modeleId === null) {
                continue;
            }
            $a = json_decode($archive->attributes, true);
            $identite = [
                'part_id' => $partId, 'vehicle_model_id' => $modeleId, 'trim_id' => null,
                'engine_code' => $a['engine_code'], 'year_from' => $a['year_from'], 'year_to' => $a['year_to'],
            ];
            if (! DB::table('part_fitments')->where($identite)->exists()) {
                DB::table('part_fitments')->insert([
                    ...$identite,
                    'engine_type_id' => $a['engine_type_id'] ?? null,
                    'drivetrain_id' => $a['drivetrain_id'] ?? null,
                    'position' => $a['position'] ?? null,
                    'notes' => $a['notes'] ?? null,
                    'source' => $a['source'] ?? 'declared',
                    'chassis_code' => $a['chassis_code'] ?? null,
                    'created_at' => $a['created_at'] ?? now(),
                    'updated_at' => now(),
                ]);
                $restaurees++;
            }
            DB::table('part_fitment_archives')->where('id', $archive->id)->delete();
        }

        return $restaurees;
    }

    /** @param  list<array{year_from: int, year_to: ?int}>  $remplacantes */
    private static function couvre(int $de, ?int $a, array $remplacantes): bool
    {
        usort($remplacantes, fn ($x, $y) => $x['year_from'] <=> $y['year_from']);
        $curseur = $de;
        foreach ($remplacantes as $r) {
            if ($r['year_from'] > $curseur) {
                return false;
            }
            if ($r['year_to'] === null) {
                return true;
            }
            $curseur = max($curseur, $r['year_to'] + 1);
            if ($a !== null && $curseur > $a) {
                return true;
            }
        }

        return false;
    }

    private static function partId(string $sku): ?int
    {
        $ids = DB::table('parts')->where('sku', $sku)->whereNull('owner_partner_account_id')->whereNull('deleted_at')->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private static function cle(string $sku, string $slug, string $moteur, int $de, ?int $a): string
    {
        return $sku.'|'.$slug.'|'.$moteur.'|'.$de.'-'.($a ?? '');
    }
}
