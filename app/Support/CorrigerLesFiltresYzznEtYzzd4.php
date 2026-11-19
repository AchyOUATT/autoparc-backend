<?php

namespace App\Support;

use App\Enums\FitmentSource;
use App\Models\OemNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Priorités 1 à 3 de l'audit filtres à huile (règle par châssis, pas par moteur).
 *
 * 1. Retirer la Corolla E140 1ZZ-FE du YZZN1, et déplacer vers le 10003
 *    les lignes HKS documentées au 90915-10003 (Yaris XP130, Prius XW50,
 *    Corolla E180 et E210 2ZR).
 * 2. Déplacer les lignes A25A / M20A / T24A du YZZN1 vers le YZZN2, et
 *    rattacher le 90915-10009 au YZZN2 au lieu du YZZN1.
 * 3. Retirer du YZZD4 le Prado J120 5L-E et le Land Cruiser J200 1GR-FE
 *    (aucune source châssis ne les donne à cette référence).
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
     * @return list<array<string, mixed>>
     */
    public static function deplacements(): array
    {
        return [
            // Priorité 1 : vers le 90915-10003 (HKS).
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
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source châssis : HKS, Vitz NCP131, 1NZ-FE, 12/2010–01/2020, 90915-10003. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Prius',
                'generation' => 'XW50',
                'engine_code' => '2ZR-FXE',
                'chassis_codes' => ['ZVW50', 'ZVW51', 'ZVW55'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source châssis : HKS, Prius ZVW50/ZVW51/ZVW55, 2ZR-FXE, 09/2017–12/2022, 90915-10003. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E180',
                'engine_code' => '2ZR-FAE',
                'chassis_codes' => ['ZRE186H'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source châssis : HKS, Auris ZRE186H, 2ZR-FAE, 09/2016–05/2018, 90915-10003. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E180',
                'engine_code' => '2ZR-FE',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003, avec les Corolla E180 2ZR. HKS documente le 2ZR-FAE (Auris ZRE186H) au 90915-10003, sans code châssis pour le 2ZR-FE. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => '2ZR-FAE',
                'chassis_codes' => ['ZRE212', 'ZRE212W'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source châssis : HKS, Corolla ZRE212 et Corolla Touring ZRE212W, 2ZR-FAE, 09–10/2019–09/2022, 90915-10003. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => '2ZR-FXE',
                'chassis_codes' => ['ZWE211', 'ZWE214', 'ZWE215', 'ZWE219'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003. Source châssis : HKS, Corolla ZWE211/ZWE214 (09/2019–09/2022) et ZWE215/ZWE219 (10/2022–), 2ZR-FXE, 90915-10003. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-10003',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => '2ZR-FE',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-10003, avec les Corolla E210 2ZR. HKS documente le 2ZR-FAE (ZRE212) au 90915-10003, sans code châssis pour le 2ZR-FE. '.self::HKS_URL.' '.self::MARQUEUR,
            ],

            // Priorité 2 : vers le 90915-YZZN2 (HKS / Boshoku, 10009).
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Camry',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AXVH70'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, Camry AXVH70, A25A-FXS, 01/2017–12/2023, 90915-10009. Boshoku : Camry 2018~, A25A-FXS, 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Camry',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FKS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Boshoku : Camry 2018~, A25A-FKB, 90915-10009 / 90915-YZZN2. HKS documente le jumeau hybride AXVH70 (A25A-FXS), sans code châssis pour l’A25A-FKS. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'RAV4',
                'generation' => 'XA50',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AXAH52', 'AXAH54', 'AXAP54'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, RAV4 AXAH52/AXAH54 (04/2019–11/2025) et AXAP54 PHEV (06/2020–11/2025), A25A-FXS, 90915-10009. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'RAV4',
                'generation' => 'XA50',
                'engine_code' => 'A25A-FKS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. HKS documente le RAV4 XA50 A25A-FXS (AXAH52/54) et M20A-FKS (MXAA52/54) au 90915-10009, sans code châssis pour l’A25A-FKS. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Avalon',
                'generation' => null,
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. HKS et Boshoku associent la famille A25A-FXS au 90915-10009 / 90915-YZZN2, sans en documenter le châssis pour l’Avalon. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Highlander',
                'generation' => null,
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Boshoku et HKS associent la famille A25A-FXS au 90915-10009 / 90915-YZZN2. HKS ne donne un châssis Highlander (AXUH78R) qu’à partir de 04/2026, trop tardif pour cette ligne. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Highlander',
                'generation' => null,
                'engine_code' => 'T24A-FTS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Boshoku (Crown 2022~, T24A-FTS) et HKS (Lexus NX/RX T24A-FTS) associent ce moteur au 90915-10009 / 90915-YZZN2, sans châssis Highlander. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Sienna',
                'generation' => null,
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. HKS et Boshoku associent la famille A25A-FXS au 90915-10009 / 90915-YZZN2, sans en documenter le châssis pour la Sienna. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Venza',
                'generation' => 'XU80',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. HKS et Boshoku associent la famille A25A-FXS au 90915-10009 / 90915-YZZN2, sans en documenter le châssis pour le Venza. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'generation' => 'E210',
                'engine_code' => 'M20A-FKS',
                'chassis_codes' => ['MZEA12H', 'MZEA12W'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, Corolla Sport MZEA12H (10/2022–04/2025) et Corolla Touring MZEA12W (06/2020–04/2025), M20A-FKS, 90915-10009. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'ES',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AXZH10'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, ES300h AXZH10, A25A-FXS, 01/2022–05/2026, 90915-10009. Boshoku : ES 2019~, A25A-FXS, 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'ES',
                'generation' => 'XV70',
                'engine_code' => 'A25A-FKS',
                'chassis_codes' => [],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Boshoku documente l’ES 2019~ en A25A-FXS (90915-10009 / 90915-YZZN2). HKS n’a pas de code châssis pour l’A25A-FKS. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'NX',
                'generation' => 'AZ20',
                'engine_code' => 'A25A-FKS',
                'chassis_codes' => ['AAZA20', 'AAZA25'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, NX250 AAZA20/AAZA25, A25A-FKS, 10/2021–, 90915-10009. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'NX',
                'generation' => 'AZ20',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AAZH20', 'AAZH25', 'AAZH26'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, NX350h AAZH20/AAZH25 et NX450h+ AAZH26, A25A-FXS, 10/2021–, 90915-10009. Boshoku : NX 2022~, A25A-FXS, 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'RX',
                'generation' => 'AL30',
                'engine_code' => 'A25A-FXS',
                'chassis_codes' => ['AALH10', 'AALH15', 'AALH16'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, RX350h AALH10/AALH15 (07/2023–) et RX450h+ AALH16 (11/2022–), A25A-FXS, 90915-10009. Boshoku : RX 2023~, A25A-FXS, 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'UX',
                'generation' => 'ZA10',
                'engine_code' => 'M20A-FKS',
                'chassis_codes' => ['MZAA10'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, UX200 MZAA10, M20A-FKS, 11/2018–12/2023, 90915-10009. '.self::HKS_URL.' '.self::MARQUEUR,
            ],
            [
                'from_sku' => '90915-YZZN1',
                'to_sku' => '90915-YZZN2',
                'brand' => 'Lexus',
                'model' => 'UX',
                'generation' => 'ZA10',
                'engine_code' => 'M20A-FXS',
                'chassis_codes' => ['MZAH10', 'MZAH15', 'MZAH11', 'MZAH16'],
                'source_note' => 'Déplacée du 90915-YZZN1 vers le 90915-YZZN2. Source châssis : HKS, UX250h MZAH10/MZAH15 (11/2018–12/2023) et UX300h MZAH11/MZAH16 (01/2024–), M20A-FXS, 90915-10009. Boshoku : UX 2018~, M20A-FXS, 90915-10009 / 90915-YZZN2. '.self::HKS_URL.' ; '.self::BOSHOKU_URL.' '.self::MARQUEUR,
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
            foreach ($lignesCible as $ligne) {
                self::completerLigneDeplacee($ligne, $chassis, $noteAjout, $operation);
            }
            DB::table('part_fitments')->whereIn('id', $lignesSource->pluck('id'))->delete();
            if ($source !== null) {
                self::toucherLaPiece((int) $source->id);
            }
            self::toucherLaPiece((int) $cible->id);
            $rapport['moved'] += $lignesSource->count();

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
     * @param  list<string>  $codes
     */
    private static function chassis(array $codes): ?string
    {
        $codes = array_values(array_filter(array_map('strval', $codes), fn (string $c): bool => trim($c) !== ''));

        return $codes === [] ? null : implode('/', $codes);
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
        }
        $notes = self::ajouterNote($ligne->notes, $noteAjout);
        if ($notes !== (string) ($ligne->notes ?? '')) {
            $maj['notes'] = $notes;
        }
        if (array_key_exists('year_from', $operation) && (int) $ligne->year_from !== (int) $operation['year_from']) {
            $maj['year_from'] = $operation['year_from'];
        }
        if (array_key_exists('year_to', $operation) && (int) $ligne->year_to !== (int) $operation['year_to']) {
            $maj['year_to'] = $operation['year_to'];
        }
        if ($maj === []) {
            return;
        }
        $maj['updated_at'] = now();
        DB::table('part_fitments')->where('id', $ligne->id)->update($maj);
        self::toucherLaPiece((int) $ligne->part_id);
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
        $numeroYzzN2 = DB::table('oem_numbers')
            ->where('normalized_number', OemNumber::normalize('90915-YZZN2'))
            ->first();

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

        // $numeroYzzN2 n'est lu que pour s'assurer qu'on ne crée pas de
        // succession inventée : le 10009 est associé, pas remplacé.
        unset($numeroYzzN2);

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
     * YZZN1, YZZN2, les destinations du 10003 et les deux retraits du YZZD4
     * sont exclus.
     */
    private static function empreinteHorsPerimetre(): string
    {
        $clesMobiles = self::clesMobiles();

        $lignes = DB::table('part_fitments as pf')
            ->join('parts as p', 'p.id', '=', 'pf.part_id')
            ->join('vehicle_models as vm', 'vm.id', '=', 'pf.vehicle_model_id')
            ->leftJoin('brands as b', 'b.id', '=', 'vm.brand_id')
            ->whereNull('p.owner_partner_account_id')
            ->orderBy('p.sku')
            ->orderBy('b.name')
            ->orderBy('vm.name')
            ->orderBy('vm.generation')
            ->orderBy('pf.engine_code')
            ->orderBy('pf.year_from')
            ->orderBy('pf.notes')
            ->get([
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
            ]);

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
        foreach (self::suppressions() as $operation) {
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
