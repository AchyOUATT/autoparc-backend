<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les finitions des generations Toyota restees vides, et ce qui reste refuse.
 *
 * Ce fichier verrouille deux choses de nature differente, et la seconde est
 * la plus importante :
 *
 *   1. CE QUI EST POSE. Vingt-trois finitions sur quatre generations —
 *      tacoma-iii, tacoma-iv, land-cruiser-j300 et yaris-xp130 — avec leurs
 *      noms, leur ordre, leur type de moteur et leur description.
 *
 *   2. CE QUI N'EST PAS POSE, ET POURQUOI CA DOIT LE RESTER. Neuf
 *      generations sont ecartees faute de deux sources concordantes, et six
 *      slugs nus ne peuvent rien recevoir du tout puisqu'une finition se
 *      pose sur une generation. Les tests qui gardent ces cases vides
 *      ECHOUERONT le jour ou quelqu'un les remplira — et c'est fait pour :
 *      ils obligeront a venir relire ici la raison du refus, et a l'effacer
 *      si elle a cesse de valoir. Le lot precedent a pose le meme genre de
 *      garde avec `test_les_quatre_autres_importations_restent_sans_generation`.
 *
 * LE TEST QUI COMPTE LE PLUS est `test_une_motricite_nulle_n_exclut_aucune_piece`.
 * Le commentaire du seeder affirme qu'une motricite par defaut FAUSSE cache
 * une piece qui convient, alors qu'une motricite NULLE ne filtre rien — c'est
 * sur cette affirmation que repose le choix de laisser le Tacoma sans
 * motricite par defaut. Un commentaire qui affirme un comportement doit etre
 * vrai : celui-la est mesure ici, par la chaine reelle
 * `CompatibilityService::partsForCriteria`, et pas par relecture du code.
 */
class FinitionsToyotaSecondeMoitieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les quatre generations pourvues par ce lot, dans l'ordre des rangs.
     *
     * L'ordre de ces tableaux EST l'ordre attendu en base : le test compare la
     * liste rendue par `ORDER BY rank` a celle-ci, position par position. Une
     * finition inseree au milieu sans decaler les rangs ferait echouer.
     */
    private const POSEES = [
        'tacoma-iii' => ['SR', 'SR5', 'TRD Sport', 'TRD Off-Road', 'Limited', 'TRD Pro'],
        'tacoma-iv' => [
            'SR', 'SR5', 'TRD PreRunner', 'TRD Sport', 'TRD Off-Road',
            'Limited', 'TRD Pro', 'Trailhunter',
        ],
        'land-cruiser-j300' => ['GX', 'GXL', 'VX', 'Sahara', 'GR Sport', 'Sahara ZX'],
        'yaris-xp130' => ['L', 'LE', 'SE'],
    ];

    /**
     * Les neuf generations NOMMEES qui restent sans finition, faute de sources.
     *
     * Chacune a sa raison ecrite dans `TrimsSeeder`, au bloc « LES NEUF
     * GENERATIONS ECARTEES ». Tacoma I et II sont la bien que leur modele ait
     * ete pourvu sur ses deux autres generations : l'echelle de finitions
     * n'existait pas avant le millesime 2016.
     */
    private const ECARTEES = [
        'tacoma-i', 'tacoma-ii', 'land-cruiser-prado-j120', 'fortuner-an50',
        'hilux-an10', 'land-cruiser-j100', 'rush-f800', 'auris-e180',
        'avensis-t270',
    ];

    /**
     * Les generations apparues APRES ce lot de finitions, et donc sans gamme.
     *
     * Elles sont a part des neuf ci-dessus, et il faut que cela reste clair :
     * les neuf ont ete examinees et ECARTEES faute de deux sources
     * concordantes sur leur gamme. Celles-ci n'ont pas ete examinees du tout —
     * elles n'existaient pas quand ce lot a mesure le referentiel. Le lot du
     * 21/10/2026 qui a scinde le Venza en AV10 et XU80 est passe apres.
     *
     * CE QUI OBLIGE A LES DECLARER ICI, et rien de plus : le compte de la fin
     * de ce fichier liste nommement toutes les lignes Toyota sans finition. Une
     * generation nouvelle sans gamme le ferait echouer en annoncant un
     * debordement de ce lot-ci, qui n'y est pour rien. Aucune finition n'est
     * posee, retiree ni modifiee par cet ajout.
     *
     * QUI VIENDRA POURVOIR LE VENZA les sortira de cette liste. La gamme
     * americaine du Venza n'a pas ete recoupee sur deux sources par le lot qui
     * a pose ses generations, et celui-ci avait pour consigne de ne toucher a
     * aucune finition.
     *
     * @var list<string>
     */
    private const POSEES_APRES_CE_LOT = ['venza-av10', 'venza-xu80'];

    /**
     * Les six slugs nus, qui ne peuvent rien recevoir.
     *
     * Ce n'est pas un refus faute de sources mais une impossibilite de
     * principe : leurs bornes couvrent plusieurs generations aux gammes
     * disjointes, donc une finition posee la vaudrait pour toutes sans
     * pouvoir le dire. `tacoma` et `venza` sont en plus inactifs, le premier
     * depuis le lot du Tacoma, le second depuis celui du Venza.
     *
     * ILS RESTENT SIX, et `venza` y reste avec les autres : sa ligne nue n'a
     * pas ete supprimee, seulement vidée et sortie des listes. Une finition y
     * serait toujours aussi muette, et en plus invisible.
     */
    private const SLUGS_NUS = ['avalon', 'highlander', 'sienna', 'tacoma', 'venza', 'vios'];

    /**
     * La chaine de demarrage reelle, dans l'ordre du Dockerfile.
     *
     * Surtout pas `ReferenceDataSeeder` : c'est un jeu d'essai qui cree ses
     * propres modeles sans generation, et il masquerait les doublons que ce
     * fichier surveille. `GenerationsNommeesTest` porte deja cette mise en
     * garde, pour la meme raison.
     *
     * `DrivetrainsSeeder` et `EngineTypesSeeder` passent AVANT `TrimsSeeder`,
     * sans quoi celui-ci resoudrait toutes ses motricites et tous ses moteurs
     * a null et annoncerait DONE quand meme.
     */
    private function referentiel(): void
    {
        foreach ([
            \Database\Seeders\CountriesSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\DrivetrainsSeeder::class,
            \Database\Seeders\EngineTypesSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
        ] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }

    private function finitions(): void
    {
        $this->referentiel();
        Artisan::call('db:seed', ['--class' => \Database\Seeders\TrimsSeeder::class, '--force' => true]);
    }

    /** Le catalogue de filtres, pour les controles de compatibilite. */
    private function catalogue(): void
    {
        $this->finitions();
        foreach ([
            \Database\Seeders\ManufacturersSeeder::class,
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\FiltresHuileBoutiqueSeeder::class,
        ] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }

    /** Les finitions d'un slug Toyota, dans l'ordre des rangs. */
    private function nomsDe(string $slug): array
    {
        return DB::table('trims')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
            ->join('brands', 'brands.id', '=', 'vehicle_models.brand_id')
            ->where('brands.slug', 'toyota')
            ->where('vehicle_models.slug', $slug)
            ->orderBy('trims.rank')
            ->pluck('trims.name')
            ->all();
    }

    public function test_les_quatre_generations_recoivent_exactement_leurs_finitions(): void
    {
        $this->finitions();

        foreach (self::POSEES as $slug => $attendues) {
            $this->assertSame(
                $attendues,
                $this->nomsDe($slug),
                "Les finitions de « {$slug} » ne sont plus celles que les sources recoupaient, "
                . 'ou leur ordre a change. Les sources sont nommees dans TrimsSeeder.',
            );
        }

        // Le total du lot, pour qu'un ajout silencieux ailleurs sur ces quatre
        // generations ne passe pas entre les mailles des quatre controles.
        $this->assertSame(
            23,
            array_sum(array_map(fn ($n) => count($n), array_map(fn ($s) => $this->nomsDe($s), array_keys(self::POSEES)))),
            'Ce lot pose vingt-trois finitions, pas davantage.',
        );
    }

    public function test_chaque_finition_posee_porte_une_description(): void
    {
        $this->finitions();

        // La consigne du lot l'exige, et la description est le seul endroit ou
        // le MARCHE est dit : « GX » d'Australie et « GX » du Golfe ne sont
        // pas la meme voiture. Une description vide rendrait le nom ambigu.
        $sansDescription = DB::table('trims')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
            ->whereIn('vehicle_models.slug', array_keys(self::POSEES))
            ->where(fn ($q) => $q->whereNull('trims.description')->orWhere('trims.description', ''))
            ->pluck('trims.name')
            ->all();

        $this->assertSame([], $sansDescription, 'Toute finition de ce lot doit porter une description.');
    }

    public function test_le_marche_est_nomme_dans_chaque_description(): void
    {
        $this->finitions();

        // « GXR » ne veut rien dire sans « Golfe » : le marche doit apparaitre
        // partout ou il decide. Les quatre generations de ce lot viennent de
        // deux marches seulement, et aucune description ne doit rester muette.
        //
        // CE CONTROLE A MORDU EN ECRIVANT LE LOT, et c'est pour cela qu'il est
        // ecrit sans echappatoire : six des vingt-trois descriptions ne
        // nommaient aucun marche au premier jet — la TRD Pro du Tacoma III, et
        // cinq lignes du Tacoma IV. Elles ont ete corrigees, pas excusees.
        $marches = [
            'tacoma-iii' => 'nord-américain',
            'tacoma-iv' => 'nord-américain',
            'land-cruiser-j300' => 'australien',
            'yaris-xp130' => 'nord-américain',
        ];

        foreach ($marches as $slug => $mot) {
            $descriptions = DB::table('trims')
                ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
                ->where('vehicle_models.slug', $slug)
                ->pluck('trims.description', 'trims.name');

            $this->assertNotEmpty($descriptions, "« {$slug} » doit porter des finitions.");

            foreach ($descriptions as $nom => $description) {
                $this->assertStringContainsString(
                    $mot,
                    (string) $description,
                    "La description de « {$slug} / {$nom} » ne dit pas de quel marche elle vient. "
                    . 'Un nom de finition sans marche est ambigu : « GX » d\'Australie et « GX » du '
                    . 'Golfe ne sont pas la meme voiture.',
                );
            }
        }
    }

    public function test_les_rangs_sont_contigus_et_sans_doublon(): void
    {
        $this->finitions();

        foreach (self::POSEES as $slug => $attendues) {
            $rangs = DB::table('trims')
                ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
                ->where('vehicle_models.slug', $slug)
                ->orderBy('trims.rank')
                ->pluck('trims.rank')
                ->all();

            $this->assertSame(
                range(1, count($attendues)),
                array_map('intval', $rangs),
                "Les rangs de « {$slug} » doivent aller de 1 a " . count($attendues) . ' sans trou ni doublon.',
            );
        }
    }

    public function test_le_rejeu_du_referentiel_ne_cree_aucun_doublon(): void
    {
        $this->finitions();
        $avant = DB::table('trims')->count();

        // Le vrai chemin de production : le Dockerfile rejoue ces deux seeders
        // a CHAQUE demarrage du conteneur. C'est pour cela que ce lot n'a pas
        // besoin de migration — et c'est aussi pour cela que l'idempotence de
        // la garde `$exists` sur (vehicle_model_id, name) doit etre verifiee.
        Artisan::call('db:seed', ['--class' => \Database\Seeders\VehicleModelsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\TrimsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\TrimsSeeder::class, '--force' => true]);

        $this->assertSame($avant, DB::table('trims')->count(), 'Le rejeu ne doit rien inserer.');

        $doublons = DB::table('trims')
            ->select('vehicle_model_id', 'name')
            ->groupBy('vehicle_model_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $doublons, 'Aucune finition ne doit apparaitre deux fois sur un meme modele.');

        foreach (self::POSEES as $slug => $attendues) {
            $this->assertSame($attendues, $this->nomsDe($slug), "« {$slug} » a bouge au rejeu.");
        }
    }

    public function test_aucun_modele_ni_generation_n_est_cree_par_ce_lot(): void
    {
        // La consigne l'interdit : les generations sont l'affaire du lot
        // precedent. On compare l'etat du referentiel AVANT et APRES
        // TrimsSeeder — s'il creait un modele pour y accrocher une finition,
        // la difference se verrait ici et nulle part ailleurs.
        $this->referentiel();
        $avant = VehicleModel::orderBy('id')->pluck('slug')->all();

        Artisan::call('db:seed', ['--class' => \Database\Seeders\TrimsSeeder::class, '--force' => true]);
        $apres = VehicleModel::orderBy('id')->pluck('slug')->all();

        $this->assertSame($avant, $apres, 'TrimsSeeder ne doit creer ni modele ni generation.');

        // Le compte absolu, lui, a bouge APRES ce lot : il etait de 33 quand
        // ce fichier a ete ecrit — quatre generations de Tacoma, sa ligne nue
        // inactive, et le reste — et le lot du 21/10/2026 qui a scinde le
        // Venza a ajoute `venza-av10` et `venza-xu80`.
        //
        // CE QUE CE TEST SURVEILLE EST AU-DESSUS, ET N'A PAS BOUGE : la
        // comparaison avant / apres `TrimsSeeder`. C'est elle qui prouve que
        // ce lot de finitions ne cree aucun modele. Le chiffre ci-dessous ne
        // fait que dater l'etat du referentiel, et il se met a jour avec lui.
        $toyota = Brand::where('slug', 'toyota')->firstOrFail();
        $this->assertSame(
            35,
            VehicleModel::where('brand_id', $toyota->id)->count(),
            'Toyota porte 35 lignes : 33 au moment de ce lot, plus les deux generations du Venza posees apres.',
        );
    }

    public function test_les_neuf_generations_ecartees_restent_sans_finition(): void
    {
        $this->finitions();

        foreach (self::ECARTEES as $slug) {
            $modele = VehicleModel::where('slug', $slug)->first();
            $this->assertNotNull($modele, "La generation « {$slug} » doit exister au referentiel.");

            $this->assertSame(
                0,
                DB::table('trims')->where('vehicle_model_id', $modele->id)->count(),
                "« {$slug} » est ECARTEE faute de deux sources concordantes, et sa raison est ecrite "
                . 'dans TrimsSeeder au bloc « LES NEUF GENERATIONS ECARTEES ». Si ce test echoue '
                . "parce que vous venez de la pourvoir : tant mieux, mais allez effacer la raison "
                . 'du refus, qui vient de cesser d\'etre vraie.',
            );
        }
    }

    public function test_les_six_slugs_nus_restent_sans_finition(): void
    {
        $this->finitions();

        foreach (self::SLUGS_NUS as $slug) {
            $modele = VehicleModel::where('slug', $slug)->firstOrFail();

            $this->assertNull(
                $modele->generation,
                "« {$slug} » doit rester un slug nu : c'est ce qui interdit d'y poser une finition.",
            );
            $this->assertSame(
                0,
                DB::table('trims')->where('vehicle_model_id', $modele->id)->count(),
                "Une finition se pose sur une GENERATION. « {$slug} » couvre plusieurs generations "
                . 'aux gammes disjointes : une finition posee la vaudrait pour toutes sans pouvoir le dire.',
            );
        }
    }

    public function test_la_motricite_du_tacoma_reste_nulle_sauf_la_trd_prerunner(): void
    {
        $this->finitions();

        $motricites = DB::table('trims')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
            ->leftJoin('drivetrains', 'drivetrains.id', '=', 'trims.default_drivetrain_id')
            ->whereIn('vehicle_models.slug', ['tacoma-iii', 'tacoma-iv'])
            ->pluck('drivetrains.code', 'trims.name');

        // Sur un pick-up, la motricite est une CONFIGURATION choisie a la
        // commande, pas une propriete de la finition : la meme SR5 existe en
        // propulsion et en quatre roues motrices. Aucune source consultee ne
        // la donne finition par finition, donc aucun defaut n'est pose.
        //
        // UNE SEULE EXCEPTION, et elle est sourcee au mot : Wikipedia decrit
        // la TRD PreRunner « an off-road oriented two-wheel drive trim ».
        $this->assertSame('RWD', $motricites['TRD PreRunner'], 'La TRD PreRunner est propulsion, et la source le dit.');

        foreach ($motricites as $nom => $code) {
            if ($nom === 'TRD PreRunner') {
                continue;
            }
            $this->assertNull(
                $code,
                "« {$nom} » ne doit pas porter de motricite par defaut : aucune source ne la donne "
                . 'finition par finition, et un defaut faux cache une piece qui convient.',
            );
        }
    }

    public function test_une_motricite_nulle_n_exclut_aucune_piece(): void
    {
        $this->catalogue();

        // ── LA MESURE QUI JUSTIFIE TOUT LE CHOIX CI-DESSUS ──────────────
        //
        // Le commentaire de TrimsSeeder affirme deux choses. Les voici
        // mesurees sur la chaine reelle, et non relues dans le code.

        $service = app(CompatibilityService::class);

        // 1. UNE MOTRICITE FAUSSE RETRECIT BIEN LE RESULTAT. On prend une
        //    compatibilite qui PORTE une motricite — il n'y en a que chez
        //    Lexus — et on interroge avec une AUTRE. La piece disparait.
        $avecMotricite = DB::table('part_fitments')->whereNotNull('drivetrain_id')->first();
        $this->assertNotNull(
            $avecMotricite,
            'Ce controle a besoin d\'au moins une compatibilite portant une motricite.',
        );

        $autre = DB::table('drivetrains')->where('id', '!=', $avecMotricite->drivetrain_id)->value('id');

        $sansFiltre = $service->partsForCriteria($avecMotricite->vehicle_model_id)->pluck('id');
        $bonneMotricite = $service->partsForCriteria(
            $avecMotricite->vehicle_model_id, null, null, null, $avecMotricite->drivetrain_id,
        )->pluck('id');
        $mauvaiseMotricite = $service->partsForCriteria(
            $avecMotricite->vehicle_model_id, null, null, null, $autre,
        )->pluck('id');

        $this->assertContains(
            $avecMotricite->part_id,
            $sansFiltre->all(),
            'Une motricite NULLE ne filtre rien : la condition est gardee par if ($drivetrainId !== null).',
        );
        $this->assertContains(
            $avecMotricite->part_id,
            $bonneMotricite->all(),
            'La bonne motricite laisse evidemment passer la piece.',
        );
        $this->assertNotContains(
            $avecMotricite->part_id,
            $mauvaiseMotricite->all(),
            'UNE MOTRICITE FAUSSE CACHE UNE PIECE QUI CONVIENT. C\'est tout l\'argument du lot : '
            . 'mieux vaut aucun defaut qu\'un defaut faux.',
        );

        // 2. ET AUJOURD'HUI, SUR LE TACOMA, LE NUL NE COUTE RIEN : sa seule
        //    compatibilite ne porte pas de motricite, donc les deux
        //    interrogations rendent le meme resultat. Le constat vaut pour
        //    aujourd'hui, pas pour demain — et c'est pourquoi un defaut faux
        //    est refuse malgre tout.
        $tacoma = VehicleModel::where('slug', 'tacoma-iii')->firstOrFail();
        $quatreRoues = DB::table('drivetrains')->where('code', '4WD')->value('id');

        $this->assertSame(
            $service->partsForCriteria($tacoma->id, 2020)->pluck('id')->sort()->values()->all(),
            $service->partsForCriteria($tacoma->id, 2020, null, null, $quatreRoues)->pluck('id')->sort()->values()->all(),
            'Aucune compatibilite Toyota ne porte de motricite : le defaut nul ne retire rien aujourd\'hui.',
        );

        $this->assertSame(
            0,
            DB::table('part_fitments')
                ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
                ->join('brands', 'brands.id', '=', 'vehicle_models.brand_id')
                ->where('brands.slug', 'toyota')
                ->whereNotNull('part_fitments.drivetrain_id')
                ->count(),
            'Le jour ou une compatibilite Toyota portera une motricite, ce controle le dira.',
        );
    }

    public function test_l_hybride_est_reserve_aux_deux_finitions_qui_le_recoivent_de_serie(): void
    {
        $this->finitions();

        $types = DB::table('trims')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
            ->leftJoin('engine_types', 'engine_types.id', '=', 'trims.default_engine_type_id')
            ->where('vehicle_models.slug', 'tacoma-iv')
            ->pluck('engine_types.code', 'trims.name');

        // Les deux sources disent que l'i-Force Max est DE SERIE sur TRD Pro et
        // Trailhunter, et seulement en OPTION sur TRD Sport et TRD Off-Road.
        // Une option ne change pas le type par defaut : elle est nommee dans la
        // description de ces deux lignes-la, et c'est tout.
        foreach ($types as $nom => $code) {
            $attendu = in_array($nom, ['TRD Pro', 'Trailhunter'], true) ? 'hybrid' : 'petrol';
            $this->assertSame($attendu, $code, "Le type de moteur par defaut de « {$nom} » a change.");
        }

        // La troisieme generation n'a jamais eu d'hybride du tout.
        $this->assertSame(
            [],
            DB::table('trims')
                ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
                ->join('engine_types', 'engine_types.id', '=', 'trims.default_engine_type_id')
                ->where('vehicle_models.slug', 'tacoma-iii')
                ->where('engine_types.code', 'hybrid')
                ->pluck('trims.name')
                ->all(),
            'Le Tacoma III n\'a jamais eu d\'hybride : son 2,7 et son V6 sont tous deux a essence.',
        );
    }

    public function test_le_catalogue_de_filtres_ne_contredit_pas_les_moteurs_poses(): void
    {
        $this->catalogue();

        // Source INTERNE, et la consigne demande de s'y recouper : une finition
        // dont le moteur contredit le catalogue de filtres est suspecte des
        // deux cotes. Ici les deux generations que le catalogue connait le
        // confirment, chacune sur son code moteur.
        $attendus = [
            'tacoma-iii' => '2GR-FKS',
            'yaris-xp130' => '1NZ-FE',
        ];

        foreach ($attendus as $slug => $code) {
            $modele = VehicleModel::where('slug', $slug)->firstOrFail();

            $this->assertTrue(
                DB::table('part_fitments')
                    ->where('vehicle_model_id', $modele->id)
                    ->where('engine_code', $code)
                    ->exists(),
                "Le catalogue de filtres doit toujours porter le {$code} sur « {$slug} » : "
                . 'c\'est la troisieme source, et elle est interne.',
            );

            // Et le code moteur du catalogue doit se retrouver dans les
            // descriptions, sans quoi le vendeur ne saurait pas les relier.
            $descriptions = DB::table('trims')
                ->where('vehicle_model_id', $modele->id)
                ->pluck('description')
                ->implode(' ');

            $this->assertStringContainsString(
                $code,
                $descriptions,
                "Les descriptions de « {$slug} » doivent nommer le {$code}, que le catalogue confirme.",
            );
        }

        // Les deux autres generations posees sont INCONNUES du catalogue de
        // filtres. Ce n'est pas une contradiction, c'est un trou — et le
        // commentaire du seeder l'affirme, donc il doit rester vrai.
        foreach (['tacoma-iv', 'land-cruiser-j300'] as $slug) {
            $modele = VehicleModel::where('slug', $slug)->firstOrFail();
            $this->assertSame(
                0,
                DB::table('part_fitments')->where('vehicle_model_id', $modele->id)->count(),
                "Le seeder affirme que le catalogue de filtres ne connait pas « {$slug} ». "
                . 'Si ce test echoue, c\'est que le catalogue a grandi : allez relire le commentaire.',
            );
        }
    }

    public function test_aucune_autre_marque_n_a_recu_de_finition(): void
    {
        $this->finitions();

        // La consigne borne le lot a Toyota. Les comptes des quatorze autres
        // marques sont ceux mesures avant le lot, sur base jetable : si l'un
        // bouge, c'est que ce lot a deborde.
        $attendus = [
            'bmw' => 67, 'ford' => 4, 'hyundai' => 7, 'isuzu' => 4, 'jeep' => 3,
            'kia' => 4, 'land-rover' => 3, 'lexus' => 68, 'mercedes-benz' => 4,
            'mitsubishi' => 7, 'nissan' => 10, 'peugeot' => 4, 'renault' => 4,
            'volkswagen' => 4, 'toyota' => 88,
        ];

        $mesures = DB::table('trims')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'trims.vehicle_model_id')
            ->join('brands', 'brands.id', '=', 'vehicle_models.brand_id')
            ->selectRaw('brands.slug AS marque, COUNT(trims.id) AS n')
            ->groupBy('brands.slug')
            ->orderBy('brands.slug')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->marque => (int) $r->n])
            ->all();

        ksort($attendus);
        $this->assertSame($attendus, $mesures, 'Seule Toyota doit avoir change, de 65 a 88 finitions.');
        $this->assertSame(281, DB::table('trims')->count(), '258 finitions avant ce lot, 23 posees, 281 apres.');
    }

    public function test_les_generations_toyota_encore_vides_sont_exactement_dix_sept(): void
    {
        $this->finitions();

        // Avant ce lot : dix-neuf, mesure. Quatre pourvues, donc quinze — neuf
        // generations nommees ecartees et six slugs nus. Ce compte est le
        // resume du lot, et il doit tomber juste des deux cotes.
        //
        // IL EST PASSE A DIX-SEPT, ET PAS DU FAIT DE CE LOT. Le lot du
        // 21/10/2026 qui a scinde le Venza a ajoute `venza-av10` et
        // `venza-xu80` au referentiel, sans finition ni l'un ni l'autre. Les
        // quinze d'origine sont intactes et toujours nommees : c'est la
        // reunion des trois listes qui est comparee, et les deux nouvelles
        // sont isolees dans `POSEES_APRES_CE_LOT` pour qu'on voie d'un coup
        // d'oeil ce qui appartient a ce lot et ce qui lui est arrive apres.
        $vides = DB::table('vehicle_models')
            ->join('brands', 'brands.id', '=', 'vehicle_models.brand_id')
            ->where('brands.slug', 'toyota')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('trims')
                ->whereColumn('trims.vehicle_model_id', 'vehicle_models.id'))
            ->orderBy('vehicle_models.slug')
            ->pluck('vehicle_models.slug')
            ->all();

        $attendues = array_merge(self::ECARTEES, self::SLUGS_NUS, self::POSEES_APRES_CE_LOT);
        sort($attendues);

        $this->assertSame(
            $attendues,
            $vides,
            'Dix-sept lignes Toyota restent sans finition : 9 ecartees + 6 slugs nus + 2 posees apres ce lot.',
        );
        $this->assertCount(15, array_merge(self::ECARTEES, self::SLUGS_NUS), 'Le compte de CE lot reste quinze.');
        $this->assertCount(17, $vides);
    }
}
