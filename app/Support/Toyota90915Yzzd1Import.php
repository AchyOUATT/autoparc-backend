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

    private const SRC_LEXUS = 'https://parts.lexus.com/p/Lexus_2007_RX-400h/Engine-Oil-Filter/69396229/90915YZZD1.html';

    private const SRC_LEXUSPARTSNOW = 'https://www.lexuspartsnow.com/oem-lexus-rx330-oil_filter.html';

    /** EPC Toyota (catalogues constructeur, périodes de production par châssis). */
    private const SRC_EPC = 'https://toyota-epc.aftermarketcatalog.com';

    /** EPC Toyota, recherche inverse par numéro, marché Amérique du Nord. */
    private const SRC_XREF_US_20001 = 'https://www.toyodiy.com/parts/xref?mU=on&s=90915-20001';

    private const SRC_XREF_US_20003 = 'https://www.toyodiy.com/parts/xref?mU=on&s=90915-20003';

    /** Remplacement 90915-20003 → 90915-YZZD1 (catalogue concessionnaire Toyota). */
    private const SRC_REMPLACEMENT = 'https://www.toyotapartsdeal.com/oem-toyota-4runner-oil_filter.html';

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
                'chassis_code' => implode('/', $application['chassis_codes']),
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
     * Applications documentées.
     *
     * CONVENTION DES ANNÉES (revue du 26/11/2026) : `year_from` / `year_to`
     * sont des années civiles de FABRICATION, car l'API compare ces bornes à
     * `manufacturing_year` (« Année de fabrication » dans l'application),
     * jamais à `model_year`. Elles sont tirées des dates de production du
     * catalogue constructeur (EPC Toyota) pour le châssis et le moteur, et
     * restent dans les bornes de la génération ; quand une génération déjà
     * au référentiel porte des bornes plus étroites que l'EPC (Tacoma II
     * dès 2005, Highlander nu dès 2008), la ligne s'arrête à ces bornes et la
     * note donne les mois exacts. Le châssis va dans `chassis_code` ; le
     * marché, qui n'a pas de colonne, va dans la note.
     *
     * @return list<array{model_slug: string, trim_code: ?string, engine_code: string, year_from: int, year_to: int, chassis_codes: list<string>, notes: string}>
     */
    public static function applications(): array
    {
        $epc = fn (string $catalogue, string $periode): string => ' Période EPC Toyota (catalogue '.$catalogue.', '.self::SRC_EPC.'/vehicle/US/'.$catalogue.'/info) : '.$periode.'.';
        $pdf = fn (string $ligne): string => ' Source constructeur : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, années-modèle US, '.$ligne.' → 90915-YZZD1).';
        $remplace = ' 90915-20001 / 90915-20003 / 90915-03002 sont remplacés par 90915-YZZD1 ('.self::SRC_REMPLACEMENT.').';

        return [
            [
                'model_slug' => 'camry-xv10', 'trim_code' => null, 'engine_code' => '3VZ-FE',
                'year_from' => 1991, 'year_to' => 1993, 'chassis_codes' => ['VCV10'],
                'notes' => 'Camry V6 3.0 l 3VZ-FE, châssis VCV10, marché Amérique du Nord (USA).'
                    .$epc('283450', 'VCV10L NAP 09/1991-09/1993 ; catalogue 281450 JPP 06/1991-08/1993')
                    .' EPC Amérique du Nord : filtre 90915-20001 / 90915-03002 au schéma 15-02 des SXV10/VCV10/MCV10 ('.self::SRC_XREF_US_20001.').'.$remplace
                    .' Le catalogue PDF Toyota ne remonte pas avant 1997. Les 3VZ-FE des autres marchés ne sont pas posés. Le 4 cylindres 5S-FE (SXV10) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'camry-xv10', 'trim_code' => null, 'engine_code' => '1MZ-FE',
                'year_from' => 1993, 'year_to' => 1996, 'chassis_codes' => ['MCV10'],
                'notes' => 'Camry V6 3.0 l 1MZ-FE, châssis MCV10, marché Amérique du Nord (USA).'
                    .$epc('283450', 'MCV10L NAP 09/1993-07/1996 ; catalogue 281450 JPP 08/1993-08/1996')
                    .' EPC Amérique du Nord : filtre 90915-20001 / 90915-03002 au schéma 15-02 ('.self::SRC_XREF_US_20001.').'.$remplace
                    .' Le 4 cylindres 5S-FE (SXV10) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'camry-xv20', 'trim_code' => null, 'engine_code' => '1MZ-FE',
                'year_from' => 1996, 'year_to' => 2001, 'chassis_codes' => ['MCV20'],
                'notes' => 'Camry V6 3.0 l 1MZ-FE, châssis MCV20, marché Amérique du Nord (USA).'.$pdf('Camry 1997-2006 V6 1MZFE / 3MZFE')
                    .$epc('283460', 'MCV20L NAP 07/1996-07/2001 ; catalogue 281460 JPP 08/1996-07/2001')
                    .' EPC Amérique du Nord : 90915-20001 puis 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace
                    .' Le 4 cylindres 5S-FE (SXV20) prend la YZZN1 : exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i', 'trim_code' => null, 'engine_code' => '2RZ-FE',
                'year_from' => 1995, 'year_to' => 2004, 'chassis_codes' => ['RZN140', 'RZN150', 'RZN161', 'RZN171', 'RZN191', 'RZN196'],
                'notes' => 'Tacoma 2.4 l 2RZ-FE, marché USA.'.$pdf('Tacoma 1995-2004 L4 2RZFE / 3RZFE')
                    .$epc('675460 et 674460', 'RZN140/150/161/171 01/1995-09/2004, RZN191 08/1998-09/2004, RZN196 02/1998-09/2004')
                    .' Ces listes EPC ne séparent pas 2RZ-FE et 3RZ-FE par châssis : contrôler le moteur.'
                    .' EPC Amérique du Nord : 90915-20001 / 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i', 'trim_code' => null, 'engine_code' => '3RZ-FE',
                'year_from' => 1995, 'year_to' => 2004, 'chassis_codes' => ['RZN140', 'RZN150', 'RZN161', 'RZN171', 'RZN191', 'RZN196'],
                'notes' => 'Tacoma 2.7 l 3RZ-FE, marché USA.'.$pdf('Tacoma 1995-2004 L4 2RZFE / 3RZFE')
                    .$epc('675460 et 674460', 'RZN140/150/161/171 01/1995-09/2004, RZN191 08/1998-09/2004, RZN196 02/1998-09/2004')
                    .' Ces listes EPC ne séparent pas 2RZ-FE et 3RZ-FE par châssis : contrôler le moteur.'
                    .' EPC Amérique du Nord : 90915-20001 / 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-i', 'trim_code' => null, 'engine_code' => '5VZ-FE',
                'year_from' => 1995, 'year_to' => 2004, 'chassis_codes' => ['VZN150', 'VZN160', 'VZN170', 'VZN195'],
                'notes' => 'Tacoma V6 3.4 l 5VZ-FE, marché USA.'.$pdf('Tacoma 1995-2004 V6 5VZFE')
                    .$epc('675460 et 674460', 'VZN150/170 01/1995-09/2004, VZN160 01/1995-06/1997, VZN195 02/1998-09/2004')
                    .' EPC Amérique du Nord : 90915-20001 / 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-ii', 'trim_code' => null, 'engine_code' => '2TR-FE',
                'year_from' => 2005, 'year_to' => 2015, 'chassis_codes' => ['TRN220', 'TRN225', 'TRN240', 'TRN245', 'TRN260', 'TRN265'],
                'notes' => 'Tacoma 2.7 l 2TR-FE, génération II, marché USA.'.$pdf('Tacoma L4 2TRFE 2005-2006, 2007-2010 et 2011-')
                    .$epc('674470', 'TRN2## à partir de 09/2004, génération III à partir de 08/2015')
                    .' Bornes arrêtées à celles de la génération au référentiel (2005-2015) : un Tacoma II fabriqué de 09 à 12/2004 reste à vérifier au VIN.'
                    .' EPC Amérique du Nord : 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace
                    .' Les V6 1GR-FE (YZZD3) et 2GR-FKS (04152-YZZA1) sont exclus.'.self::BORNES,
            ],
            [
                'model_slug' => 'tacoma-iii', 'trim_code' => null, 'engine_code' => '2TR-FE',
                'year_from' => 2016, 'year_to' => 2023, 'chassis_codes' => ['TRN245', 'TRN265'],
                'notes' => 'Tacoma 2.7 l 2TR-FE, génération III, marché USA.'.$pdf('Tacoma L4 2TRFE 2011-')
                    .$epc('674470', 'génération III à partir de 08/2015 ; Tacoma IV (TZNA4#) à partir de 12/2023')
                    .' Bornes arrêtées à celles de la génération au référentiel (2016-2023) : un Tacoma III fabriqué de 08 à 12/2015 reste à vérifier au VIN.'
                    .' EPC Amérique du Nord : 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace
                    .' Le V6 2GR-FKS (04152-YZZA1) est exclu.'.self::BORNES,
            ],
            [
                'model_slug' => 'rx-xu30', 'trim_code' => '330', 'engine_code' => '3MZ-FE',
                'year_from' => 2003, 'year_to' => 2006, 'chassis_codes' => ['MCU33', 'MCU38'],
                'notes' => 'Lexus RX 330, 3.3 l V6 3MZ-FE, châssis MCU33/MCU38, marché USA.'
                    .$epc('521420', 'MCU33L/MCU38L 02/2003-01/2006 ; catalogue 523410 09/2003-02/2006')
                    .' EPC Amérique du Nord : 90915-20003 au schéma 15-02 des RX330/350 MCU3# ('.self::SRC_XREF_US_20003.') ; '
                    .self::SRC_LEXUSPARTSNOW.' (90915-20003 remplacé par 90915-YZZD1). Le catalogue PDF Toyota ne couvre pas Lexus. La RX 350 (2GR-FE, GSU3#) est exclue.'.self::BORNES,
            ],
            [
                'model_slug' => 'rx-xu30', 'trim_code' => '400h', 'engine_code' => '3MZ-FE',
                'year_from' => 2005, 'year_to' => 2008, 'chassis_codes' => ['MHU33', 'MHU38'],
                'notes' => 'Lexus RX 400h, 3.3 l V6 hybride 3MZ-FE, châssis MHU33/MHU38, marché USA.'
                    .$epc('524410', 'MHU33L/MHU38L 03/2005-12/2008')
                    .' EPC Amérique du Nord : 90915-20003 au schéma 15-02 des RX400H MHU3# 03/2005-11/2008 ('.self::SRC_XREF_US_20003.') ; '
                    .self::SRC_LEXUS.' (parts.lexus.com, RX 400h 2007 : 90915-YZZD1). Le catalogue PDF Toyota ne couvre pas Lexus.'.self::BORNES,
            ],
            [
                'model_slug' => 'highlander', 'trim_code' => null, 'engine_code' => '3MZ-FE',
                'year_from' => 2008, 'year_to' => 2010, 'chassis_codes' => ['MHU48'],
                'notes' => 'Highlander Hybrid 3.3 l V6 3MZ-FE, châssis MHU48, marché USA.'.$pdf('Highlander/HV 2008-2010 V6 3MZFE HV')
                    .$epc('522420', 'MHU48L 08/2007-08/2010')
                    .' Bornes arrêtées au début de la ligne `highlander` du référentiel (2008) : un MHU48 fabriqué de 08 à 12/2007 reste à vérifier au VIN.'
                    .' EPC Amérique du Nord : 90915-20003 au schéma 15-02 ('.self::SRC_XREF_US_20003.').'.$remplace
                    .' Le Highlander V6 2GR-FE (GSU4#) prend la cartouche 04152-YZZA1 : exclu.'.self::BORNES,
            ],
        ];
    }
}
