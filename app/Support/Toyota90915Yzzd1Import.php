<?php

namespace App\Support;

use App\Enums\FitmentSource;
use App\Models\OemNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pose les compatibilités sourcées du filtre à huile vissé Toyota 90915-YZZD1
 * (article déjà au catalogue, sans aucune application) et rattache les
 * anciens numéros que Toyota déclare remplacés.
 *
 * Règles suivies :
 *  - l'article est retrouvé par SKU ; s'il manque ou est supprimé, rien n'est
 *    écrit, et un doublon fait refuser l'import ;
 *  - prix, stock, publication, nom et numéro OEM principal ne sont jamais touchés ;
 *  - seuls les modèles déjà au catalogue (par slug) reçoivent une ligne ;
 *  - chaque ligne est restreinte au CODE MOTEUR, car ce filtre dépend du
 *    moteur et non du nom du modèle (le Camry en 5S-FE prend un autre filtre,
 *    déjà posé sur le 90915-10001) ;
 *  - une ligne déjà présente (même modèle, finition, moteur et bornes) n'est
 *    ni dupliquée ni réécrite : le rejeu est sans effet.
 */
final class Toyota90915Yzzd1Import
{
    public const SKU = '90915-YZZD1';

    /**
     * Anciens numéros nommés « Previous Version(s) » par la fiche officielle
     * Toyota (autoparts.toyota.com) et par parts.lexus.com.
     *
     * @var list<string>
     */
    public const NUMEROS_REMPLACES = ['90915-YZZB1', '90915-YZZB9', '90915-YZZG2'];

    private const SRC_PDF = 'https://www.toyotapartsandservice.com/wp-content/uploads/2024/10/06_OilFilters2025_hi.pdf';

    private const SRC_TOYOTA = 'https://autoparts.toyota.com/products/product/filter-oil-90915yzzd1';

    private const SRC_PARTSADVISORY = 'https://www.partsadvisory.com/make-model/toyota-camry-1992-1996-3rd-generation-xv10-parts-fitment-guide';

    private const SRC_MUGEN = 'https://store.mugen-24.com/product/toyota-lexus-genuine-oil-filter-90915-yzzd1-oem/';

    private const SRC_AUTOHAUS = 'https://www.autohausaz.com/pn/90915YZZD1OE';

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
                'fitments_skipped_missing_trim' => [],
                'oem_superseded_linked' => 0,
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
            $touche = self::poserLesApplications($part, $rapport);
            $touche = self::rattacherLesNumerosRemplaces($part, $rapport) || $touche;

            if ($touche) {
                DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
            }
            $rapport['fitments_skipped_missing_model'] = array_values(array_unique($rapport['fitments_skipped_missing_model']));

            return $rapport;
        });
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    private static function poserLesApplications(object $part, array &$rapport): bool
    {
        $touche = false;

        foreach (self::applications() as $application) {
            $modele = DB::table('vehicle_models')->where('slug', $application['model_slug'])->first();
            if ($modele === null) {
                $rapport['fitments_skipped_missing_model'][] = $application['model_slug'];

                continue;
            }

            $trimId = null;
            if ($application['trim_code'] !== null) {
                $trimId = DB::table('trims')
                    ->where('vehicle_model_id', $modele->id)
                    ->where('code', $application['trim_code'])
                    ->value('id');
                if ($trimId === null) {
                    $rapport['fitments_skipped_missing_trim'][] = $application['model_slug'].':'.$application['trim_code'];

                    continue;
                }
            }

            $identite = [
                'part_id' => $part->id,
                'vehicle_model_id' => $modele->id,
                'trim_id' => $trimId,
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
            $touche = true;
        }

        return $touche;
    }

    /**
     * Rattache les numéros périmés : ils partent en `is_superseded` et
     * pointent sur le numéro actuel, comme le fait le seeder des filtres.
     * Un numéro déjà déclaré remplacé par une AUTRE référence n'est pas touché.
     *
     * @param  array<string, mixed>  $rapport
     */
    private static function rattacherLesNumerosRemplaces(object $part, array &$rapport): bool
    {
        $actuel = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize(self::SKU), 'brand_id' => null],
            ['number' => self::SKU, 'label' => (string) $part->name, 'is_superseded' => false],
        );

        $touche = false;
        foreach (self::NUMEROS_REMPLACES as $ancien) {
            $perime = OemNumber::firstOrCreate(
                ['normalized_number' => OemNumber::normalize($ancien), 'brand_id' => null],
                ['number' => $ancien, 'label' => (string) $part->name, 'is_superseded' => true, 'superseded_by_id' => $actuel->id],
            );

            if ($perime->superseded_by_id !== null && (int) $perime->superseded_by_id !== (int) $actuel->id) {
                continue;
            }
            if (! $perime->is_superseded || $perime->superseded_by_id === null) {
                $perime->update(['is_superseded' => true, 'superseded_by_id' => $actuel->id]);
            }

            $deja = DB::table('oem_number_part')
                ->where('part_id', $part->id)
                ->where('oem_number_id', $perime->id)
                ->exists();
            if ($deja) {
                continue;
            }
            DB::table('oem_number_part')->insert([
                'part_id' => $part->id,
                'oem_number_id' => $perime->id,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $rapport['oem_superseded_linked']++;
            $touche = true;
        }

        return $touche;
    }

    /**
     * @return list<array{model_slug: string, trim_code: ?string, engine_code: string, year_from: int, year_to: int, notes: string}>
     */
    public static function applications(): array
    {
        $tacomaI = ' Source officielle : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, Tacoma 1995-2004 : V6 5VZFE et L4 2RZFE / 3RZFE → 90915-YZZD1) ; recoupé par '
            .self::SRC_MUGEN.' et '.self::SRC_AUTOHAUS.'.';
        $tacoma2Tr = ' Source officielle : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, Tacoma L4 2TRFE 2005-2006, 2007-2010 et 2011- → 90915-YZZD1) ; '
            .'le V6 1GRFE (YZZD3) et le V6 2GRFKS (04152-YZZA1) sont exclus.';

        return [
            [
                'model_slug' => 'camry-xv10',
                'trim_code' => null,
                'engine_code' => '3VZ-FE',
                'year_from' => 1992,
                'year_to' => 1993,
                'notes' => 'Camry V6 3.0 l 3VZ-FE, 1992-1993 (XV10, Amérique du Nord). Sources : '.self::SRC_MUGEN.' ; '.self::SRC_YOTASHOP.' ; '
                    .'moteur et années : '.self::SRC_PARTSADVISORY.'. Le catalogue officiel Toyota ne remonte pas avant 1997 : application documentée par des revendeurs seulement. '
                    .'Le 3VZ-FE 1994-1996 des autres marchés (Japon, Australie, Europe) n\'est pas posé faute de source pour ce filtre. Le Camry 4 cylindres (5S-FE) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'camry-xv10',
                'trim_code' => null,
                'engine_code' => '1MZ-FE',
                'year_from' => 1994,
                'year_to' => 1996,
                'notes' => 'Camry V6 3.0 l 1MZ-FE, 1994-1996 (XV10, Amérique du Nord ; le 1MZ-FE remplace le 3VZ-FE dès l\'année-modèle 1994). Sources : '.self::SRC_MUGEN.' ; '
                    .self::SRC_YOTASHOP.' ; moteur et années : '.self::SRC_PARTSADVISORY.'. Le catalogue officiel Toyota ne remonte pas avant 1997 : application documentée par des revendeurs seulement. '
                    .'Le Camry 4 cylindres (5S-FE) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'camry-xv20',
                'trim_code' => null,
                'engine_code' => '1MZ-FE',
                'year_from' => 1997,
                'year_to' => 2001,
                'notes' => 'Camry V6 3.0 l 1MZ-FE, 1997-2001 (XV20). Source officielle : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, Camry 1997-2006 V6 1MZFE / 3MZFE → 90915-YZZD1) ; '
                    .'recoupé par '.self::SRC_MUGEN.' et '.self::SRC_AUTOHAUS.'. Le Camry 4 cylindres (5S-FE) prend la YZZN1 : exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i',
                'trim_code' => null,
                'engine_code' => '2RZ-FE',
                'year_from' => 1995,
                'year_to' => 2004,
                'notes' => 'Tacoma 2.4 l 2RZ-FE, 1995-2004.'.$tacomaI.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i',
                'trim_code' => null,
                'engine_code' => '3RZ-FE',
                'year_from' => 1995,
                'year_to' => 2004,
                'notes' => 'Tacoma 2.7 l 3RZ-FE, 1995-2004.'.$tacomaI.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i',
                'trim_code' => null,
                'engine_code' => '5VZ-FE',
                'year_from' => 1995,
                'year_to' => 2004,
                'notes' => 'Tacoma V6 3.4 l 5VZ-FE, 1995-2004.'.$tacomaI.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-ii',
                'trim_code' => null,
                'engine_code' => '2TR-FE',
                'year_from' => 2005,
                'year_to' => 2015,
                'notes' => 'Tacoma 2.7 l 2TR-FE, 2005-2015 (génération II).'.$tacoma2Tr.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-iii',
                'trim_code' => null,
                'engine_code' => '2TR-FE',
                'year_from' => 2016,
                'year_to' => 2023,
                'notes' => 'Tacoma 2.7 l 2TR-FE, 2016-2023 (génération III).'.$tacoma2Tr.self::BORNES,
            ],
            [
                'model_slug' => 'rx-xu30',
                'trim_code' => '330',
                'engine_code' => '3MZ-FE',
                'year_from' => 2004,
                'year_to' => 2006,
                'notes' => 'Lexus RX 330, 3.3 l V6 3MZ-FE, 2004-2006. Sources : '.self::SRC_LEXUSPARTSNOW.' (RX 330 2004-2006 3MZFE : 90915-20003 remplacé par 90915-YZZD1) ; '
                    .self::SRC_AUTOHAUS.' ; '.self::SRC_MUGEN.'. Le catalogue officiel Toyota ne couvre pas Lexus. La RX 350 (2GR-FE) est exclue.'.self::BORNES,
            ],
            [
                'model_slug' => 'rx-xu30',
                'trim_code' => '400h',
                'engine_code' => '3MZ-FE',
                'year_from' => 2006,
                'year_to' => 2008,
                'notes' => 'Lexus RX 400h, 3.3 l V6 hybride 3MZ-FE, 2006-2008. Sources : '.self::SRC_LEXUS.' (parts.lexus.com, RX 400h 2007) ; '
                    .self::SRC_AUTOHAUS.' (RX400h 2006-2008) ; '.self::SRC_MUGEN.'. Le catalogue officiel Toyota ne couvre pas Lexus.'.self::BORNES,
            ],
            [
                'model_slug' => 'highlander',
                'trim_code' => null,
                'engine_code' => '3MZ-FE',
                'year_from' => 2008,
                'year_to' => 2010,
                'notes' => 'Highlander Hybrid 3.3 l V6 3MZ-FE, 2008-2010. Source officielle : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, Highlander/HV 2008-2010 V6 3MZFE HV → 90915-YZZD1) ; '
                    .'recoupé par '.self::SRC_CAMELBACK.'. Le Highlander V6 2GR-FE de ces années prend la cartouche 04152-YZZA1 : exclu.'.self::BORNES,
            ],
        ];
    }
}
