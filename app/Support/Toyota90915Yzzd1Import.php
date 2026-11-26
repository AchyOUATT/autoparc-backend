<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pose les compatibilités sourcées du filtre à huile vissé Toyota 90915-YZZD1
 * (article déjà au catalogue, sans aucune application).
 *
 * Règles suivies :
 *  - l'article est retrouvé par SKU ; s'il manque, est supprimé ou existe en
 *    double, rien n'est écrit (ou l'import est refusé pour le doublon) ;
 *  - prix, stock, publication, nom et numéros OEM ne sont jamais touchés ;
 *  - seuls les modèles déjà au catalogue (par slug) reçoivent une ligne ;
 *  - chaque ligne est restreinte au CODE MOTEUR, car ce filtre dépend du
 *    moteur et non du nom du modèle (le Camry XV10/XV20 en 5S-FE prend un
 *    autre filtre, déjà posé sur le 90915-10001) ;
 *  - une ligne déjà présente (même modèle, moteur et bornes) n'est pas
 *    dupliquée ni réécrite : le rejeu est sans effet.
 */
final class Toyota90915Yzzd1Import
{
    public const SKU = '90915-YZZD1';

    private const SRC_MUGEN = 'https://store.mugen-24.com/product/toyota-lexus-genuine-oil-filter-90915-yzzd1-oem/';

    private const SRC_AUTOHAUS = 'https://www.autohausaz.com/pn/90915YZZD1OE';

    private const SRC_SPARKS = 'https://www.sparksparts.com/oem-parts/toyota-1984-2023-toyota-oil-filter-90915yzzd1';

    private const SRC_YOTASHOP = 'https://www.yotashop.com/oil-filter-toyota-genuine-oil-filter-90915-yzzd1/';

    private const SRC_LEXUS = 'https://parts.lexus.com/p/Lexus_2007_RX-400h/Engine-Oil-Filter/69396229/90915YZZD1.html';

    private const SRC_LEXUSPARTSNOW = 'https://www.lexuspartsnow.com/oem-lexus-rx330-oil_filter.html';

    private const SRC_CAMELBACK = 'https://parts.camelbacktoyota.com/oem-parts/toyota-oil-filter-90915yzzd1?c=az0x';

    private const BORNES = ' Bornes annuelles : contrôler le VIN, le code moteur et la date de fabrication aux bornes.';

    /** @return array<string, mixed> */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'part_id' => null,
                'fitments_inserted' => 0,
                'fitments_already_present' => 0,
                'fitments_skipped_missing_model' => [],
            ];

            $parts = DB::table('parts')->where('sku', self::SKU)
                ->whereNull('owner_partner_account_id')
                ->lockForUpdate()
                ->limit(2)
                ->get();
            if ($parts->count() > 1) {
                throw new RuntimeException('Import 90915-YZZD1 refusé : plusieurs articles plateforme portent ce SKU.');
            }

            $part = $parts->first();
            if ($part === null || $part->deleted_at !== null) {
                return $rapport;
            }

            $rapport['part_id'] = (int) $part->id;

            foreach (self::applications() as $application) {
                $modele = DB::table('vehicle_models')->where('slug', $application['model_slug'])->first();
                if ($modele === null) {
                    $rapport['fitments_skipped_missing_model'][] = $application['model_slug'];

                    continue;
                }

                $identite = [
                    'part_id' => $part->id,
                    'vehicle_model_id' => $modele->id,
                    'trim_id' => null,
                    'engine_type_id' => null,
                    'drivetrain_id' => null,
                    'engine_code' => $application['engine_code'],
                    'year_from' => $application['year_from'],
                    'year_to' => $application['year_to'],
                ];

                if (DB::table('part_fitments')->where($identite)->lockForUpdate()->exists()) {
                    $rapport['fitments_already_present']++;

                    continue;
                }

                DB::table('part_fitments')->insert([
                    ...$identite,
                    'position' => null,
                    'notes' => $application['notes'],
                    'source' => FitmentSource::Declared->value,
                    'chassis_code' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $rapport['fitments_inserted']++;
            }

            if ($rapport['fitments_inserted'] > 0) {
                DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
            }
            $rapport['fitments_skipped_missing_model'] = array_values(array_unique($rapport['fitments_skipped_missing_model']));

            return $rapport;
        });
    }

    /**
     * @return list<array{model_slug: string, engine_code: string, year_from: int, year_to: int, notes: string}>
     */
    public static function applications(): array
    {
        return [
            [
                'model_slug' => 'camry-xv10',
                'engine_code' => '3VZ-FE',
                'year_from' => 1992,
                'year_to' => 1996,
                'notes' => 'Camry V6 3.0 l 3VZ-FE, 1992-1996 (XV10). Sources : '.self::SRC_MUGEN.' (« 1992-1996 Camry V6 3.0L 3VZ-FE / 1MZ-FE ») ; '
                    .self::SRC_YOTASHOP.' (Camry 1992-2000, 3VZFE / 1MZFE). Le Camry 4 cylindres (5S-FE) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'camry-xv10',
                'engine_code' => '1MZ-FE',
                'year_from' => 1992,
                'year_to' => 1996,
                'notes' => 'Camry V6 3.0 l 1MZ-FE, 1992-1996 (XV10). Sources : '.self::SRC_MUGEN.' (« 1992-1996 Camry V6 3.0L 3VZ-FE / 1MZ-FE ») ; '
                    .self::SRC_YOTASHOP.' (Camry 1992-2000, 3VZFE / 1MZFE). Le Camry 4 cylindres (5S-FE) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'camry-xv20',
                'engine_code' => '1MZ-FE',
                'year_from' => 1997,
                'year_to' => 2001,
                'notes' => 'Camry V6 3.0 l 1MZ-FE, 1997-2001 (XV20). Sources : '.self::SRC_MUGEN.' (« 1997-2006 Camry V6 3.0L 1MZ-FE / 3.3L 3MZ-FE ») ; '
                    .self::SRC_SPARKS.' (« 1997-2006 Camry V6 ») ; '.self::SRC_AUTOHAUS.' (Camry 1997-2001, application spécifique, vissé). '
                    .'Le Camry 4 cylindres (5S-FE) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i',
                'engine_code' => '2RZ-FE',
                'year_from' => 1995,
                'year_to' => 2004,
                'notes' => 'Tacoma 2.4 l 2RZ-FE, 1995-2004. Sources : '.self::SRC_MUGEN.' (« 1995-2004 Tacoma 4Cyl 2.4L 2RZ-FE / 4Cyl 2.7L 3RZ-FE / V6 3.4L 5VZ-FE ») ; '
                    .self::SRC_AUTOHAUS.' (Tacoma 1995-2004, vissé) ; '.self::SRC_YOTASHOP.' (Tacoma 1995-2000, 2RZFE).'.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i',
                'engine_code' => '3RZ-FE',
                'year_from' => 1995,
                'year_to' => 2004,
                'notes' => 'Tacoma 2.7 l 3RZ-FE, 1995-2004. Sources : '.self::SRC_MUGEN.' (« 1995-2004 Tacoma 4Cyl 2.4L 2RZ-FE / 4Cyl 2.7L 3RZ-FE / V6 3.4L 5VZ-FE ») ; '
                    .self::SRC_AUTOHAUS.' (Tacoma 1995-2004, vissé) ; '.self::SRC_YOTASHOP.' (Tacoma 1995-2000, 3RZFE).'.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i',
                'engine_code' => '5VZ-FE',
                'year_from' => 1995,
                'year_to' => 2004,
                'notes' => 'Tacoma V6 3.4 l 5VZ-FE, 1995-2004. Sources : '.self::SRC_MUGEN.' (« 1995-2004 Tacoma 4Cyl 2.4L 2RZ-FE / 4Cyl 2.7L 3RZ-FE / V6 3.4L 5VZ-FE ») ; '
                    .self::SRC_SPARKS.' (« 1998-2004 Tacoma V6 ») ; '.self::SRC_YOTASHOP.' (Tacoma 1995-2000, 5VZFE).'.self::BORNES,
            ],
            [
                'model_slug' => 'rx-xu30',
                'engine_code' => '3MZ-FE',
                'year_from' => 2004,
                'year_to' => 2008,
                'notes' => 'Lexus RX 330 (3.3 l V6 3MZ-FE, 2004-2006) et RX 400h (3.3 l V6 hybride 3MZ-FE, 2006-2008). Sources : '
                    .self::SRC_LEXUS.' (parts.lexus.com, RX 400h 2007) ; '.self::SRC_LEXUSPARTSNOW.' (RX 330 2004-2006 3MZ-FE : 90915-20003 remplacé par 90915-YZZD1) ; '
                    .self::SRC_AUTOHAUS.' (RX330 2004-2006, RX400h 2006-2008) ; '.self::SRC_MUGEN.'. La RX 350 (2GR-FE) est exclue.'.self::BORNES,
            ],
            [
                'model_slug' => 'highlander',
                'engine_code' => '3MZ-FE',
                'year_from' => 2008,
                'year_to' => 2010,
                'notes' => 'Highlander Hybrid 3.3 l V6 3MZ-FE, 2008-2010 (seules années de ce 3MZ-FE dans le modèle au catalogue). Sources : '
                    .self::SRC_CAMELBACK.' (Highlander Hybrid 2008-2010, 3.3 l V6) ; '.self::SRC_SPARKS.' (« 2008-10 Highlander HV »). '
                    .'Recoupé par le catalogue de correspondance du 18/10/2026 déjà au référentiel, qui range le Highlander hybride 3.3 l 3MZ-FE sur la YZZD1. '
                    .'Le Highlander 3.5 l 2GR-FE prend la cartouche 04152-YZZA1 : exclu.'.self::BORNES,
            ],
        ];
    }
}
