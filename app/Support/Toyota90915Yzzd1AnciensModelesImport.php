<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pose les générations anciennes Toyota / Lexus qui manquaient au
 * référentiel, puis les applications sourcées du filtre 90915-YZZD1 sur ces
 * seules générations.
 *
 *  - Modèles : lus dans `VehicleModelsSeeder::GENERATIONS_FILTRE_YZZD1`
 *    (une seule définition) et insérés uniquement si le couple (marque,
 *    slug) manque — jamais de mise à jour d'une ligne existante.
 *  - Applications : restreintes au CODE MOTEUR ; une ligne identique déjà
 *    présente n'est ni dupliquée ni réécrite. Prix, stock, nom et numéros
 *    OEM de l'article ne sont jamais touchés.
 */
final class Toyota90915Yzzd1AnciensModelesImport
{
    public const SKU = '90915-YZZD1';

    private const SRC_PDF = 'https://www.toyotapartsandservice.com/wp-content/uploads/2024/10/06_OilFilters2025_hi.pdf';

    private const SRC_AUTOHAUS = 'https://www.autohausaz.com/pn/90915YZZD1OE';

    private const SRC_MUGEN = 'https://store.mugen-24.com/product/toyota-lexus-genuine-oil-filter-90915-yzzd1-oem/';

    private const BORNES = ' Bornes annuelles : contrôler le VIN, le code moteur et la date de fabrication aux bornes.';

    /** @return array<string, mixed> */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'models_created' => [],
                'part_id' => null,
                'fitments_inserted' => 0,
                'fitments_already_present' => 0,
                'fitments_skipped_missing_model' => [],
            ];

            self::poserLesModeles($rapport);

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
     * @param  array<string, mixed>  $rapport
     */
    private static function poserLesModeles(array &$rapport): void
    {
        foreach (VehicleModelsSeeder::GENERATIONS_FILTRE_YZZD1 as [$marque, $nom, $gen, $carrosserie, $segment, $de, $a]) {
            $brandId = DB::table('brands')->where('slug', $marque)->value('id');
            if ($brandId === null) {
                continue;
            }
            $slug = Str::slug($nom.'-'.$gen);
            if (DB::table('vehicle_models')->where('brand_id', $brandId)->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('vehicle_models')->insert([
                'brand_id' => $brandId,
                'name' => $nom,
                'slug' => $slug,
                'generation' => $gen,
                'body_type' => $carrosserie,
                'segment' => $segment,
                'production_start' => $de,
                'production_end' => $a,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $rapport['models_created'][] = $slug;
        }
    }

    /**
     * @return list<array{model_slug: string, engine_code: string, year_from: int, year_to: int, notes: string}>
     */
    public static function applications(): array
    {
        $officiel = fn (string $ligne): string => ' Source officielle : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, '.$ligne.' → 90915-YZZD1).';
        $lexus = fn (string $ligne): string => ' Sources revendeurs (le catalogue officiel Toyota ne couvre pas Lexus) : '.self::SRC_AUTOHAUS.' ('.$ligne.') ; '.self::SRC_MUGEN.'.';

        return [
            ['model_slug' => 'camry-xv30', 'engine_code' => '1MZ-FE', 'year_from' => 2002, 'year_to' => 2006,
                'notes' => 'Camry V6 3.0 l 1MZ-FE, 2002-2006 (XV30).'.$officiel('Camry 1997-2006 V6 1MZFE / 3MZFE').' Les 4 cylindres 1AZ-FE / 2AZ-FE prennent la YZZF1 : exclus.'.self::BORNES],
            ['model_slug' => 'camry-xv30', 'engine_code' => '3MZ-FE', 'year_from' => 2004, 'year_to' => 2006,
                'notes' => 'Camry V6 3.3 l 3MZ-FE, 2004-2006 (XV30 SE ; années du moteur selon Wikipedia « Toyota Camry (XV30) »).'.$officiel('Camry 1997-2006 V6 1MZFE / 3MZFE').self::BORNES],
            ['model_slug' => 'avalon-xx10', 'engine_code' => '1MZ-FE', 'year_from' => 1995, 'year_to' => 1999,
                'notes' => 'Avalon V6 3.0 l 1MZ-FE, 1995-1999 (XX10).'.$officiel('Avalon 1995-2004 V6 1MZFE').self::BORNES],
            ['model_slug' => 'avalon-xx20', 'engine_code' => '1MZ-FE', 'year_from' => 2000, 'year_to' => 2004,
                'notes' => 'Avalon V6 3.0 l 1MZ-FE, 2000-2004 (XX20).'.$officiel('Avalon 1995-2004 V6 1MZFE').self::BORNES],
            ['model_slug' => 'highlander-xu20', 'engine_code' => '1MZ-FE', 'year_from' => 2001, 'year_to' => 2003,
                'notes' => 'Highlander V6 3.0 l 1MZ-FE, 2001-2003 (XU20).'.$officiel('Highlander 2001-2003 V6 1MZFE').' Le 4 cylindres 2AZ-FE prend la YZZF1 : exclu.'.self::BORNES],
            ['model_slug' => 'highlander-xu20', 'engine_code' => '3MZ-FE', 'year_from' => 2004, 'year_to' => 2007,
                'notes' => 'Highlander V6 3.3 l 3MZ-FE, 2004-2007 (XU20, MCU2#), y compris le Highlander Hybrid 2006-2007 (MHU2#).'.$officiel('Highlander 2004-2007 V6 3MZFE MCU2# et 2006-2007 V6 3MZFE MHU2# HV').self::BORNES],
            ['model_slug' => 'sienna-xl10', 'engine_code' => '1MZ-FE', 'year_from' => 1998, 'year_to' => 2003,
                'notes' => 'Sienna V6 3.0 l 1MZ-FE, 1998-2003 (XL10, seul moteur de la génération selon Wikipedia « Toyota Sienna »).'.$officiel('Sienna 1998-2006 V6 1MZFE / 3MZFE').self::BORNES],
            ['model_slug' => '4runner-n180', 'engine_code' => '5VZ-FE', 'year_from' => 1996, 'year_to' => 2002,
                'notes' => '4Runner V6 3.4 l 5VZ-FE, 1996-2002 (N180).'.$officiel('4Runner 1996-1997 et 1998-2002 V6 5VZFE').self::BORNES],
            ['model_slug' => '4runner-n180', 'engine_code' => '3RZ-FE', 'year_from' => 1996, 'year_to' => 2000,
                'notes' => '4Runner 2.7 l 3RZ-FE, 1996-2000 (N180).'.$officiel('4Runner L4 3RZFE 1996-1998, 1998 et 1999-2000').' Les diesels 1KZ-TE ne sont pas au catalogue officiel : exclus.'.self::BORNES],
            ['model_slug' => '4runner-n280', 'engine_code' => '2TR-FE', 'year_from' => 2010, 'year_to' => 2024,
                'notes' => '4Runner 2.7 l 2TR-FE (TRN280), à partir de 2010 (N280 ; Wikipedia ne donne ce moteur qu\'en 2009-2010).'.$officiel('4Runner 2010- L4 2TRFE').' Le V6 1GR-FE prend la cartouche 04152-YZZA5 : exclu.'.self::BORNES],
            ['model_slug' => 'tundra-i', 'engine_code' => '5VZ-FE', 'year_from' => 2000, 'year_to' => 2004,
                'notes' => 'Tundra V6 3.4 l 5VZ-FE, 2000-2004 (1re génération).'.$officiel('Tundra 2000-2004 V6 5VZFE').' Le V8 2UZ-FE et le V6 1GR-FE prennent la 90915-YZZD3 : exclus.'.self::BORNES],
            ['model_slug' => 'solara-xv20', 'engine_code' => '1MZ-FE', 'year_from' => 1999, 'year_to' => 2003,
                'notes' => 'Solara V6 3.0 l 1MZ-FE (MCV20), 1999-2003 (XV20).'.$officiel('Solara 1999-2008 V6 1MZFE / 3MZFE').' Les 4 cylindres 5S-FE (YZZN1) et 2AZ-FE (YZZF1) sont exclus.'.self::BORNES],
            ['model_slug' => 'solara-xv30', 'engine_code' => '3MZ-FE', 'year_from' => 2004, 'year_to' => 2008,
                'notes' => 'Solara V6 3.3 l 3MZ-FE (MCV30), 2004-2008 (XV30).'.$officiel('Solara 1999-2008 V6 1MZFE / 3MZFE').' Le 4 cylindres 2AZ-FE (YZZF1) est exclu.'.self::BORNES],
            ['model_slug' => 'supra-a80', 'engine_code' => '2JZ-GE', 'year_from' => 1994, 'year_to' => 1998,
                'notes' => 'Supra 3.0 l 2JZ-GE, 1994-1998 (A80, années-modèle nord-américaines).'.$officiel('Supra 1994-1998 L6 2JZGE').' Les Supra japonaises 1993 et 1999-2002 ne sont pas couvertes par cette source.'.self::BORNES],
            ['model_slug' => 'supra-a80', 'engine_code' => '2JZ-GTE', 'year_from' => 1994, 'year_to' => 1998,
                'notes' => 'Supra 3.0 l biturbo 2JZ-GTE, 1994-1998 (A80, années-modèle nord-américaines).'.$officiel('Supra 1994-1996 et 1997-1998 L6 2JZGTE').' Les Supra japonaises 1993 et 1999-2002 ne sont pas couvertes par cette source.'.self::BORNES],
            ['model_slug' => 'es-xv20', 'engine_code' => '1MZ-FE', 'year_from' => 1997, 'year_to' => 2001,
                'notes' => 'Lexus ES 300 V6 3.0 l 1MZ-FE, 1997-2001 (XV20).'.$lexus('ES300 1992-2003, vissé').' Le 2MZ-FE japonais (Windom) n\'est pas couvert : exclu.'.self::BORNES],
            ['model_slug' => 'es-xv30', 'engine_code' => '1MZ-FE', 'year_from' => 2002, 'year_to' => 2003,
                'notes' => 'Lexus ES 300 V6 3.0 l 1MZ-FE, 2002-2003 (XV30).'.$lexus('ES300 1992-2003, vissé').self::BORNES],
            ['model_slug' => 'es-xv30', 'engine_code' => '3MZ-FE', 'year_from' => 2004, 'year_to' => 2006,
                'notes' => 'Lexus ES 330 V6 3.3 l 3MZ-FE, 2004-2006 (XV30).'.$lexus('ES330 2004-2006, vissé').self::BORNES],
            ['model_slug' => 'gs-s160', 'engine_code' => '2JZ-GE', 'year_from' => 1998, 'year_to' => 2005,
                'notes' => 'Lexus GS 300 3.0 l 2JZ-GE, 1998-2005 (S160 ; millésime 2005 fabriqué jusqu\'en 12/2004).'.$lexus('GS300 1993-2004, et 2005 « To 12/04 »').' Les V8 GS 400 / 430 et le 2JZ-GTE de l\'Aristo japonaise ne sont pas couverts : exclus.'.self::BORNES],
            ['model_slug' => 'is-xe10', 'engine_code' => '2JZ-GE', 'year_from' => 2001, 'year_to' => 2005,
                'notes' => 'Lexus IS 300 3.0 l 2JZ-GE, 2001-2005 (XE10).'.$lexus('IS300 2001-2005, vissé').' L\'IS 200 (1G-FE) n\'est pas couvert : exclu.'.self::BORNES],
            ['model_slug' => 'rx-xu10', 'engine_code' => '1MZ-FE', 'year_from' => 1999, 'year_to' => 2003,
                'notes' => 'Lexus RX 300 V6 3.0 l 1MZ-FE, 1999-2003 (XU10).'.$lexus('RX300 1999-2003, traction et intégrale').' Les 4 cylindres du Harrier japonais ne sont pas couverts : exclus.'.self::BORNES],
        ];
    }
}
