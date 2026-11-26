<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Qualifie le filtre plateforme 90915-YZZD2 à partir du catalogue de
 * l'équipementier d'origine Toyota Boshoku (lineup « TBAS », 12/2023, filtre
 * OS-004 = « 90915-TB001 / 90915-YZZD2 »), recoupé par des miroirs NON
 * officiels de l'EPC Toyota (groupe 15601).
 *
 * - Les quatre lignes Hilux / Fortuner déjà posées reçoivent le niveau
 *   `aftermarket_crossref` et leurs sources : elles restent candidates.
 * - Huit couples génération × moteur documentés par Boshoku ET présents dans
 *   l'EPC (miroir) sont ajoutés, au même niveau, donc candidats.
 * - Les deux lignes Prado reçoivent des revendeurs de pièces d'origine
 *   (Australie, Royaume-Uni) au même niveau, et une note « à vérifier par
 *   moteur » : elles restent candidates. Boshoku ne cite aucun Prado.
 * - La ligne Fortuner AN50 sans année reçoit l'année de début 2005 (Boshoku,
 *   Toyota Indus, EPC) ; sa fin reste ouverte.
 * - La description est corrigée (elle limitait la YZZD2 aux diesels).
 *
 * Jamais posés ici : marché, mois, châssis (critères éliminatoires de
 * FitmentRestrictions ; les années Boshoku sont des années
 * d'immatriculation), niveau confirmable, relation entre numéros d'origine.
 * Aucune compatibilité n'est héritée de la 90915-YZZD1.
 *
 * Cible par clés stables (SKU, slug, moteur, années), jamais par id.
 * N'écrit que les champs vides. Le `rollback()` est « tout ou rien » par
 * ligne : une ligne n'est rétablie (ou une ligne ajoutée supprimée) que si
 * TOUS les champs posés ici valent encore leur valeur d'origine.
 *
 * Rejoué à la fin de FiltresHuileBoutiqueSeeder, dont le
 * `fitments()->delete()` effacerait sinon preuves et lignes ajoutées.
 */
final class Toyota90915Yzzd2PreuvesBoshoku
{
    public const SKU = '90915-YZZD2';

    public const BOSHOKU_URL = 'https://www.tb-filter.toyota-boshoku.com/en/wp-content/uploads/2023/12/231227-TBAS-lineup-table_OIL.pdf';

    /** Miroir non officiel de l'EPC Toyota, groupe 15601 (filtre à huile). */
    public const EPC = 'https://toyota-epc.aftermarketcatalog.com/vehicle/';

    /** Note posée par le seeder sur les six lignes d'origine. */
    public const NOTE_ORIGINE = "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur.";

    /** Description lue en production le 26/11/2026. */
    public const DESCRIPTION_AVANT = 'Visse, moteurs diesel 2KD-FTV 2.5, 1KD-FTV 3.0, 2GD-FTV 2.4, 1GD-FTV 2.8 et certains 2TR-FE 2.7.';

    public const DESCRIPTION_APRES = 'Visse. Hilux, Fortuner et Prado hors Etats-Unis, surtout diesel (1KD-FTV, 2KD-FTV, 1GD-FTV, 2GD-FTV), aussi essence 2TR-FE. Toyota Boshoku (filtre OS-004) le donne equivalent du 90915-TB001 sur Hilux, Fortuner, Hiace, Innova et Alphard ; des revendeurs le citent aussi pour le Prado et pour d\'anciens moteurs essence (3RZ-FE). Absent du catalogue Toyota US et des EPC consultes, qui donnent 90915-20003, 90915-TB001 ou 90915-20005 selon le marche et la periode : verifier par VIN et par moteur.';

    public const INDUS_YZZD2 = 'https://direct.toyota-indus.com/oil-filter-90915-yzzd2.html';

    /** Revendeurs de pièces d'origine, fiches consultées le 26/11/2026 (non officiels). */
    public const REVENDEUR_AU = 'https://www.allfourx4.com.au/Genuine-Oil-Filter-suitable-for-Landcruiser-70-Series-Prado-Hilux-90915-YZZD2/';

    public const REVENDEUR_AU_KIT = 'https://www.allfourx4.com.au/Genuine-Filter-Kit-suitable-for-Toyota-Landcruiser-Prado-150-Series-KDJ150/';

    public const CONCESSIONNAIRE_AU = 'https://www.nunawadingtoyotaparts.com.au/brand-new-genuine-toyota-oil-filter-90915yzzd2';

    public const REVENDEUR_UK = 'https://www.milneroffroad.com/product/engine-oil-filter-genuine/';

    private const SUFFIXE_EPC = '/g/15601';

    /**
     * Lignes existantes à qualifier.
     *
     * @return list<array{model_slug: string, engine_code: string, year_from: ?int, year_to: ?int, sources: list<string>, note: string}>
     */
    public static function existantes(): array
    {
        return [
            [
                'model_slug' => 'hilux-an10', 'engine_code' => '1KD-FTV', 'year_from' => 2005, 'year_to' => 2015,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '672370'), self::epc('GR', '672380')],
                'note' => 'Toyota Boshoku (equipementier d\'origine, lineup 12/2023) : Hilux Vigo 2004~2015 (annees d\'immatriculation), 1KD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC Toyota, miroir non officiel, marche general : 90915-20003 / 90915-TB001 de 2004-08 a 2012-04 (catalogue 672370), puis jusqu\'a 2016-10 (672380). Niveau aftermarket_crossref : ligne candidate, a verifier par VIN.',
            ],
            [
                'model_slug' => 'hilux-an120', 'engine_code' => '2GD-FTV', 'year_from' => 2015, 'year_to' => 2020,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '672390'), self::epc('GR', '6723A0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Hilux Revo 2015~ (annees d\'immatriculation), 2GD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel, marche general : 90915-20003 / 90915-TB001 de 2015-05 a 2021-05 (672390), puis 90915-TB001 ou 90915-20005 au-dela (6723A0 et suivants). La borne 2020 de cette ligne n\'est pas modifiee. Niveau aftermarket_crossref : ligne candidate, a verifier par VIN.',
            ],
            [
                'model_slug' => 'fortuner-an50', 'engine_code' => '2KD-FTV', 'year_from' => 2005, 'year_from_avant' => null, 'year_to' => null,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '671380'), self::epc('GR', '6713A0'), self::INDUS_YZZD2],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Fortuner 2005~2015 (annees d\'immatriculation), 2KD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. Toyota Indus (Pakistan) : « Fortuner & Hilux (2005 Onwards) ». EPC, miroir non officiel, marche general : 90915-20003 / 90915-TB001 de 2005-01 a 2012-02 (671380), puis jusqu\'a 2016-11 (6713A0). Annee de debut 2005 posee (trois sources concordantes) ; fin laissee ouverte, car Boshoku (2015) et l\'EPC (2016-11) different. Niveau aftermarket_crossref : ligne candidate, a verifier par VIN.',
            ],
            [
                'model_slug' => 'fortuner-an160', 'engine_code' => '2GD-FTV', 'year_from' => 2015, 'year_to' => 2020,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '6713B0'), self::epc('GR', '6713C0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Fortuner 2015~ (annees d\'immatriculation), 2GD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel, marche general : 90915-20003 / 90915-TB001 de 2015-07 a 2021-04 (6713B0), puis 90915-TB001 ou 90915-20005 (6713C0 et suivants). La borne 2020 de cette ligne n\'est pas modifiee. Niveau aftermarket_crossref : ligne candidate, a verifier par VIN.',
            ],
        ];
    }

    /**
     * Couples ajoutés : présents dans le catalogue Boshoku ET dans l'EPC
     * (miroir). Années : début de production EPC, ramené aux bornes de la
     * génération ; fin = fin de génération, ou ouverte si la génération l'est.
     *
     * @return list<array{model_slug: string, engine_code: string, year_from: ?int, year_to: ?int, sources: list<string>, note: string}>
     */
    public static function nouvelles(): array
    {
        $fin = ' Niveau aftermarket_crossref : ligne candidate, a verifier par VIN. Aucun marche, mois ni chassis pose.';

        return [
            [
                'model_slug' => 'hilux-an10', 'engine_code' => '2KD-FTV', 'year_from' => 2004, 'year_to' => 2015,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '672370'), self::epc('GR', '672380')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Hilux Vigo 2004~2015 (annees d\'immatriculation), 2KD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-20003 / 90915-TB001 de 2004-08 a 2012-04 (672370), puis jusqu\'a 2016-10 (672380).'.$fin,
            ],
            [
                'model_slug' => 'hilux-an10', 'engine_code' => '2TR-FE', 'year_from' => 2005, 'year_to' => 2015,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '672370'), self::epc('GR', '672380')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Hilux Vigo 2004~2015 (annees d\'immatriculation), 2TR-FE essence 2.7, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-20003 / 90915-TB001 a partir de 2005-01 (672370), 90915-TB001 ensuite (672380).'.$fin,
            ],
            [
                'model_slug' => 'hilux-an120', 'engine_code' => '1GD-FTV', 'year_from' => 2015, 'year_to' => null,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '672390'), self::epc('GR', '6723A0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Hilux Revo 2015~ (annees d\'immatriculation), 1GD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-20003 / 90915-TB001 de 2015-05 a 2021-05 (672390), 90915-TB001 ensuite (6723A0 et suivants).'.$fin,
            ],
            [
                'model_slug' => 'hilux-an120', 'engine_code' => '2TR-FE', 'year_from' => 2015, 'year_to' => null,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '672390'), self::epc('GR', '6723A0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Hilux Revo 2015~2018 (annees d\'immatriculation), 2TR-FE essence 2.7, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-TB001 de 2015-05 a 2020-10 (672390), puis sur les catalogues suivants (6723A0 et suivants) : la fin 2018 de Boshoku n\'est donc pas reprise.'.$fin,
            ],
            [
                'model_slug' => 'fortuner-an50', 'engine_code' => '1KD-FTV', 'year_from' => 2005, 'year_to' => 2015,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '671380'), self::epc('GR', '6713A0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Fortuner 2005~2015 (annees d\'immatriculation), 1KD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-20003 / 90915-TB001 de 2005-01 a 2012-02 (671380), puis jusqu\'a 2016-11 (6713A0).'.$fin,
            ],
            [
                'model_slug' => 'fortuner-an50', 'engine_code' => '2TR-FE', 'year_from' => 2005, 'year_to' => 2015,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '671380'), self::epc('GR', '6713A0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Fortuner 2005~2015 (annees d\'immatriculation), 2TR-FE essence 2.7, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-20003 / 90915-TB001 de 2005-01 a 2012-04 (671380), 90915-TB001 jusqu\'a 2017-03 (6713A0).'.$fin,
            ],
            [
                'model_slug' => 'fortuner-an160', 'engine_code' => '1GD-FTV', 'year_from' => 2015, 'year_to' => null,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '6713B0'), self::epc('GR', '6713C0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Fortuner 2015~ (annees d\'immatriculation), 1GD-FTV, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-20003 / 90915-TB001 de 2015-07 a 2021-04 (6713B0), 90915-TB001 ensuite (6713C0 et suivants).'.$fin,
            ],
            [
                'model_slug' => 'fortuner-an160', 'engine_code' => '2TR-FE', 'year_from' => 2015, 'year_to' => null,
                'sources' => [self::BOSHOKU_URL, self::epc('GR', '6713B0'), self::epc('GR', '6713C0')],
                'note' => 'Toyota Boshoku (lineup 12/2023) : Fortuner 2015~2019 (annees d\'immatriculation), 2TR-FE essence 2.7, 90915-TB001 / 90915-YZZD2, filtre OS-004. EPC, miroir non officiel : 90915-TB001 de 2015-07 a 2021-04 (6713B0), puis sur les catalogues suivants (6713C0 et suivants) : la fin 2019 de Boshoku n\'est donc pas reprise.'.$fin,
            ],
        ];
    }

    /**
     * Lignes Prado : sources de revendeurs (non Toyota) et note « à vérifier
     * par moteur ». Elles restent candidates ; bornes, marché, mois et châssis
     * ne sont pas touchés.
     *
     * @return list<array{model_slug: string, engine_code: string, year_from: ?int, year_to: ?int, sources: list<string>, note: string}>
     */
    public static function prado(): array
    {
        return [
            [
                'model_slug' => 'land-cruiser-prado-j120', 'engine_code' => '1KD-FTV', 'year_from' => 2003, 'year_to' => 2009,
                'sources' => [self::REVENDEUR_AU, self::CONCESSIONNAIRE_AU, self::REVENDEUR_UK],
                'note' => 'Ligne candidate, a verifier par moteur et par date de fabrication. Revendeurs de pieces d\'origine (pas Toyota) : All Four x 4 Spares et Nunawading Toyota (Australie) donnent le KDJ120 1KD-FTV de 09/2002 a 07/2006 seulement ; Milner Off Road (Royaume-Uni) de 08/2006 en avant. Periodes opposees selon les sources. EPC, miroir non officiel : 90915-30002 jusqu\'a 2006-08 (Europe, 781550) ou 2007-07 (Japon, 781150), puis 90915-20003 (Europe) ; la YZZD2 n\'y figure pas. Toyota Boshoku ne cite aucun Prado. Les revendeurs australiens citent aussi le 3RZ-FE (RZJ120) : non repris. Bornes, marche, mois et chassis non modifies.',
            ],
            [
                'model_slug' => 'land-cruiser-prado-j150', 'engine_code' => '1KD-FTV', 'year_from' => 2009, 'year_to' => 2015,
                'sources' => [self::REVENDEUR_AU, self::REVENDEUR_AU_KIT, self::CONCESSIONNAIRE_AU, self::REVENDEUR_UK],
                'note' => 'Ligne candidate, a verifier par moteur. Revendeurs de pieces d\'origine (pas Toyota) : All Four x 4 Spares et Nunawading Toyota (Australie) donnent le KDJ150/155 1KD-FTV de 08/2009 a 12/2023 (fiche du filtre) ou a 08/2015 (kit d\'entretien) ; Milner Off Road (Royaume-Uni) : tous les KDJ150. Fins divergentes : bornes 2009-2015 non modifiees. EPC, miroir non officiel : 90915-20003 sur le KDJ15# 1KD-FTV de 2009-08 a 2016 (781560, coupure de variante en 2013-08 sans changement de filtre) ; la YZZD2 n\'y figure pas. Toyota Boshoku ne cite aucun Prado. Toyota Indus (Pakistan) range « Prado 2009 onwards » sous la cartouche 04152-YZZA5 pour des variantes 2.7 et 3.4, jamais 3.0 : probable confusion de moteur (cette cartouche equipe des moteurs essence aux Etats-Unis), a verifier par moteur. Marche, mois et chassis non poses.',
            ],
        ];
    }

    /** @return array<string, int|list<string>> */
    public static function run(): array
    {
        $rapport = [
            'description_corrigee' => 0,
            'fitments_qualifies' => 0,
            'fitments_ajoutes' => 0,
            'fitments_deja_presents' => 0,
            'notes_prado' => 0,
            'modeles_absents' => [],
        ];
        if (! self::schemaPret()) {
            return $rapport;
        }
        // Filet : le niveau posé ici ne doit jamais confirmer.
        if (in_array(FitmentEvidence::AFTERMARKET_CROSSREF, FitmentEvidence::SUFFICIENT, true)) {
            throw new RuntimeException('aftermarket_crossref est devenu confirmable : qualification YZZD2 refusée.');
        }

        return DB::transaction(function () use ($rapport): array {
            $piece = self::piecePlateforme();
            if ($piece === null) {
                return $rapport;
            }
            $partId = (int) $piece->id;

            $rapport['description_corrigee'] = self::remplacerDescription($partId, self::DESCRIPTION_AVANT, self::DESCRIPTION_APRES);

            foreach ([[self::existantes(), 'fitments_qualifies'], [self::prado(), 'notes_prado']] as [$cibles, $compteur]) {
                foreach ($cibles as $cible) {
                    $modele = self::modele($cible['model_slug'], $rapport);
                    if ($modele === null) {
                        continue;
                    }
                    foreach (self::lignes($partId, $modele, $cible) as $ligne) {
                        if (! self::estVide($ligne->evidence_level) || self::liste($ligne->evidence_sources) !== []
                            || $ligne->notes !== self::NOTE_ORIGINE) {
                            continue;
                        }
                        $patch = [
                            'evidence_level' => FitmentEvidence::AFTERMARKET_CROSSREF,
                            'evidence_sources' => self::json($cible['sources']),
                            'notes' => self::noteComplete($cible['note']),
                            'updated_at' => now(),
                        ];
                        // Année de début : posée seulement si elle est encore vide.
                        if (array_key_exists('year_from_avant', $cible) && $ligne->year_from === null) {
                            $patch['year_from'] = $cible['year_from'];
                        }
                        DB::table('part_fitments')->where('id', $ligne->id)->update($patch);
                        $rapport[$compteur]++;
                    }
                }
            }

            foreach (self::nouvelles() as $cible) {
                $modele = self::modele($cible['model_slug'], $rapport);
                if ($modele === null) {
                    continue;
                }
                $existe = DB::table('part_fitments')->where('part_id', $partId)
                    ->where('vehicle_model_id', $modele)->where('engine_code', $cible['engine_code'])->exists();
                if ($existe) {
                    $rapport['fitments_deja_presents']++;

                    continue;
                }
                DB::table('part_fitments')->insert([
                    'part_id' => $partId,
                    'vehicle_model_id' => $modele,
                    'engine_code' => $cible['engine_code'],
                    'year_from' => $cible['year_from'],
                    'year_to' => $cible['year_to'],
                    'source' => FitmentSource::Declared->value,
                    'evidence_level' => FitmentEvidence::AFTERMARKET_CROSSREF,
                    'evidence_sources' => self::json($cible['sources']),
                    'notes' => $cible['note'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $rapport['fitments_ajoutes']++;
            }

            $rapport['modeles_absents'] = array_values(array_unique($rapport['modeles_absents']));

            return $rapport;
        });
    }

    /** @return array<string, int> */
    public static function rollback(): array
    {
        $rapport = ['description_retablie' => 0, 'fitments_retablis' => 0, 'fitments_supprimes' => 0, 'notes_prado_retablies' => 0];
        if (! self::schemaPret()) {
            return $rapport;
        }

        return DB::transaction(function () use ($rapport): array {
            $piece = self::piecePlateforme();
            if ($piece === null) {
                return $rapport;
            }
            $partId = (int) $piece->id;
            $absents = [];

            $rapport['description_retablie'] = self::remplacerDescription($partId, self::DESCRIPTION_APRES, self::DESCRIPTION_AVANT);

            foreach ([[self::existantes(), 'fitments_retablis'], [self::prado(), 'notes_prado_retablies']] as [$cibles, $compteur]) {
                foreach ($cibles as $cible) {
                    $modele = self::modele($cible['model_slug'], $absents);
                    if ($modele === null) {
                        continue;
                    }
                    foreach (self::lignes($partId, $modele, $cible) as $ligne) {
                        $intacte = $ligne->evidence_level === FitmentEvidence::AFTERMARKET_CROSSREF
                            && self::liste($ligne->evidence_sources) === $cible['sources']
                            && $ligne->notes === self::noteComplete($cible['note'])
                            && (! array_key_exists('year_from_avant', $cible) || (int) $ligne->year_from === $cible['year_from']);
                        if (! $intacte) {
                            continue;
                        }
                        $patch = [
                            'evidence_level' => null,
                            'evidence_sources' => null,
                            'notes' => self::NOTE_ORIGINE,
                            'updated_at' => now(),
                        ];
                        if (array_key_exists('year_from_avant', $cible)) {
                            $patch['year_from'] = $cible['year_from_avant'];
                        }
                        DB::table('part_fitments')->where('id', $ligne->id)->update($patch);
                        $rapport[$compteur]++;
                    }
                }
            }

            foreach (self::nouvelles() as $cible) {
                $modele = self::modele($cible['model_slug'], $absents);
                if ($modele === null) {
                    continue;
                }
                foreach (self::lignes($partId, $modele, $cible) as $ligne) {
                    if (! self::nouvelleIntacte($ligne, $cible)) {
                        continue;
                    }
                    DB::table('part_fitments')->where('id', $ligne->id)->delete();
                    $rapport['fitments_supprimes']++;
                }
            }

            return $rapport;
        });
    }

    public static function noteComplete(string $ajout): string
    {
        return self::NOTE_ORIGINE.' '.$ajout;
    }

    public static function epc(string $marche, string $catalogue): string
    {
        return self::EPC.$marche.'/'.$catalogue.self::SUFFIXE_EPC;
    }

    private static function schemaPret(): bool
    {
        return Schema::hasTable('part_fitments') && Schema::hasColumns('part_fitments', [
            'evidence_level', 'evidence_sources', 'market_codes', 'month_from', 'month_to',
            'chassis_code', 'chassis_restricted', 'verified_by_id', 'verified_on',
        ]);
    }

    private static function piecePlateforme(): ?object
    {
        $pieces = DB::table('parts')->where('sku', self::SKU)
            ->whereNull('owner_partner_account_id')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($pieces->count() > 1) {
            throw new RuntimeException('Qualification 90915-YZZD2 refusée : plusieurs articles plateforme portent ce SKU.');
        }

        return $pieces->first();
    }

    private static function remplacerDescription(int $partId, string $de, string $vers): int
    {
        return (int) DB::table('parts')->where('id', $partId)->where('description', $de)
            ->update(['description' => $vers, 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $rapport */
    private static function modele(string $slug, array &$rapport): ?int
    {
        $id = DB::table('vehicle_models')->where('slug', $slug)->value('id');
        if ($id === null) {
            $rapport['modeles_absents'][] = $slug;

            return null;
        }

        return (int) $id;
    }

    /**
     * @param  array{engine_code: string, year_from: ?int, year_to: ?int, year_from_avant?: ?int}  $cible
     * @return list<object>
     */
    private static function lignes(int $partId, int $modele, array $cible): array
    {
        $requete = DB::table('part_fitments')->where('part_id', $partId)
            ->where('vehicle_model_id', $modele)
            ->where('engine_code', $cible['engine_code'])
            ->where('source', FitmentSource::Declared->value);
        // Année de début : valeur actuelle de la cible, ou celle d'avant la qualification.
        $debuts = array_unique([$cible['year_from'], array_key_exists('year_from_avant', $cible) ? $cible['year_from_avant'] : $cible['year_from']], SORT_REGULAR);
        $requete->where(function ($q) use ($debuts) {
            foreach ($debuts as $debut) {
                $debut === null ? $q->orWhereNull('year_from') : $q->orWhere('year_from', $debut);
            }
        });
        $cible['year_to'] === null ? $requete->whereNull('year_to') : $requete->where('year_to', $cible['year_to']);

        return $requete->orderBy('id')->lockForUpdate()->get()->all();
    }

    /** @param  array{sources: list<string>, note: string}  $cible */
    private static function nouvelleIntacte(object $ligne, array $cible): bool
    {
        return $ligne->evidence_level === FitmentEvidence::AFTERMARKET_CROSSREF
            && self::liste($ligne->evidence_sources) === $cible['sources']
            && $ligne->notes === $cible['note']
            && $ligne->trim_id === null && $ligne->engine_type_id === null && $ligne->drivetrain_id === null
            && self::estVide($ligne->position)
            && self::estVide($ligne->chassis_code) && $ligne->chassis_restricted === null
            && self::liste($ligne->market_codes) === []
            && $ligne->month_from === null && $ligne->month_to === null
            && $ligne->verified_by_id === null && $ligne->verified_on === null;
    }

    /** @param  list<string>  $valeurs */
    private static function json(array $valeurs): string
    {
        return json_encode($valeurs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function estVide(mixed $valeur): bool
    {
        return $valeur === null || $valeur === '';
    }

    /** @return list<mixed> */
    private static function liste(mixed $valeur): array
    {
        if ($valeur === null || $valeur === '' || $valeur === 'null') {
            return [];
        }
        if (is_string($valeur)) {
            $valeur = json_decode($valeur, true);
        }

        return is_array($valeur) ? array_values($valeur) : [];
    }
}
