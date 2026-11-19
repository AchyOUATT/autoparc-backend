<?php

namespace App\Support;

use App\Models\OemNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Priorités 1 à 3 de l'audit filtres à huile, reprises après contre-expertise :
 * une ligne n'est déplacée que si HKS, CUSCO ou Boshoku documente exactement
 * le modèle, le moteur, la période (et le châssis, quand la source en donne un).
 * Les analogies de moteur sont refusées. Les bornes d'années suivent la source ;
 * les mois, absents du schéma, sont conservés dans les notes.
 *
 * 1. Retirer la Corolla E140 1ZZ-FE du YZZN1 (sans la placer sur le 10003).
 *    Déplacer vers le 10003 seulement les lignes sourcées : Yaris XP130 1NZ-FE,
 *    Prius XW50 2ZR-FXE, Corolla E180 2ZR-FAE, Corolla E210 2ZR-FAE et 2ZR-FXE.
 * 2. Déplacer vers le YZZN2 seulement les lignes sourcées (Camry A25A-FXS,
 *    RAV4 A25A-FXS, Corolla E210 M20A-FKS, Lexus ES A25A-FXS, NX, RX, UX).
 *    Rattacher le 90915-10009 au YZZN2. Les lignes sans preuve exacte restent
 *    sur le YZZN1, marquées « à vérifier ».
 * 3. Retirer du YZZD4 le Prado J120 5L-E et le Land Cruiser J200 1GR-FE.
 *
 * Ciblage par référence pièce + marque + modèle + génération + moteur,
 * jamais par identifiant numérique. Idempotent. Ne touche ni aux prix,
 * ni au stock, ni aux photos, ni aux autres filtres.
 */
final class CorrigerLesFiltresYzznEtYzzd4
{
    public const HKS_URL = 'https://www.hks-power.co.jp/en/product_search/product/935/media/print';

    public const BOSHOKU_URL = 'https://www.tb-filter.toyota-boshoku.com/en/wp-content/uploads/2023/12/231227-TBAS-lineup-table_OIL.pdf';

    private const MARQUEUR = '[audit filtres 2026-11-19]';

    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'removed' => 0,
                'moved' => 0,
                'merged' => 0,
                'flagged' => 0,
                'kept_conflict' => 0,
                'already_done' => 0,
                'skipped_missing_part' => [],
                'skipped_missing_model' => [],
                'oem_10009_updated' => false,
                'untouched_other_filters' => true,
            ];

            $empreinteAvant = self::empreinteHorsPerimetre();

            foreach (self::suppressions() as $operation) {
                self::retirer($operation, $rapport);
            }

            foreach (self::deplacements() as $operation) {
                self::deplacer($operation, $rapport);
            }

            foreach (self::aVerifier() as $operation) {
                self::marquerAVerifier($operation, $rapport);
            }

            $rapport['oem_10009_updated'] = self::rattacherLe10009AuYzzN2();

            $empreinteApres = self::empreinteHorsPerimetre();
            if ($empreinteAvant !== $empreinteApres) {
                throw new RuntimeException(
                    'Correction refusée : des lignes hors périmètre ont été modifiées.'
                );
            }

            return $rapport;
        });
    }

    /**
     * @return list<array{from_sku: string, brand: string, model: string, generation: ?string, engine_code: string}>
     */
    public static function suppressions(): array
    {
        return [
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E140',
                'engine_code' => '1ZZ-FE',
            ],
            [
                'from_sku' => '90915-YZZD4',
                'brand' => 'Toyota',
                'model' => 'Land Cruiser Prado',
                'generation' => 'J120',
                'engine_code' => '5L-E',
            ],
            [
                'from_sku' => '90915-YZZD4',
                'brand' => 'Toyota',
                'model' => 'Land Cruiser',
                'generation' => 'J200',
                'engine_code' => '1GR-FE',
            ],
        ];
    }

    /**
     * Déplacements justifiés par une source exacte (modèle + moteur + période).
     *
     * @return list<array<string, mixed>>
     */
    public static function deplacements(): array
    {
        return [
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Yaris',
                'generation' => 'XP130',
                'engine_code' => '1NZ-FE',
                'chassis_codes' => ['NCP131'],
                'year_from' => 2010,
                'year_to' => 2020,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source : HKS, Vitz NCP131, 1NZ-FE, 12/2010–01/2020, 90915-10003. Le schéma ne porte que les années : restriction de mois conservée ici. Le catalogue Boshoku Thaïlande liste un Yaris 2006–2013 1NZ-FE au 90915-YZZN2, sans code châssis (marché ASEAN). '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Prius',
                'generation' => 'XW50',
                'engine_code' => '2ZR-FXE',
                'chassis_codes' => ['ZVW50', 'ZVW51', 'ZVW55'],
                'year_from' => 2017,
                'year_to' => 2022,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source : HKS, Prius ZVW50/ZVW51/ZVW55, 2ZR-FXE, 09/2017–12/2022, 90915-10003. La forme peut différer selon la date de production entre 09/2017 et 12/2017. Le châssis ZVW52 (Prius PHV) n\'est pas ajouté. Restriction de mois conservée ici. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E180',
                'engine_code' => '2ZR-FAE',
                'chassis_codes' => ['ZRE186H'],
                'year_from' => 2016,
                'year_to' => 2018,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source : HKS, Auris ZRE186H (plateforme E180), 2ZR-FAE, 09/2016–05/2018, 90915-10003. Restriction de mois conservée ici. La ligne 2ZR-FE de la même génération n\'est pas déplacée : HKS ne la documente pas. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => '2ZR-FAE',
                'chassis_codes' => ['ZRE212', 'ZRE212W'],
                'year_from' => 2019,
                'year_to' => 2022,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source : HKS, Corolla ZRE212 (10/2019–09/2022) et Corolla Touring ZRE212W (09/2019–09/2022), 2ZR-FAE, 90915-10003. Restriction de mois conservée ici. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => '2ZR-FXE',
                'chassis_codes' => ['ZWE211', 'ZWE214', 'ZWE215', 'ZWE219'],
                'year_from' => 2019,
                'year_to' => null,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source : HKS, Corolla ZWE211/ZWE214 (09/2019–09/2022) et ZWE215/ZWE219 (10/2022–), 2ZR-FXE, 90915-10003. Restriction de mois conservée ici. Attention : le catalogue Boshoku Thaïlande donne la Corolla Altis 2019~ 2ZR-FXE à la cartouche 04152-YZZA7 (marché ASEAN). '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Camry',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AXVH70'],
                'year_from' => 2017,
                'year_to' => 2023,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, Camry AXVH70, A25A-FXS, 01/2017–12/2023, 90915-10009. Boshoku : Camry 2018~, A25A-FXS, 90915-10009 / 90915-YZZN2. Restriction de mois conservée ici. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'RAV4',
                'generation' => 'XA50',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AXAH52', 'AXAH54', 'AXAP54'],
                'year_from' => 2019,
                'year_to' => 2025,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, RAV4 AXAH52/AXAH54 (04/2019–11/2025) et AXAP54 PHEV (06/2020–11/2025), A25A-FXS, 90915-10009. Les châssis 2025+ AXAN64/AXAP64 ne sont pas ajoutés. Restriction de mois conservée ici. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => 'M20A-FKS',
                'chassis_codes' => ['MZEA12H', 'MZEA12W'],
                'year_from' => 2020,
                'year_to' => 2025,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, Corolla Touring MZEA12W (06/2020–04/2025) et Corolla Sport MZEA12H (10/2022–04/2025), M20A-FKS, 90915-10009. Restriction de mois conservée ici. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'ES',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AXZH10'],
                'year_from' => 2019,
                'year_to' => 2026,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Boshoku : ES 2019~, A25A-FXS, 90915-10009 / 90915-YZZN2. Source châssis : HKS, ES300h AXZH10, A25A-FXS, 01/2022–05/2026, 90915-10009. Restriction de mois conservée ici. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'NX',
                'generation' => 'AZ20',
                'engine_code' => 'A25A-FKS',
                'chassis_codes' => ['AAZA20', 'AAZA25'],
                'year_from' => 2021,
                'year_to' => null,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, NX250 AAZA20/AAZA25, A25A-FKS, 10/2021–, 90915-10009. Restriction de mois conservée ici. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'NX',
                'generation' => 'AZ20',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AAZH20', 'AAZH25', 'AAZH26'],
                'year_from' => 2021,
                'year_to' => null,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, NX350h AAZH20/AAZH25 et NX450h+ AAZH26, A25A-FXS, 10/2021–, 90915-10009. Boshoku : NX 2022~, A25A-FXS, 90915-10009 / 90915-YZZN2. Restriction de mois conservée ici. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'RX',
                'generation' => 'AL30',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AALH10', 'AALH15', 'AALH16'],
                'year_from' => 2022,
                'year_to' => null,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, RX450h+ AALH16 (11/2022–) et RX350h AALH10/AALH15 (07/2023–), A25A-FXS, 90915-10009. Boshoku : RX 2023~, A25A-FXS, 90915-10009 / 90915-YZZN2. Restriction de mois conservée ici. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'UX',
                'generation' => 'ZA10',
                'engine_code' => 'M20A-FKS',
                'chassis_codes' => ['MZAA10'],
                'year_from' => 2018,
                'year_to' => 2023,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, UX200 MZAA10, M20A-FKS, 11/2018–12/2023, 90915-10009. Restriction de mois conservée ici. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'UX',
                'generation' => 'ZA10',
                'engine_code' => 'M20A-FXS',
                'chassis_codes' => ['MZAH10', 'MZAH15', 'MZAH11', 'MZAH16'],
                'year_from' => 2018,
                'year_to' => null,
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source : HKS, UX250h MZAH10/MZAH15 (11/2018–12/2023) et UX300h MZAH11/MZAH16 (01/2024–), M20A-FXS, 90915-10009. Boshoku : UX 2018~, M20A-FXS, 90915-10009 / 90915-YZZN2. Restriction de mois conservée ici. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
        ];
    }

    /**
     * Lignes laissées sur le YZZN1, faute de source exacte pour ce modèle,
     * ce moteur et cette période.
     *
     * @return list<array<string, mixed>>
     */
    public static function aVerifier(): array
    {
        return [
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E180',
                'engine_code' => '2ZR-FE',
                'source_note' => 'À vérifier : aucune source HKS, CUSCO ou Boshoku pour la Corolla E180 2ZR-FE au 90915-10003. HKS documente l\'Auris ZRE186H en 2ZR-FAE seulement ; ce n\'est pas une preuve pour le 2ZR-FE. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => '2ZR-FE',
                'source_note' => 'À vérifier : aucune source HKS, CUSCO ou Boshoku pour la Corolla E210 2ZR-FE au 90915-10003. HKS documente le 2ZR-FAE (ZRE212) et le 2ZR-FXE (ZWE211 et suivants), pas le 2ZR-FE. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Camry',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FKS',
                'source_note' => 'À vérifier : HKS ne documente pas de Camry A25A-FKS au 90915-10009. Boshoku liste un Camry 2018~ en A25A-FKB, pas en A25A-FKS ; les deux codes ne sont pas assimilés. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'RAV4',
                'generation' => 'XA50',
                'engine_code' => 'A25A-FKS',
                'source_note' => 'À vérifier : HKS documente le RAV4 XA50 M20A-FKS (MXAA52/MXAA54) et A25A-FXS (AXAH52/AXAH54) au 90915-10009, pas l\'A25A-FKS. Le châssis MXAA52 n\'est pas recopié sur ce moteur. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Avalon',
                'generation' => null,
                'engine_code' => 'A25A-FXS',
                'source_note' => 'À vérifier : ni HKS, ni CUSCO, ni Boshoku ne documentent un Avalon A25A-FXS au 90915-10009 / 90915-YZZN2. Les applications Camry ou ES de la même famille de moteurs ne valent pas pour ce modèle. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Highlander',
                'generation' => null,
                'engine_code' => 'A25A-FXS',
                'source_note' => 'À vérifier : la seule ligne HKS Highlander A25A-FXS est le châssis AXUH78R à partir de 04/2026, incompatible avec la période 2020+ déjà enregistrée. Les applications Harrier ou Camry ne sont pas transposées. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Highlander',
                'generation' => null,
                'engine_code' => 'T24A-FTS',
                'source_note' => 'À vérifier : HKS documente le T24A-FTS au 90915-10009 sur d\'autres modèles (Vellfire, Crown, NX, RX), pas sur le Highlander. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Sienna',
                'generation' => null,
                'engine_code' => 'A25A-FXS',
                'source_note' => 'À vérifier : ni HKS, ni CUSCO, ni Boshoku ne documentent un Sienna A25A-FXS au 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Toyota',
                'model' => 'Venza',
                'generation' => 'XU80',
                'engine_code' => 'A25A-FXS',
                'source_note' => 'À vérifier : ni HKS, ni CUSCO, ni Boshoku ne documentent un Venza XU80 A25A-FXS au 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'brand' => 'Lexus',
                'model' => 'ES',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FKS',
                'source_note' => 'À vérifier : HKS et Boshoku documentent l\'ES A25A-FXS au 90915-10009 / 90915-YZZN2, pas l\'A25A-FKS. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
        ];
    }

    /**
     * @param  array{from_sku: string, brand: string, model: string, generation: ?string, engine_code: string}  $operation
     * @param  array<string, mixed>  $rapport
     */
    private static function retirer(array $operation, array &$rapport): void
    {
        $piece = self::piecePlateforme($operation['from_sku']);
        if ($piece === null) {
            $rapport['already_done']++;

            return;
        }

        $modele = self::trouverModele($operation['brand'], $operation['model'], $operation['generation']);
        if ($modele === null) {
            $rapport['already_done']++;

            return;
        }

        $lignes = self::lignes($piece->id, $modele->id, $operation['engine_code']);
        if ($lignes->isEmpty()) {
            $rapport['already_done']++;

            return;
        }

        DB::table('part_fitments')->whereIn('id', $lignes->pluck('id'))->delete();
        self::toucherLaPiece((int) $piece->id);
        $rapport['removed'] += $lignes->count();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $rapport
     */
    private static function deplacer(array $operation, array &$rapport): void
    {
        $source = self::piecePlateforme($operation['from_sku']);
        $cible = self::piecePlateforme($operation['to_sku']);
        if ($cible === null) {
            $rapport['skipped_missing_part'][] = $operation['to_sku'];

            return;
        }

        $modele = self::trouverModele($operation['brand'], $operation['model'], $operation['generation']);
        if ($modele === null) {
            $rapport['skipped_missing_model'][] = $operation['brand'].' '.$operation['model'].' '.($operation['generation'] ?? '(sans génération)');

            return;
        }

        $chassis = self::chassis($operation['chassis_codes'] ?? []);
        $noteAjout = (string) $operation['source_note'];

        $lignesSource = $source === null
            ? collect()
            : self::lignes($source->id, $modele->id, $operation['engine_code']);
        $lignesCible = self::lignes($cible->id, $modele->id, $operation['engine_code']);

        if ($lignesSource->isEmpty() && $lignesCible->isNotEmpty()) {
            foreach ($lignesCible as $ligne) {
                self::completerLigneDeplacee($ligne, $chassis, $noteAjout, $operation);
            }
            $rapport['already_done']++;

            return;
        }

        if ($lignesSource->isEmpty()) {
            $rapport['already_done']++;

            return;
        }

        if ($lignesCible->isNotEmpty()) {
            self::fusionnerOuConserver($lignesSource, $lignesCible, $source, $cible, $chassis, $noteAjout, $operation, $rapport);

            return;
        }

        foreach ($lignesSource as $ligne) {
            $maj = [
                'part_id' => $cible->id,
                'notes' => self::ajouterNote($ligne->notes, $noteAjout),
                'updated_at' => now(),
            ];
            if ($chassis !== null && trim((string) ($ligne->chassis_code ?? '')) === '') {
                $maj['chassis_code'] = $chassis;
            }
            if (array_key_exists('year_from', $operation)) {
                $maj['year_from'] = $operation['year_from'];
            }
            if (array_key_exists('year_to', $operation)) {
                $maj['year_to'] = $operation['year_to'];
            }
            DB::table('part_fitments')->where('id', $ligne->id)->update($maj);
        }

        if ($source !== null) {
            self::toucherLaPiece((int) $source->id);
        }
        self::toucherLaPiece((int) $cible->id);
        $rapport['moved'] += $lignesSource->count();
    }

    /**
     * Si la pièce cible a déjà une ligne, on fusionne notes, châssis et périodes
     * seulement quand ils sont compatibles. Sinon on garde les deux et on marque
     * la ligne source « à vérifier ».
     *
     * @param  \Illuminate\Support\Collection<int, object>  $lignesSource
     * @param  \Illuminate\Support\Collection<int, object>  $lignesCible
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $rapport
     */
    private static function fusionnerOuConserver($lignesSource, $lignesCible, ?object $source, object $cible, ?string $chassis, string $noteAjout, array $operation, array &$rapport): void
    {
        $idsASupprimer = [];

        foreach ($lignesSource as $ligneSource) {
            $compatibles = $lignesCible->filter(function ($ligneCible) use ($ligneSource, $chassis, $operation): bool {
                $chassisCible = $ligneCible->chassis_code ?? null;
                $chassisSource = $chassis ?? ($ligneSource->chassis_code ?? null);

                return self::chassisCompatibles($chassisCible, $chassisSource)
                    && self::periodesCompatibles($ligneCible, $operation, $ligneSource);
            });

            if ($compatibles->count() !== 1) {
                self::appliquerNote($ligneSource, $noteAjout);
                $rapport['kept_conflict']++;

                continue;
            }

            $ligneCible = $compatibles->first();
            $maj = [
                'notes' => self::fusionnerNotes($ligneCible->notes, $ligneSource->notes, $noteAjout),
                'updated_at' => now(),
            ];
            $chassisFusionne = self::chassisAFusionner(
                $ligneCible->chassis_code ?? null,
                $ligneSource->chassis_code ?? null,
                $chassis
            );
            if ($chassisFusionne !== null && (string) ($ligneCible->chassis_code ?? '') !== $chassisFusionne) {
                $maj['chassis_code'] = $chassisFusionne;
            }
            if (array_key_exists('year_from', $operation) && self::anneeDifferente($ligneCible->year_from, $operation['year_from'])) {
                $maj['year_from'] = $operation['year_from'];
            }
            if (array_key_exists('year_to', $operation) && self::anneeDifferente($ligneCible->year_to, $operation['year_to'])) {
                $maj['year_to'] = $operation['year_to'];
            }
            DB::table('part_fitments')->where('id', $ligneCible->id)->update($maj);
            $idsASupprimer[] = $ligneSource->id;
            $rapport['merged']++;
            $rapport['moved']++;
        }

        if ($idsASupprimer !== []) {
            DB::table('part_fitments')->whereIn('id', $idsASupprimer)->delete();
            if ($source !== null) {
                self::toucherLaPiece((int) $source->id);
            }
            self::toucherLaPiece((int) $cible->id);
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $rapport
     */
    private static function marquerAVerifier(array $operation, array &$rapport): void
    {
        $piece = self::piecePlateforme($operation['from_sku']);
        if ($piece === null) {
            $rapport['already_done']++;

            return;
        }

        $modele = self::trouverModele($operation['brand'], $operation['model'], $operation['generation']);
        if ($modele === null) {
            $rapport['already_done']++;

            return;
        }

        $lignes = self::lignes($piece->id, $modele->id, $operation['engine_code']);
        if ($lignes->isEmpty()) {
            $rapport['already_done']++;

            return;
        }

        $marquees = 0;
        foreach ($lignes as $ligne) {
            if (str_contains((string) ($ligne->notes ?? ''), self::MARQUEUR)) {
                continue;
            }
            self::appliquerNote($ligne, (string) $operation['source_note']);
            $marquees++;
        }

        if ($marquees === 0) {
            $rapport['already_done']++;

            return;
        }

        $rapport['flagged'] += $marquees;
        self::toucherLaPiece((int) $piece->id);
    }

    /**
     * @param  list<string>  $codes
     */
    private static function chassis(array $codes): ?string
    {
        $codes = array_values(array_filter(array_map('strval', $codes), fn (string $c): bool => trim($c) !== ''));

        return $codes === [] ? null : implode('/', $codes);
    }

    /**
     * @return list<string>
     */
    private static function ensembleChassis(?string $codes): array
    {
        $codes = array_values(array_filter(array_map('trim', explode('/', (string) $codes)), fn (string $c): bool => $c !== ''));
        sort($codes);

        return $codes;
    }

    private static function chassisCompatibles(?string $a, ?string $b): bool
    {
        $sa = self::ensembleChassis($a);
        $sb = self::ensembleChassis($b);
        if ($sa === [] || $sb === []) {
            return true;
        }

        return array_diff($sa, $sb) === [] || array_diff($sb, $sa) === [];
    }

    private static function chassisAFusionner(?string $cible, ?string $source, ?string $operation): ?string
    {
        $op = self::ensembleChassis($operation);
        if ($op !== []) {
            return self::chassis($op);
        }
        $union = array_values(array_unique(array_merge(
            self::ensembleChassis($cible),
            self::ensembleChassis($source)
        )));
        sort($union);

        return $union === [] ? null : implode('/', $union);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private static function periodesCompatibles(object $ligneCible, array $operation, object $ligneSource): bool
    {
        $debutCible = $ligneCible->year_from;
        $finCible = $ligneCible->year_to;
        $debutSource = array_key_exists('year_from', $operation) ? $operation['year_from'] : $ligneSource->year_from;
        $finSource = array_key_exists('year_to', $operation) ? $operation['year_to'] : $ligneSource->year_to;

        $debutA = $debutCible ?? 0;
        $finA = $finCible ?? 9999;
        $debutB = $debutSource ?? 0;
        $finB = $finSource ?? 9999;

        return $debutA <= $finB && $debutB <= $finA;
    }

    private static function anneeDifferente(mixed $actuelle, mixed $voulue): bool
    {
        if ($actuelle === null && $voulue === null) {
            return false;
        }

        return (int) $actuelle !== (int) $voulue || ($actuelle === null) !== ($voulue === null);
    }

    /**
     * @param  object  $ligne
     * @param  array<string, mixed>  $operation
     */
    private static function completerLigneDeplacee(object $ligne, ?string $chassis, string $noteAjout, array $operation): void
    {
        $maj = [];
        if ($chassis !== null && trim((string) ($ligne->chassis_code ?? '')) === '') {
            $maj['chassis_code'] = $chassis;
        } elseif ($chassis !== null && ! self::chassisCompatibles($ligne->chassis_code ?? null, $chassis)) {
            return;
        }
        $notes = self::ajouterNote($ligne->notes, $noteAjout);
        if ($notes !== (string) ($ligne->notes ?? '')) {
            $maj['notes'] = $notes;
        }
        if (array_key_exists('year_from', $operation) && self::anneeDifferente($ligne->year_from, $operation['year_from'])) {
            $maj['year_from'] = $operation['year_from'];
        }
        if (array_key_exists('year_to', $operation) && self::anneeDifferente($ligne->year_to, $operation['year_to'])) {
            $maj['year_to'] = $operation['year_to'];
        }
        if ($maj === []) {
            return;
        }
        $maj['updated_at'] = now();
        DB::table('part_fitments')->where('id', $ligne->id)->update($maj);
        self::toucherLaPiece((int) $ligne->part_id);
    }

    private static function appliquerNote(object $ligne, string $ajout): void
    {
        $notes = self::ajouterNote($ligne->notes, $ajout);
        if ($notes === (string) ($ligne->notes ?? '')) {
            return;
        }
        DB::table('part_fitments')->where('id', $ligne->id)->update([
            'notes' => $notes,
            'updated_at' => now(),
        ]);
    }

    private static function ajouterNote(?string $existantes, string $ajout): string
    {
        $existantes = trim((string) $existantes);
        if (str_contains($existantes, self::MARQUEUR)) {
            return $existantes;
        }
        if ($existantes === '') {
            return $ajout;
        }

        return rtrim($existantes, " \n\r\t.").'. '.$ajout;
    }

    private static function fusionnerNotes(?string $cible, ?string $source, string $ajout): string
    {
        $texte = trim((string) $cible);
        $source = trim((string) $source);
        if ($source !== '' && ($texte === '' || ! str_contains($texte, $source))) {
            $texte = $texte === '' ? $source : rtrim($texte, " \n\r\t.").'. '.$source;
        }

        return self::ajouterNote($texte, $ajout);
    }

    private static function rattacherLe10009AuYzzN2(): bool
    {
        $numero = DB::table('oem_numbers')
            ->where('normalized_number', OemNumber::normalize('90915-10009'))
            ->lockForUpdate()
            ->first();
        if ($numero === null) {
            return false;
        }

        $yzzn1 = DB::table('oem_numbers')
            ->where('normalized_number', OemNumber::normalize('90915-YZZN1'))
            ->first();
        $yzzn2 = self::piecePlateforme('90915-YZZN2');

        $modifie = false;

        $pointeEncoreVersYzzN1 = $yzzn1 !== null
            && (int) $numero->superseded_by_id === (int) $yzzn1->id;
        if ($pointeEncoreVersYzzN1) {
            DB::table('oem_numbers')->where('id', $numero->id)->update([
                'is_superseded' => false,
                'superseded_by_id' => null,
                'updated_at' => now(),
            ]);
            $modifie = true;
        }

        if ($yzzn1 !== null) {
            $pieceYzzN1 = self::piecePlateforme('90915-YZZN1');
            if ($pieceYzzN1 !== null) {
                $supprimes = DB::table('oem_number_part')
                    ->where('oem_number_id', $numero->id)
                    ->where('part_id', $pieceYzzN1->id)
                    ->delete();
                if ($supprimes > 0) {
                    $modifie = true;
                }
            }
        }

        if ($yzzn2 !== null) {
            $deja = DB::table('oem_number_part')
                ->where('oem_number_id', $numero->id)
                ->where('part_id', $yzzn2->id)
                ->exists();
            if (! $deja) {
                DB::table('oem_number_part')->insert([
                    'part_id' => $yzzn2->id,
                    'oem_number_id' => $numero->id,
                    'is_primary' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $modifie = true;
            }
        }

        return $modifie;
    }

    private static function piecePlateforme(string $sku): ?object
    {
        $pieces = DB::table('parts')->where('sku', $sku)
            ->whereNull('owner_partner_account_id')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($pieces->count() > 1) {
            throw new RuntimeException("Correction refusée : plusieurs articles plateforme portent le SKU {$sku}.");
        }

        return $pieces->first();
    }

    private static function trouverModele(string $marque, string $modele, ?string $generation): ?object
    {
        $requete = DB::table('vehicle_models as vm')
            ->join('brands as b', 'b.id', '=', 'vm.brand_id')
            ->where('b.name', $marque)
            ->where('vm.name', $modele)
            ->select('vm.*');
        if ($generation === null) {
            $requete->whereNull('vm.generation');
        } else {
            $requete->where('vm.generation', $generation);
        }
        $modeles = $requete->limit(2)->get();
        if ($modeles->count() > 1) {
            throw new RuntimeException("Correction refusée : plusieurs modèles {$marque} {$modele} {$generation}.");
        }

        return $modeles->first();
    }

    private static function lignes(int $partId, int $modeleId, string $moteur)
    {
        return DB::table('part_fitments')
            ->where('part_id', $partId)
            ->where('vehicle_model_id', $modeleId)
            ->where('engine_code', $moteur)
            ->lockForUpdate()
            ->get();
    }

    private static function toucherLaPiece(int $partId): void
    {
        DB::table('parts')->where('id', $partId)->update(['updated_at' => now()]);
    }

    /**
     * Empreinte des compatibilités hors périmètre : elles ne doivent pas bouger.
     */
    private static function empreinteHorsPerimetre(): string
    {
        $clesMobiles = self::clesMobiles();
        $lignes = DB::table('part_fitments as pf')
            ->join('parts as p', 'p.id', '=', 'pf.part_id')
            ->join('vehicle_models as vm', 'vm.id', '=', 'pf.vehicle_model_id')
            ->join('brands as b', 'b.id', '=', 'vm.brand_id')
            ->whereNull('p.deleted_at')
            ->orderBy('p.sku')
            ->orderBy('b.name')
            ->orderBy('vm.name')
            ->orderBy('vm.generation')
            ->orderBy('pf.engine_code')
            ->orderBy('pf.year_from')
            ->select([
                'p.sku',
                'b.name as brand',
                'vm.name as model',
                'vm.generation',
                'pf.engine_code',
                'pf.chassis_code',
                'pf.year_from',
                'pf.year_to',
                'pf.notes',
                'pf.source',
            ])
            ->get();

        return $lignes
            ->reject(fn ($l) => isset($clesMobiles[self::cle($l->sku, $l->brand, $l->model, $l->generation, $l->engine_code)]))
            ->map(fn ($l) => implode('|', [
                $l->sku,
                $l->brand,
                $l->model,
                $l->generation,
                $l->engine_code,
                $l->chassis_code,
                $l->year_from,
                $l->year_to,
                $l->notes,
                $l->source,
            ]))
            ->implode("\n");
    }

    /** @return array<string, true> */
    private static function clesMobiles(): array
    {
        $cles = [];
        foreach (array_merge(self::suppressions(), self::aVerifier()) as $operation) {
            $cles[self::cle(
                $operation['from_sku'],
                $operation['brand'],
                $operation['model'],
                $operation['generation'],
                $operation['engine_code'],
            )] = true;
        }
        foreach (self::deplacements() as $operation) {
            foreach ([$operation['from_sku'], $operation['to_sku']] as $sku) {
                $cles[self::cle(
                    $sku,
                    $operation['brand'],
                    $operation['model'],
                    $operation['generation'],
                    $operation['engine_code'],
                )] = true;
            }
        }

        return $cles;
    }

    private static function cle(?string $sku, ?string $marque, ?string $modele, ?string $generation, ?string $moteur): string
    {
        return implode("\t", [$sku, $marque, $modele, $generation, $moteur]);
    }
}
