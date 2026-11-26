<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réattribue aux générations XL20 / XL30 / XL40 les motorisations (cotes de
 * consommation nrcan) rattachées à la ligne nue `sienna` (décision du
 * propriétaire du 26/11/2026).
 *
 * Les cotes nrcan ne portent ni code moteur, ni châssis, ni type d'hybride :
 * seulement l'année (année-modèle canadienne quand `year_kind` le dit), la
 * cylindrée, le nombre de cylindres, la boîte, le carburant et le libellé.
 * Une règle n'est donc retenue que si ces critères désignent UNE seule
 * combinaison génération + moteur dans les sources constructeur :
 *
 *  - la BOÎTE sépare les générations aux années de transition : 5 rapports
 *    (A5) = XL20 jusqu'à l'année-modèle 2010 incluse ; 6 rapports (AS6) =
 *    XL30 2011-2016 ; 8 rapports (AS8) = XL30 2GR-FKS 2017-2020 ; variation
 *    continue (AV) 2,5 l 4 cylindres = XL40 hybride, seule motorisation de
 *    la génération ;
 *  - l'année-modèle est confrontée aux dates de fabrication EPC par châssis ;
 *  - quand `year_kind` est inconnu, l'année est lue des deux façons
 *    (année-modèle ET année de fabrication) : la cote n'est réattribuée que
 *    si les deux lectures donnent la même génération, sinon elle est mise
 *    de côté (`ambiguous`) et reste sur la ligne nue ;
 *  - le moteur et les châssis déduits sont écrits dans le journal
 *    `motorisation_generation_assignments`, PAS dans `motorisations` : ils
 *    restent à valider avant de servir de critère.
 *
 * Seule `vehicle_model_id` change : aucune cote n'est supprimée ni copiée.
 * Une cote utilisée par un véhicule de garage, liée à une finition, ou
 * vérifiée n'est pas touchée (`not_reassigned`).
 *
 * Idempotent : une cote déjà journalisée n'est pas réexaminée.
 */
final class SiennaMotorisationsReattribution
{
    public const MIGRATION = '2026_11_26_180200_reattribuer_les_motorisations_de_la_sienna';

    public const SLUG_NU = 'sienna';

    /** Libellés nrcan reconnus comme la Sienna (le suffixe ne dit que la transmission). */
    public const LIBELLES = ['sienna', 'sienna awd', 'sienna 4wd'];

    private const SRC_EPC = 'https://toyota-epc.aftermarketcatalog.com/vehicle/US';

    /**
     * @return list<array{rule: string, engine_l: float, cylinders: int, transmission: string, fuel: string, model_years: array{0: int, 1: int}, production_years: array{0: int, 1: ?int}, target: string, engine_code: string, chassis: string, evidence: list<string>}>
     */
    public static function regles(): array
    {
        return [
            [
                'rule' => 'xl20-2gr-fe-a5', 'engine_l' => 3.5, 'cylinders' => 6, 'transmission' => 'A5', 'fuel' => 'X',
                'model_years' => [2007, 2010], 'production_years' => [2006, 2009],
                'target' => 'sienna-xl20', 'engine_code' => '2GR-FE', 'chassis' => 'GSL20/GSL23/GSL25',
                'evidence' => [
                    'EPC Toyota 622420 (Sienna XL20) : GSL2# 2GR-FE de 12/2006 à 12/2009 ('.self::SRC_EPC.'/622420/info).',
                    'Toyota USA, 28/09/2009 (Sienna 2010) : V6 3,5 l et boîte automatique 5 rapports (https://pressroom.toyota.com/sienna-a-reputation-for-family-comfort-utility/).',
                    'Toyota USA, 18/12/2009 : la 2011 est la troisième génération, boîte 6 rapports (https://pressroom.toyota.com/toyota-introduces-all-new-2011-toyota-sienna-minivan/).',
                ],
            ],
            [
                'rule' => 'xl30-1ar-fe-as6', 'engine_l' => 2.7, 'cylinders' => 4, 'transmission' => 'AS6', 'fuel' => 'X',
                'model_years' => [2011, 2013], 'production_years' => [2010, 2013],
                'target' => 'sienna-xl30', 'engine_code' => '1AR-FE', 'chassis' => 'ASL30',
                'evidence' => [
                    'EPC Toyota 622430 (Sienna XL30) : ASL30 1AR-FE de 05/2010 à 08/2013 ('.self::SRC_EPC.'/622430/info).',
                    'Toyota USA, 21/12/2009 : 2011 Sienna L4 2,7 l, boîte 6 rapports (https://pressroom.toyota.com/toyota-announces-pricing-for-all-new-2011-sienna-minivan/).',
                ],
            ],
            [
                'rule' => 'xl30-2gr-fe-as6', 'engine_l' => 3.5, 'cylinders' => 6, 'transmission' => 'AS6', 'fuel' => 'X',
                'model_years' => [2011, 2016], 'production_years' => [2010, 2016],
                'target' => 'sienna-xl30', 'engine_code' => '2GR-FE', 'chassis' => 'GSL30/GSL33/GSL35',
                'evidence' => [
                    'EPC Toyota 622430 (Sienna XL30) : GSL3# 2GR-FE de 01/2010 à 08/2016 ('.self::SRC_EPC.'/622430/info).',
                    'Toyota USA, 21/12/2009 : 2011 Sienna V6 3,5 l, boîte 6 rapports (https://pressroom.toyota.com/toyota-announces-pricing-for-all-new-2011-sienna-minivan/).',
                ],
            ],
            [
                'rule' => 'xl30-2gr-fks-as8', 'engine_l' => 3.5, 'cylinders' => 6, 'transmission' => 'AS8', 'fuel' => 'X',
                'model_years' => [2017, 2020], 'production_years' => [2016, 2020],
                'target' => 'sienna-xl30', 'engine_code' => '2GR-FKS', 'chassis' => 'GSL30/GSL33/GSL35',
                'evidence' => [
                    'EPC Toyota 622430 (Sienna XL30) : GSL3# 2GR-FKS de 08/2016 à 09/2020 ('.self::SRC_EPC.'/622430/info).',
                    'Toyota Canada, 05/10/2016 : Sienna 2017, V6 3,5 l D-4S et boîte 8 rapports (https://media.toyota.ca/en/releases/2016/a-van-for-all-seasons--and-all-reasons-the-2017-toyota-sienna.html).',
                ],
            ],
            [
                'rule' => 'xl40-a25a-fxs-av', 'engine_l' => 2.5, 'cylinders' => 4, 'transmission' => 'AV', 'fuel' => 'X',
                'model_years' => [2021, 2026], 'production_years' => [2020, null],
                'target' => 'sienna-xl40', 'engine_code' => 'A25A-FXS', 'chassis' => 'AXLH40/AXLH45',
                'evidence' => [
                    'EPC Toyota 622440 (Sienna XL40) : AXLH4# dès 11/2020 ('.self::SRC_EPC.'/622440/info).',
                    'Toyota USA, 27/10/2020 : la 2021 (quatrième génération) est hybride sur toutes les versions, 2,5 l 4 cylindres (https://pressroom.toyota.com/2021-toyota-sienna-hits-dealerships-this-november/).',
                ],
            ],
        ];
    }

    /**
     * Décide pour UNE cote, sans rien écrire.
     *
     * @return array{decision: string, rule: ?array<string, mixed>, reason: string}
     */
    public static function decider(object $cote): array
    {
        $libelle = mb_strtolower(trim((string) $cote->model_raw));
        if (! in_array($libelle, self::LIBELLES, true) || strtolower((string) $cote->make_raw) !== 'toyota') {
            return ['decision' => 'not_reassigned', 'rule' => null, 'reason' => 'Libellé « '.$cote->model_raw.' » non reconnu comme une Sienna.'];
        }
        if ($cote->source !== 'nrcan') {
            return ['decision' => 'not_reassigned', 'rule' => null, 'reason' => 'Source « '.$cote->source.' » hors périmètre (règles établies pour nrcan).'];
        }

        $annee = (int) $cote->model_year;
        $correspondantes = array_values(array_filter(self::regles(), fn (array $r) => abs((float) $cote->engine_l - $r['engine_l']) < 0.05
            && (int) $cote->cylinders === $r['cylinders']
            && strtoupper((string) $cote->transmission_code) === $r['transmission']
            && strtoupper((string) $cote->fuel_code) === $r['fuel']
            && $annee >= $r['model_years'][0] && $annee <= $r['model_years'][1]));

        if ($correspondantes === []) {
            return ['decision' => 'not_reassigned', 'rule' => null, 'reason' => 'Aucune règle constructeur pour '.$annee.' '.$cote->engine_l.' l '.$cote->cylinders.' cyl. '.$cote->transmission_code.' '.$cote->fuel_code.'.'];
        }
        if (count($correspondantes) > 1) {
            return ['decision' => 'ambiguous', 'rule' => null, 'reason' => 'Plusieurs règles possibles : '.implode(', ', array_column($correspondantes, 'rule')).'.'];
        }
        $regle = $correspondantes[0];

        $sorte = $cote->year_kind ?? 'unknown';
        if ($sorte === 'model_year') {
            return ['decision' => 'reassigned', 'rule' => $regle, 'reason' => 'Année-modèle '.$annee.', '.$cote->engine_l.' l '.$cote->cylinders.' cyl., boîte '.$cote->transmission_code.' : une seule génération possible ('.$regle['target'].', '.$regle['engine_code'].').'];
        }
        if ($sorte === 'registration_year') {
            return ['decision' => 'ambiguous', 'rule' => $regle, 'reason' => 'Année d\'immatriculation : ni année-modèle ni année de fabrication.'];
        }

        // Année de nature inconnue : la réattribuer seulement si la lecture
        // « année de fabrication » donne la même génération.
        [$de, $a] = $regle['production_years'];
        if ($annee >= $de && ($a === null || $annee <= $a)) {
            return ['decision' => 'reassigned', 'rule' => $regle, 'reason' => 'Nature de l\'année inconnue, mais '.$annee.' désigne '.$regle['target'].' qu\'on la lise en année-modèle ou en année de fabrication ('.$de.'-'.($a ?? '…').') ; boîte '.$cote->transmission_code.'.'];
        }

        return ['decision' => 'ambiguous', 'rule' => $regle, 'reason' => 'Nature de l\'année inconnue : en année-modèle, '.$annee.' + boîte '.$cote->transmission_code.' désigne '.$regle['target'].' ; en année de fabrication, '.$regle['target'].' n\'est plus produite en '.$annee.' (fabrication '.$de.'-'.($a ?? '…').'). Validation manuelle requise.'];
    }

    /** @return array<string, mixed> */
    public static function run(): array
    {
        $rapport = ['reassigned' => 0, 'ambiguous' => 0, 'not_reassigned' => 0, 'already_logged' => 0, 'skipped_missing_generation' => [], 'ambiguous_ids' => []];

        if (! Schema::hasTable('motorisation_generation_assignments')) {
            $rapport['error'] = 'table motorisation_generation_assignments absente';

            return $rapport;
        }

        return DB::transaction(function () use ($rapport): array {
            $nue = DB::table('vehicle_models')->where('slug', self::SLUG_NU)->value('id');
            if ($nue === null) {
                $rapport['bare_model'] = 'absent';

                return $rapport;
            }
            $cibles = DB::table('vehicle_models')
                ->whereIn('slug', array_unique(array_column(self::regles(), 'target')))
                ->pluck('id', 'slug');

            $journalisees = DB::table('motorisation_generation_assignments')->pluck('motorisation_id')->flip();
            $utilisees = DB::table('owned_vehicles')->whereNotNull('motorisation_id')->pluck('motorisation_id')->flip();

            foreach (DB::table('motorisations')->where('vehicle_model_id', $nue)->orderBy('model_year')->orderBy('id')->get() as $cote) {
                if ($journalisees->has($cote->id)) {
                    $rapport['already_logged']++;

                    continue;
                }

                $verdict = self::decider($cote);
                if ($cote->trim_id !== null || ($cote->trim_application_id ?? null) !== null || $cote->verified_at !== null) {
                    $verdict = ['decision' => 'not_reassigned', 'rule' => $verdict['rule'], 'reason' => 'Cote liée à une finition ou vérifiée : décision manuelle.'];
                } elseif ($utilisees->has($cote->id)) {
                    $verdict = ['decision' => 'not_reassigned', 'rule' => $verdict['rule'], 'reason' => 'Cote choisie par un véhicule de garage : la déplacer changerait sa fiche.'];
                }

                $regle = $verdict['rule'];
                if ($verdict['decision'] === 'reassigned' && ! $cibles->has($regle['target'])) {
                    // Génération absente : ne rien journaliser, une exécution
                    // ultérieure pourra conclure.
                    $rapport['skipped_missing_generation'][] = $regle['target'];

                    continue;
                }

                DB::table('motorisation_generation_assignments')->insert([
                    'motorisation_id' => $cote->id,
                    'migration' => self::MIGRATION,
                    'decision' => $verdict['decision'],
                    'from_vehicle_model_slug' => self::SLUG_NU,
                    'to_vehicle_model_slug' => $verdict['decision'] === 'reassigned' ? $regle['target'] : null,
                    'rule' => $regle['rule'] ?? null,
                    'engine_code_deduced' => $regle['engine_code'] ?? null,
                    'chassis_codes_deduced' => $regle['chassis'] ?? null,
                    'reason' => $verdict['reason'],
                    'evidence' => $regle !== null ? json_encode($regle['evidence'], JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($verdict['decision'] === 'reassigned') {
                    DB::table('motorisations')->where('id', $cote->id)->where('vehicle_model_id', $nue)
                        ->update(['vehicle_model_id' => $cibles[$regle['target']]]);
                } elseif ($verdict['decision'] === 'ambiguous') {
                    $rapport['ambiguous_ids'][] = $cote->id;
                }
                $rapport[$verdict['decision']]++;
            }
            $rapport['skipped_missing_generation'] = array_values(array_unique($rapport['skipped_missing_generation']));

            return $rapport;
        });
    }

    /** Remet sur la ligne nue les cotes réattribuées par cette migration et vide son journal. */
    public static function restaurer(): int
    {
        if (! Schema::hasTable('motorisation_generation_assignments')) {
            return 0;
        }
        $nue = DB::table('vehicle_models')->where('slug', self::SLUG_NU)->value('id');
        $remises = 0;
        foreach (DB::table('motorisation_generation_assignments')->where('migration', self::MIGRATION)->get() as $ligne) {
            if ($ligne->decision === 'reassigned' && $nue !== null) {
                $cible = DB::table('vehicle_models')->where('slug', $ligne->to_vehicle_model_slug)->value('id');
                $remises += DB::table('motorisations')->where('id', $ligne->motorisation_id)
                    ->when($cible !== null, fn ($q) => $q->where('vehicle_model_id', $cible))
                    ->update(['vehicle_model_id' => $nue]);
            }
            DB::table('motorisation_generation_assignments')->where('id', $ligne->id)->delete();
        }

        return $remises;
    }
}
