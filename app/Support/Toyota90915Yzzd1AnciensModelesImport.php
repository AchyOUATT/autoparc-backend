<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 *  - Applications : restreintes au CODE MOTEUR, avec le CHÂSSIS de l'EPC
 *    Toyota ; une ligne identique déjà présente n'est ni dupliquée ni
 *    réécrite. Années = années de fabrication (voir `applications()`) ;
 *    documentation : docs/compatibilites-toyota-90915-yzzd1.md.
 *  - Prix, stock, nom et numéros OEM de l'article ne sont jamais touchés.
 */
final class Toyota90915Yzzd1AnciensModelesImport
{
    public const SKU = '90915-YZZD1';

    private const SRC_PDF = 'https://www.toyotapartsandservice.com/wp-content/uploads/2024/10/06_OilFilters2025_hi.pdf';

    /** EPC Toyota : périodes de production par châssis et par marché. */
    private const SRC_EPC = 'https://toyota-epc.aftermarketcatalog.com';

    /** EPC Toyota, recherche inverse par numéro (schéma 15-02, filtre à huile). */
    private const XREF_US_20001 = 'https://www.toyodiy.com/parts/xref?mU=on&s=90915-20001';

    private const XREF_US_20003 = 'https://www.toyodiy.com/parts/xref?mU=on&s=90915-20003';

    private const XREF_GEN_20003 = 'https://www.toyodiy.com/parts/xref?mG=on&s=90915-20003';

    /** Schémas EPC 15-02 relus châssis par châssis. */
    private const SCHEMA_4RUNNER_TRN280 = 'https://www.toyodiy.com/parts/p_U_200911_TOYOTA_4RUNNER_TRN280L-GKPGKA_1502.html';

    private const SCHEMA_4RUNNER_TRN285 = 'https://www.toyodiy.com/parts/p_U_201008_TOYOTA_4RUNNER_TRN285L-GKPGKA_1502.html';

    private const SCHEMA_HIGHLANDER = 'https://www.toyodiy.com/parts/p_U_200308_TOYOTA_HIGHLANDER_MCU23L-BWANKA_1502.html';

    private const SCHEMA_CAMRY_MCV31 = 'https://japan-parts.eu/toyota/us/2006/camry-nap/mcv31l-ceaska/3_283470_010_/tool-engine-fuel/1502_oil-filter';

    private const SCHEMA_ES_MCV20 = 'https://www.toyodiy.com/parts/p_U_2000_LEXUS_ES300_MCV20L-BTPGKA_1502.html';

    private const SCHEMA_ES_MCV30 = 'https://www.toyodiy.com/parts/p_U_2002_LEXUS_ES300~330_MCV30L-BEAGKA_1502.html';

    private const SCHEMA_GS_JZS160 = 'https://www.toyodiy.com/parts/p_U_199708_LEXUS_GS300~400~430_JZS160L-BEAQFA_1502.html';

    private const SCHEMA_IS_JCE10 = 'https://www.toyodiy.com/parts/p_U_200403_LEXUS_IS300_JCE10L-AEAVFA_1502.html';

    private const SCHEMA_RX_MCU10 = 'https://www.toyodiy.com/parts/p_U_1999_LEXUS_RX300_MCU10L-AWPGKA_1502.html';

    /** Concessionnaire Lexus : MCV31L-BEAGKA → 90915-20003, remplacé par 90915-YZZD1. */
    private const LEXUS_ES330 = 'https://www.lexuspartsnow.com/oem-lexus-es330-oil_filter.html';

    /** Concessionnaire Toyota : 90915-20001 / 20003 « Replaced by 90915-YZZD1 ». */
    private const SRC_REMPLACEMENT = 'https://www.toyotapartsdeal.com/oem-toyota-4runner-oil_filter.html';

    private const BORNES = ' Bornes annuelles : contrôler le VIN, le code moteur et la date de fabrication aux bornes.';

    /**
     * Preuve structurée d'une application, tirée de ses propres sources
     * (même règle que Toyota90915Yzzd1Import::preuve) : URL citées par la
     * note ; manufacturer_official si le catalogue PDF Toyota des filtres
     * nomme la YZZD1 pour ce moteur, sinon dealer_catalog (schéma EPC de
     * l'ancien numéro + remplacement attesté par les concessions).
     *
     * @param  array<string, mixed>  $application
     * @return array{evidence_level: string, evidence_sources: list<string>}
     */
    public static function preuve(array $application): array
    {
        return [
            'evidence_level' => str_contains($application['notes'], self::SRC_PDF)
                ? FitmentEvidence::MANUFACTURER_OFFICIAL : FitmentEvidence::DEALER_CATALOG,
            'evidence_sources' => FitmentEvidence::urlsInText($application['notes']),
        ];
    }

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
                'fitments_evidence_qualified' => 0,
            ];

            self::poserLesModeles($rapport);
            $avecPreuve = Schema::hasColumn('part_fitments', 'evidence_level') && Schema::hasColumn('part_fitments', 'evidence_sources');

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
                $preuve = $avecPreuve ? self::preuve($application) : [];
                if (DB::table('part_fitments')->where($identite)->lockForUpdate()->exists()) {
                    $rapport['fitments_already_present']++;
                    // La migration 160000 insère avant que la 200000 crée les
                    // colonnes de preuve : la 202000 rejoue l'import pour
                    // qualifier SES lignes (même identité, même note, sans preuve).
                    if ($preuve !== []) {
                        $rapport['fitments_evidence_qualified'] += DB::table('part_fitments')->where($identite)
                            ->where('notes', $application['notes'])
                            ->whereNull('evidence_level')->whereNull('evidence_sources')
                            ->update(['evidence_level' => $preuve['evidence_level'], 'evidence_sources' => json_encode($preuve['evidence_sources'])]);
                    }

                    continue;
                }

                DB::table('part_fitments')->insert([
                    ...$identite,
                    'position' => null,
                    'notes' => $application['notes'],
                    'source' => FitmentSource::Declared->value,
                    'chassis_code' => implode('/', $application['chassis_codes']),
                    ...($preuve === [] ? [] : [
                        'evidence_level' => $preuve['evidence_level'],
                        'evidence_sources' => json_encode($preuve['evidence_sources']),
                    ]),
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
    /**
     * Inverse exact de run(), pour le down() de la migration 160000.
     *
     * Retire ses lignes de compatibilité (même identité ET même note : une
     * ligne modifiée à la main depuis reste), puis les générations qu'il crée,
     * seulement quand plus rien ne les référence (compatibilité, véhicule du
     * parc ou du garage, finition, motorisation, annonce, besoin client,
     * accessoire, numéro OEM). Une génération rattachée entre-temps reste.
     *
     * @return array{fitments_removed: int, models_removed: list<string>, models_kept: list<string>}
     */
    public static function retirer(): array
    {
        return DB::transaction(function (): array {
            $rapport = ['fitments_removed' => 0, 'models_removed' => [], 'models_kept' => []];
            $part = DB::table('parts')->where('sku', self::SKU)->whereNull('owner_partner_account_id')->first();
            if ($part !== null) {
                foreach (self::applications() as $application) {
                    $modeleId = DB::table('vehicle_models')->where('slug', $application['model_slug'])->value('id');
                    if ($modeleId === null) {
                        continue;
                    }
                    $rapport['fitments_removed'] += DB::table('part_fitments')->where([
                        'part_id' => $part->id, 'vehicle_model_id' => $modeleId, 'trim_id' => null,
                        'engine_type_id' => null, 'drivetrain_id' => null, 'engine_code' => $application['engine_code'],
                        'year_from' => $application['year_from'], 'year_to' => $application['year_to'],
                        'notes' => $application['notes'], 'source' => FitmentSource::Declared->value,
                    ])->delete();
                }
            }

            $references = ['part_fitments', 'vehicles', 'owned_vehicles', 'trims', 'motorisations', 'private_vehicle_listings',
                'customer_needs', 'accessory_fitments', 'oem_number_fitments'];
            foreach (VehicleModelsSeeder::GENERATIONS_FILTRE_YZZD1 as [$marque, $nom, $gen]) {
                $brandId = DB::table('brands')->where('slug', $marque)->value('id');
                $slug = Str::slug($nom.'-'.$gen);
                $id = $brandId === null ? null : DB::table('vehicle_models')->where('brand_id', $brandId)->where('slug', $slug)->value('id');
                if ($id === null) {
                    continue;
                }
                $utilisee = false;
                foreach ($references as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'vehicle_model_id')
                        && DB::table($table)->where('vehicle_model_id', $id)->exists()) {
                        $utilisee = true;
                        break;
                    }
                }
                if ($utilisee) {
                    $rapport['models_kept'][] = $slug;

                    continue;
                }
                DB::table('vehicle_models')->where('id', $id)->delete();
                $rapport['models_removed'][] = $slug;
            }

            return $rapport;
        });
    }

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
     * Applications documentées, une par génération et par moteur.
     *
     * CONVENTION DES ANNÉES : `year_from` / `year_to` sont des années civiles
     * de FABRICATION, car l'API compare ces bornes à `manufacturing_year`
     * (« Année de fabrication » dans l'application), jamais à `model_year`.
     * Elles viennent des dates de production du catalogue constructeur (EPC
     * Toyota) pour le châssis et le moteur, et restent dans les bornes de
     * production de la génération. Le catalogue PDF Toyota, lui, parle en
     * années-modèle américaines : il fixe le MOTEUR, pas les bornes.
     *
     * Le châssis va dans `chassis_code` ; le marché, sans colonne dédiée, va
     * dans la note. Une ligne n'est posée que si l'EPC montre le filtre
     * (90915-20001 / 20003, remplacés par YZZD1) sur le châssis concerné :
     * jamais d'après le seul code moteur.
     *
     * @return list<array{model_slug: string, engine_code: string, year_from: int, year_to: int, chassis_codes: list<string>, market: string, notes: string}>
     */
    public static function applications(): array
    {
        $pdf = fn (string $ligne): string => ' Moteur : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, années-modèle US, '.$ligne.' → 90915-YZZD1).';
        $epc = fn (string $marche, string $catalogue, string $periode): string => ' Période EPC Toyota '.$marche.' (catalogue '.$catalogue.', '.self::SRC_EPC.'/vehicle/'.$marche.'/'.$catalogue.'/info) : '.$periode.'.';
        $schema = fn (string $preuve): string => ' Filtre au schéma 15-02 de l\'EPC : '.$preuve.'.';
        $remplace = ' 90915-20001 / 90915-20003 sont remplacés par 90915-YZZD1 — remplacement attesté par les catalogues de distribution Toyota ('.self::SRC_REMPLACEMENT.'), non par la fiche officielle Toyota.';

        return [
            ['model_slug' => 'camry-xv30', 'engine_code' => '1MZ-FE', 'year_from' => 2001, 'year_to' => 2006,
                'chassis_codes' => ['MCV30'], 'market' => 'USA',
                'notes' => 'Camry V6 3.0 l 1MZ-FE, châssis MCV30, marché USA.'.$pdf('Camry 1997-2006 V6 1MZFE / 3MZFE')
                    .$epc('US', '283470', 'MCV30L NAP 07/2001-02/2006 ; catalogue 284470 JPP 07/2001-01/2006')
                    .$schema('90915-20003 pour ACV30/MCV3# 07/2001-01/2006 ('.self::XREF_US_20003.')').$remplace
                    .' L\'EPC Général liste aussi MCV30 03/2002-10/2006 et MCV36 ('.self::XREF_GEN_20003.') : MCV36 non retenu, son schéma propre n\'a pas été relu.'
                    .' Les 4 cylindres 1AZ-FE / 2AZ-FE (ACV3#) prennent la YZZF1 : exclus.'.self::BORNES],
            ['model_slug' => 'camry-xv30', 'engine_code' => '3MZ-FE', 'year_from' => 2003, 'year_to' => 2006,
                'chassis_codes' => ['MCV31'], 'market' => 'USA',
                'notes' => 'Camry V6 3.3 l 3MZ-FE, châssis MCV31, marché USA.'.$pdf('Camry 1997-2006 V6 1MZFE / 3MZFE')
                    .$epc('US', '283470', 'MCV31L NAP 09/2003-02/2006 ; catalogue 284470 JPP 07/2003-01/2006')
                    .$schema('groupe « 1MZFE,3MZFE NAP » du MCV31L-CEASKA ('.self::SCHEMA_CAMRY_MCV31.') et 90915-20003 ('.self::XREF_US_20003.')').$remplace.self::BORNES],
            ['model_slug' => 'avalon-xx10', 'engine_code' => '1MZ-FE', 'year_from' => 1994, 'year_to' => 1999,
                'chassis_codes' => ['MCX10'], 'market' => 'USA',
                'notes' => 'Avalon V6 3.0 l 1MZ-FE, châssis MCX10, marché USA.'.$pdf('Avalon 1995-2004 V6 1MZFE')
                    .$epc('US', '361410', 'MCX10L 09/1994-08/1999')
                    .$schema('90915-20001 pour MCX10 09/1994-07/1999 ('.self::XREF_US_20001.')').$remplace
                    .' L\'Avalon australienne MCX10 (04/2000-05/2005, EPC Général) n\'est pas couverte par ces bornes.'.self::BORNES],
            ['model_slug' => 'avalon-xx20', 'engine_code' => '1MZ-FE', 'year_from' => 1999, 'year_to' => 2004,
                'chassis_codes' => ['MCX20'], 'market' => 'USA',
                'notes' => 'Avalon V6 3.0 l 1MZ-FE, châssis MCX20, marchés USA et Général.'.$pdf('Avalon 1995-2004 V6 1MZFE')
                    .$epc('US', '361420', 'MCX20L 08/1999-12/2004')
                    .$schema('90915-20003 pour MCX20 08/1999-12/2004 ('.self::XREF_US_20003.' ; '.self::XREF_GEN_20003.')').$remplace.self::BORNES],
            ['model_slug' => 'highlander-xu20', 'engine_code' => '1MZ-FE', 'year_from' => 2000, 'year_to' => 2003,
                'chassis_codes' => ['MCU20', 'MCU25'], 'market' => 'USA',
                'notes' => 'Highlander V6 3.0 l 1MZ-FE, châssis MCU20/MCU25, marché USA.'.$pdf('Highlander 2001-2003 V6 1MZFE')
                    .$epc('US', '522410', 'MCU20L/MCU25L 11/2000-08/2003')
                    .$schema('90915-20003 au groupe « (0011- ) 1MZFE, 3MZFE » ('.self::SCHEMA_HIGHLANDER.')').$remplace
                    .' Le 4 cylindres 2AZ-FE (ACU2#) prend la YZZF1 : exclu.'.self::BORNES],
            ['model_slug' => 'highlander-xu20', 'engine_code' => '3MZ-FE', 'year_from' => 2003, 'year_to' => 2007,
                'chassis_codes' => ['MCU23', 'MCU28', 'MHU23', 'MHU28'], 'market' => 'USA',
                'notes' => 'Highlander V6 3.3 l 3MZ-FE, châssis MCU23/MCU28 et Highlander Hybrid MHU23/MHU28, marché USA.'
                    .$pdf('Highlander 2004-2007 V6 3MZFE MCU2# et 2006-2007 V6 3MZFE MHU2# HV')
                    .$epc('US', '522410', 'MCU23L/MCU28L 08/2003-05/2007 ; catalogue 525410 MHU23L/MHU28L 03/2005-05/2007')
                    .$schema('90915-20003 au groupe « (0011- ) 1MZFE, 3MZFE » ('.self::SCHEMA_HIGHLANDER.') et pour MHU2# 03/2005-04/2007 ('.self::XREF_US_20003.')').$remplace
                    .' Le Kluger MCU28 (EPC Général 08/2003-04/2007) porte le même filtre ; il n\'a pas de modèle distinct au référentiel.'.self::BORNES],
            ['model_slug' => 'sienna-xl10', 'engine_code' => '1MZ-FE', 'year_from' => 1997, 'year_to' => 2002,
                'chassis_codes' => ['MCL10'], 'market' => 'USA',
                'notes' => 'Sienna V6 3.0 l 1MZ-FE, châssis MCL10 (seul moteur de la génération), marché USA.'.$pdf('Sienna 1998-2006 V6 1MZFE / 3MZFE')
                    .$epc('US', '622410', 'MCL10L 08/1997-12/2002')
                    .$schema('90915-20001 et 90915-20003 pour MCL10 08/1997-12/2002 ('.self::XREF_US_20001.' ; '.self::XREF_US_20003.')').$remplace
                    .' Le millésime 2003 a été fabriqué en 2002 : la borne est 2002, pas 2003.'.self::BORNES],
            ['model_slug' => '4runner-n180', 'engine_code' => '5VZ-FE', 'year_from' => 1995, 'year_to' => 2002,
                'chassis_codes' => ['VZN180', 'VZN185'], 'market' => 'USA',
                'notes' => '4Runner V6 3.4 l 5VZ-FE, châssis VZN180/VZN185, marchés USA et Général.'.$pdf('4Runner 1996-1997 et 1998-2002 V6 5VZFE')
                    .$epc('US', '671460', 'VZN180L/VZN185L 11/1995-08/2002')
                    .$schema('90915-20001 / 90915-20003 pour RZN18#/VZN18# 11/1995-07/2002 ('.self::XREF_US_20003.')').$remplace.self::BORNES],
            ['model_slug' => '4runner-n180', 'engine_code' => '3RZ-FE', 'year_from' => 1995, 'year_to' => 2000,
                'chassis_codes' => ['RZN180', 'RZN185'], 'market' => 'USA',
                'notes' => '4Runner 2.7 l 3RZ-FE, châssis RZN180/RZN185, marché USA.'.$pdf('4Runner L4 3RZFE 1996-1998, 1998 et 1999-2000')
                    .$epc('US', '671460', 'RZN180L/RZN185L 11/1995-08/2000')
                    .$schema('90915-20001 / 90915-20003 pour RZN18#/VZN18# ('.self::XREF_US_20003.')').$remplace
                    .' Le diesel 1KZ-TE (KZN185, EPC Général) n\'est pas au catalogue Toyota du filtre : exclu.'.self::BORNES],
            ['model_slug' => '4runner-n280', 'engine_code' => '2TR-FE', 'year_from' => 2009, 'year_to' => 2011,
                'chassis_codes' => ['TRN280', 'TRN285'], 'market' => 'USA',
                'notes' => '4Runner 2.7 l 2TR-FE, châssis TRN280 (propulsion) et TRN285 (4x4), marché USA uniquement.'.$pdf('4Runner 2010- L4 2TRFE')
                    .$epc('US', '671490', 'TRN280L 11/2009-08/2011, TRN285L 08/2010-08/2011 ; seuls GRN280/GRN285 (1GR-FE) apparaissent au-delà et sur le marché Général')
                    .$schema('90915-20003 au groupe « (0911- ) 2TRFE » du TRN280L-GKPGKA ('.self::SCHEMA_4RUNNER_TRN280.') et du TRN285L-GKPGKA ('.self::SCHEMA_4RUNNER_TRN285.')').$remplace
                    .' Le « 2010- » du catalogue PDF ne vaut pas pour toute la génération : Toyota a retiré ce moteur du 4Runner américain après le millésime 2010 (TRN285 jusqu\'en 2011).'
                    .' Le V6 1GR-FE (GRN28#) prend la cartouche 04152-YZZA5 : exclu.'.self::BORNES],
            ['model_slug' => 'tundra-i', 'engine_code' => '5VZ-FE', 'year_from' => 1999, 'year_to' => 2004,
                'chassis_codes' => ['VCK30', 'VCK40'], 'market' => 'USA',
                'notes' => 'Tundra V6 3.4 l 5VZ-FE, châssis VCK30/VCK40, marché USA.'.$pdf('Tundra 2000-2004 V6 5VZFE')
                    .$epc('US', '841420', 'VCK30L/VCK40L 02/1999-08/2004')
                    .$schema('90915-20001 / 90915-20003 pour GSK30/UCK3#/4#/VCK30/40 02/1999-09/2006 ('.self::XREF_US_20003.')').$remplace
                    .' Le V8 2UZ-FE (UCK) et le V6 1GR-FE (GSK30) prennent la 90915-YZZD3 selon le catalogue PDF : exclus.'.self::BORNES],
            ['model_slug' => 'solara-xv20', 'engine_code' => '1MZ-FE', 'year_from' => 1998, 'year_to' => 2002,
                'chassis_codes' => ['MCV20'], 'market' => 'USA',
                'notes' => 'Solara V6 3.0 l 1MZ-FE, châssis MCV20, marché USA.'.$pdf('Solara 1999-2008 V6 1MZFE / 3MZFE')
                    .$epc('US', '286410', 'MCV20L 06/1998-12/2002')
                    .$schema('90915-20001 / 90915-20003 pour ACV20/MCV20/SXV20 06/1998-11/2002 ('.self::XREF_US_20003.')').$remplace
                    .' Les 4 cylindres 5S-FE (SXV20, YZZN1) et 2AZ-FE (ACV20, YZZF1) sont exclus.'.self::BORNES],
            ['model_slug' => 'solara-xv30', 'engine_code' => '3MZ-FE', 'year_from' => 2003, 'year_to' => 2008,
                'chassis_codes' => ['MCV31'], 'market' => 'USA',
                'notes' => 'Solara V6 3.3 l 3MZ-FE, châssis MCV31, marché USA.'.$pdf('Solara 1999-2008 V6 1MZFE / 3MZFE')
                    .$epc('US', '286420', 'MCV31L 07/2003-12/2008')
                    .$schema('90915-20003 pour ACV30/MCV31 07/2003-11/2008 ('.self::XREF_US_20003.')').$remplace
                    .' Le 4 cylindres 2AZ-FE (ACV30, YZZF1) est exclu.'.self::BORNES],
            ['model_slug' => 'supra-a80', 'engine_code' => '2JZ-GE', 'year_from' => 1993, 'year_to' => 1998,
                'chassis_codes' => ['JZA80'], 'market' => 'USA',
                'notes' => 'Supra 3.0 l 2JZ-GE, châssis JZA80, marché USA.'.$pdf('Supra 1994-1998 L6 2JZGE')
                    .$epc('US', '291430', 'JZA80L 05/1993-08/1998')
                    .$schema('90915-20001 pour JZA80 05/1993-07/1998 ('.self::XREF_US_20001.')').$remplace
                    .' Les Supra japonaises (JZA80 jusqu\'en 08/2002, catalogue JP 291130) ne sont pas couvertes.'.self::BORNES],
            ['model_slug' => 'supra-a80', 'engine_code' => '2JZ-GTE', 'year_from' => 1993, 'year_to' => 1998,
                'chassis_codes' => ['JZA80'], 'market' => 'USA',
                'notes' => 'Supra 3.0 l biturbo 2JZ-GTE, châssis JZA80, marché USA.'.$pdf('Supra 1994-1996 et 1997-1998 L6 2JZGTE')
                    .$epc('US', '291430', 'JZA80L 05/1993-08/1998')
                    .$schema('90915-20001 pour JZA80 05/1993-07/1998 ('.self::XREF_US_20001.')').$remplace
                    .' Les Supra japonaises (JZA80 jusqu\'en 08/2002, catalogue JP 291130) ne sont pas couvertes.'.self::BORNES],
            ['model_slug' => 'es-xv20', 'engine_code' => '1MZ-FE', 'year_from' => 1996, 'year_to' => 2001,
                'chassis_codes' => ['MCV20'], 'market' => 'USA',
                'notes' => 'Lexus ES 300 V6 3.0 l 1MZ-FE, châssis MCV20, marchés USA et Général.'
                    .$epc('US', '284460', 'MCV20L 08/1996-07/2001')
                    .$schema('90915-20001 (08/1996-07/1999) puis 90915-20003 (08/1999-) sur le MCV20L-BTPGKA ('.self::SCHEMA_ES_MCV20.')').$remplace
                    .' Le catalogue PDF Toyota ne couvre pas Lexus. Le 2MZ-FE japonais (Windom) n\'est pas couvert : exclu.'.self::BORNES],
            ['model_slug' => 'es-xv30', 'engine_code' => '1MZ-FE', 'year_from' => 2001, 'year_to' => 2003,
                'chassis_codes' => ['MCV30'], 'market' => 'USA',
                'notes' => 'Lexus ES 300 V6 3.0 l 1MZ-FE, châssis MCV30, marchés USA et Général.'
                    .$epc('US', '282470', 'MCV30L 07/2001-07/2003')
                    .$schema('90915-20003 sur le MCV30L-BEAGKA ('.self::SCHEMA_ES_MCV30.')').$remplace
                    .' Le catalogue PDF Toyota ne couvre pas Lexus.'.self::BORNES],
            ['model_slug' => 'es-xv30', 'engine_code' => '3MZ-FE', 'year_from' => 2003, 'year_to' => 2006,
                'chassis_codes' => ['MCV31'], 'market' => 'USA',
                'notes' => 'Lexus ES 330 V6 3.3 l 3MZ-FE, châssis MCV31, marché USA.'
                    .$epc('US', '282470', 'MCV31L 07/2003-03/2006')
                    .$schema('90915-20003 pour ES300/330 MCV3# 07/2001-02/2006 ('.self::XREF_US_20003.') ; MCV31L-BEAGKA, '.self::LEXUS_ES330)
                    .' Le catalogue PDF Toyota ne couvre pas Lexus.'.self::BORNES],
            ['model_slug' => 'gs-s160', 'engine_code' => '2JZ-GE', 'year_from' => 1997, 'year_to' => 2004,
                'chassis_codes' => ['JZS160'], 'market' => 'USA',
                'notes' => 'Lexus GS 300 3.0 l 2JZ-GE, châssis JZS160, marchés USA et Général.'
                    .$epc('US', '332490', 'JZS160L 08/1997-12/2004')
                    .$schema('90915-20001 au groupe « (9708- ) 2JZGE » du JZS160L-BEAQFA ('.self::SCHEMA_GS_JZS160.') ; 90915-20003 pour JZS160 au marché Général ('.self::XREF_GEN_20003.')').$remplace
                    .' Le millésime 2005 a été fabriqué jusqu\'en 12/2004. Les V8 GS 400 / 430 (UZS16#) et le 2JZ-GTE de l\'Aristo japonaise sont exclus.'
                    .' Le catalogue PDF Toyota ne couvre pas Lexus.'.self::BORNES],
            ['model_slug' => 'is-xe10', 'engine_code' => '2JZ-GE', 'year_from' => 2000, 'year_to' => 2005,
                'chassis_codes' => ['JCE10'], 'market' => 'USA',
                'notes' => 'Lexus IS 300 3.0 l 2JZ-GE, châssis JCE10, marché USA.'
                    .$epc('US', '242410', 'JCE10L 05/2000-07/2005')
                    .$schema('90915-20003 sur le JCE10L-AEAVFA ('.self::SCHEMA_IS_JCE10.') et pour IS300 JCE10 05/2000-06/2005 ('.self::XREF_US_20003.')').$remplace
                    .' L\'IS 200 (GXE10, 1G-FE) est exclu. Le catalogue PDF Toyota ne couvre pas Lexus.'.self::BORNES],
            ['model_slug' => 'rx-xu10', 'engine_code' => '1MZ-FE', 'year_from' => 1998, 'year_to' => 2003,
                'chassis_codes' => ['MCU10', 'MCU15'], 'market' => 'USA',
                'notes' => 'Lexus RX 300 V6 3.0 l 1MZ-FE, châssis MCU10 (traction) / MCU15 (intégrale), marché USA.'
                    .$epc('US', '521410', 'MCU10L/MCU15L 01/1998-02/2003')
                    .$schema('90915-20001 (01/1998-07/1999) puis 90915-20003 (08/1999-) sur le MCU10L-AWPGKA ('.self::SCHEMA_RX_MCU10.')').$remplace
                    .' Les 4 cylindres du Harrier japonais ne sont pas couverts. Le catalogue PDF Toyota ne couvre pas Lexus.'.self::BORNES],
        ];
    }
}
