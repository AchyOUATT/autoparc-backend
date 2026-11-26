<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Restructure le référentiel Toyota Sienna (décision du propriétaire,
 * 26/11/2026) : pose les générations XL20, XL30 et XL40, leur donne leurs
 * compatibilités de filtre à huile réévaluées une à une, puis rend la ligne
 * nue `sienna` inactive.
 *
 * Règles suivies :
 *  - additif et idempotent : une génération est créée seulement si son slug
 *    manque, une compatibilité seulement si la même ligne (article, modèle,
 *    moteur, bornes) n'existe pas ; aucune ligne existante n'est modifiée
 *    ni supprimée, et les identifiants sont retrouvés par slug et par SKU ;
 *  - les 4 compatibilités de la ligne nue NE SONT PAS dupliquées : chacune
 *    a été réévaluée par génération, châssis et période de fabrication EPC
 *    (voir `applications()`), et la ligne nue garde les siennes, intactes ;
 *  - la ligne nue n'est rendue inactive (et ses bornes vidées, comme le
 *    Tacoma et le Venza) que si aucun véhicule ni véhicule de garage ne la
 *    référence ; sinon elle reste active et le rapport le dit.
 */
final class ToyotaSiennaReferentielImport
{
    public const SLUG_NU = 'sienna';

    private const SRC_PDF = 'https://www.toyotapartsandservice.com/wp-content/uploads/2024/10/06_OilFilters2025_hi.pdf';

    private const SRC_EPC = 'https://toyota-epc.aftermarketcatalog.com/vehicle/US';

    /** Remplacement 04152-31090 → 04152-YZZA1 publié par Toyota (« Previous Version(s) »). */
    private const SRC_YZZA1_OFFICIEL = 'https://autoparts.toyota.com/products/product/replaceable-element-oil-filter-04152yzza1';

    private const BORNES = ' Bornes annuelles : contrôler le VIN, le code moteur et la date de fabrication aux bornes.';

    /** @return array<string, mixed> */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'models_created' => [],
                'fitments_inserted' => 0,
                'fitments_already_present' => 0,
                'fitments_skipped_missing_model' => [],
                'fitments_skipped_missing_part' => [],
                'bare_model' => 'absent',
            ];

            $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');
            if ($toyota === null) {
                return $rapport;
            }

            self::poserLesGenerations((int) $toyota, $rapport);
            self::poserLesCompatibilites($rapport);
            self::desactiverLaLigneNue((int) $toyota, $rapport);

            return $rapport;
        });
    }

    /**
     * Repose les seules compatibilités Sienna, sans toucher aux modèles.
     *
     * Appelée par `FiltresHuileBoutiqueSeeder` après sa réécriture : ce
     * seeder fait `fitments()->delete()` sur la YZZA1 et la YZZN1, et
     * effacerait sinon ces lignes à chaque exécution. La YZZD1, marquée
     * `compatibilites_a_verifier` dans ce seeder, n'est pas effacée par lui.
     *
     * @param  list<string>|null  $skus  limite aux références données
     * @return array<string, mixed>
     */
    public static function reposerLesCompatibilites(?array $skus = null): array
    {
        return DB::transaction(function () use ($skus): array {
            $rapport = [
                'fitments_inserted' => 0,
                'fitments_already_present' => 0,
                'fitments_skipped_missing_model' => [],
                'fitments_skipped_missing_part' => [],
            ];
            self::poserLesCompatibilites($rapport, $skus);

            return $rapport;
        });
    }

    /** @param  array<string, mixed>  $rapport */
    private static function poserLesGenerations(int $toyota, array &$rapport): void
    {
        foreach (VehicleModelsSeeder::GENERATIONS_SIENNA as [$nom, $gen, $carrosserie, $segment, $de, $a]) {
            $slug = Str::slug($nom.'-'.$gen);
            if (DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('vehicle_models')->insert([
                'brand_id' => $toyota,
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
     * @param  array<string, mixed>  $rapport
     * @param  list<string>|null  $skus
     */
    private static function poserLesCompatibilites(array &$rapport, ?array $skus = null): void
    {
        foreach (self::applications() as $application) {
            if ($skus !== null && ! in_array($application['sku'], $skus, true)) {
                continue;
            }
            $parts = DB::table('parts')->where('sku', $application['sku'])
                ->whereNull('owner_partner_account_id')
                ->whereNull('deleted_at')
                ->limit(2)
                ->get();
            if ($parts->count() !== 1) {
                $rapport['fitments_skipped_missing_part'][] = $application['sku'];

                continue;
            }
            $part = $parts->first();

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
            if (DB::table('part_fitments')->where($identite)->exists()) {
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
            DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
            $rapport['fitments_inserted']++;
        }

        $rapport['fitments_skipped_missing_model'] = array_values(array_unique($rapport['fitments_skipped_missing_model']));
        $rapport['fitments_skipped_missing_part'] = array_values(array_unique($rapport['fitments_skipped_missing_part']));
    }

    /**
     * Rend la ligne nue inactive et sans bornes, sans la supprimer ni la
     * renommer. Ses compatibilités, ses motorisations nrcan et ses autres
     * liens restent rattachés à elle.
     *
     * @param  array<string, mixed>  $rapport
     */
    private static function desactiverLaLigneNue(int $toyota, array &$rapport): void
    {
        $id = DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', self::SLUG_NU)->value('id');
        if ($id === null) {
            return;
        }

        $attaches = DB::table('vehicles')->where('vehicle_model_id', $id)->count()
            + DB::table('owned_vehicles')->where('vehicle_model_id', $id)->count();
        if ($attaches > 0) {
            $rapport['bare_model'] = 'kept_active_vehicles_attached';

            return;
        }

        DB::table('vehicle_models')->where('id', $id)->update([
            'is_active' => false,
            'production_start' => null,
            'production_end' => null,
            'updated_at' => now(),
        ]);
        $rapport['bare_model'] = 'deactivated';
    }

    /**
     * Compatibilités réévaluées, une par génération, moteur et châssis.
     *
     * Années = années civiles de FABRICATION (l'API les compare à
     * `manufacturing_year`), lues dans l'EPC Toyota, marché USA, et
     * contenues dans les bornes de la génération. Le catalogue PDF Toyota
     * des filtres 2025-2026 (années-modèle US) confirme la référence pour
     * chaque moteur.
     *
     * Réévaluation des 4 lignes de la ligne nue (qui restent en place) :
     *  - 04152-YZZA1 2GR-FE 2007-2016 : à cheval sur XL20 et XL30 ; l'EPC
     *    sépare GSL2# (12/2006-01/2010) et GSL3# (01/2010-08/2016) : deux
     *    lignes, chacune sourcée par son catalogue ;
     *  - 04152-YZZA1 1AR-FE 2011-2013 : XL30, ASL30 05/2010-08/2013 → 2010-2013 ;
     *  - 04152-YZZA1 2GR-FKS 2017-2020 : XL30, GSL3# 08/2016-09/2020 → 2016-2020 ;
     *  - 90915-YZZN1 A25A-FXS 2021- : XL40, AXLH4# dès 11/2020 → 2020-ouvert.
     * Nouvelle : 90915-YZZD1 3MZ-FE sur la XL20 (MCL2#), confirmée par l'EPC.
     *
     * @return list<array{sku: string, model_slug: string, engine_code: string, year_from: int, year_to: ?int, chassis_codes: list<string>, notes: string}>
     */
    public static function applications(): array
    {
        $epc = fn (string $catalogue, string $groupe, string $periode): string => ' EPC Toyota, marché USA (catalogue '.$catalogue.', '.self::SRC_EPC.'/'.$catalogue.'/info ; groupe '.$groupe.') : '.$periode.'.';
        $pdf = fn (string $ligne): string => ' Source constructeur : '.self::SRC_PDF.' (catalogue Toyota des filtres 2025-2026, années-modèle US, « SIENNA/HV '.$ligne.' »).';
        $yzza1 = ' L\'EPC porte 04152-31090, remplacé par 04152-YZZA1 selon la fiche officielle Toyota ('.self::SRC_YZZA1_OFFICIEL.').';

        return [
            [
                'sku' => '90915-YZZD1', 'model_slug' => 'sienna-xl20', 'engine_code' => '3MZ-FE',
                'year_from' => 2003, 'year_to' => 2006, 'chassis_codes' => ['MCL20', 'MCL23', 'MCL25'],
                'notes' => 'Sienna XL20 3.3 V6 3MZ-FE, châssis MCL20/MCL23/MCL25, marché USA.'
                    .$pdf('1998-2006 V6 (1MZFE, 3MZFE) → 90915-YZZD1')
                    .$epc('622420', '15601, '.self::SRC_EPC.'/622420/g/15601', '90915-20003 sur MCL2# dès 01/2003 ; production MCL2# 01/2003-12/2006')
                    .' Schéma 15-02 du MCL20L-PRAEKK, groupe « (0301- ) 3MZFE » : 90915-20003 (https://www.toyodiy.com/parts/p_U_200301_TOYOTA_SIENNA_MCL20L-PRAEKK_1502.html).'
                    .' 90915-20003 est remplacé par 90915-YZZD1 — remplacement attesté par les catalogues de distribution Toyota, et la référence YZZD1 est donnée directement par le PDF constructeur.'
                    .self::BORNES,
            ],
            [
                'sku' => '04152-YZZA1', 'model_slug' => 'sienna-xl20', 'engine_code' => '2GR-FE',
                'year_from' => 2006, 'year_to' => 2010, 'chassis_codes' => ['GSL20', 'GSL23', 'GSL25'],
                'notes' => 'Sienna XL20 3.5 V6 2GR-FE, châssis GSL20/GSL23/GSL25, marché USA.'
                    .$pdf('2007-2010 V6 (2GRFE) → 04152-YZZA1')
                    .$epc('622420', '04152, '.self::SRC_EPC.'/622420/g/04152', 'GSL2# de 12/2006 à 01/2010 (variantes EPC ; le bandeau du catalogue 622420 indique 12/2009)')
                    .' Schéma 15-02 du GSL20L-PRADKK (https://www.toyodiy.com/parts/p_U_200612_TOYOTA_SIENNA_GSL20L-PRADKK_1502.html).'
                    .$yzza1.' Première partie de la ligne « Sienna 2GR-FE 2007-2016 » de la ligne nue, à cheval sur XL20 et XL30.'
                    .self::BORNES,
            ],
            [
                'sku' => '04152-YZZA1', 'model_slug' => 'sienna-xl30', 'engine_code' => '2GR-FE',
                'year_from' => 2010, 'year_to' => 2016, 'chassis_codes' => ['GSL30', 'GSL33', 'GSL35'],
                'notes' => 'Sienna XL30 3.5 V6 2GR-FE, châssis GSL30/GSL33/GSL35, marché USA.'
                    .$pdf('2011-2016 V6 (2GRFE) → 04152-YZZA1')
                    .$epc('622430', '04152, '.self::SRC_EPC.'/622430/f/151714B?pnc=04152', 'GSL30/33/35 2GR-FE 01/2010-08/2016')
                    .$yzza1.' Seconde partie de la ligne « Sienna 2GR-FE 2007-2016 » de la ligne nue.'
                    .self::BORNES,
            ],
            [
                'sku' => '04152-YZZA1', 'model_slug' => 'sienna-xl30', 'engine_code' => '1AR-FE',
                'year_from' => 2010, 'year_to' => 2013, 'chassis_codes' => ['ASL30'],
                'notes' => 'Sienna XL30 2.7 L4 1AR-FE, châssis ASL30, marché USA.'
                    .$pdf('2011-2013 L4 (1ARFE) → 04152-YZZA1')
                    .$epc('622430', '04152, '.self::SRC_EPC.'/622430/f/151714B?pnc=04152', 'ASL30 1AR-FE 05/2010-08/2013')
                    .$yzza1.' La ligne nue disait 2011-2013 (années-modèle) ; la fabrication commence en 05/2010.'
                    .self::BORNES,
            ],
            [
                'sku' => '04152-YZZA1', 'model_slug' => 'sienna-xl30', 'engine_code' => '2GR-FKS',
                'year_from' => 2016, 'year_to' => 2020, 'chassis_codes' => ['GSL30', 'GSL33', 'GSL35'],
                'notes' => 'Sienna XL30 3.5 V6 2GR-FKS, châssis GSL30/GSL33/GSL35, marché USA.'
                    .$pdf('2017-2020 V6 (2GRFKS) → 04152-YZZA1')
                    .$epc('622430', '04152, '.self::SRC_EPC.'/622430/f/151714B?pnc=04152', 'GSL3# 2GR-FKS 08/2016-09/2020')
                    .' Schéma 15-02 du GSL30L-PRZEHA (https://www.toyodiy.com/parts/p_U_201608_TOYOTA_SIENNA_GSL30L-PRZEHA_1502.html).'
                    .$yzza1.' La ligne nue disait 2017-2020 (années-modèle) ; la fabrication commence en 08/2016.'
                    .self::BORNES,
            ],
            [
                'sku' => '90915-YZZN1', 'model_slug' => 'sienna-xl40', 'engine_code' => 'A25A-FXS',
                'year_from' => 2020, 'year_to' => null, 'chassis_codes' => ['AXLH40', 'AXLH45'],
                'notes' => 'Sienna XL40 2.5 L4 hybride A25A-FXS, châssis AXLH40/AXLH45, marché USA.'
                    .$pdf('2021- L4 (A25AFXS) → 90915-YZZN1')
                    .$epc('622440', '15601, '.self::SRC_EPC.'/622440/g/15601', '90915-10009 sur AXLH4# dès 11/2020')
                    .' L\'EPC porte 90915-10009 ; la référence 90915-YZZN1 est donnée directement par le PDF constructeur. La ligne nue disait 2021- (année-modèle) ; la fabrication commence en 11/2020.'
                    .self::BORNES,
            ],
        ];
    }
}
