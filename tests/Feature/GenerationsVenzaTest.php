<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les deux generations du Venza, et les quinze lignes qui ne bougent pas.
 *
 * Suite de `GenerationsTacomaYarisTest`, dont ce fichier reprend le motif.
 * Deux choses y sont verrouillees, et la seconde est la plus importante.
 *
 * *Le Venza est pose en entier.* Ses trois compatibilites ont quitte le slug
 * nu pour la generation que leurs annees designent, le compte du rayon n'a pas
 * bouge, et la ligne nue est sortie des listes sans etre supprimee. C'est le
 * geste du Tacoma, applique au seul autre modele qui le permettait.
 *
 * *Les quinze lignes du Highlander, du Sienna et de l'Avalon restent ou elles
 * sont, et ce n'est pas un oubli.* SEPT d'entre elles portent des bornes a
 * cheval sur deux generations. Ce fichier ne se contente pas de l'affirmer :
 * `test_les_sept_lignes_a_cheval_sont_exactement_sept_et_nommees` les RECALCULE
 * depuis le catalogue de filtres et les bornes des generations, et echoue si
 * leur compte change. Un lot suivant qui poserait ces generations sans
 * trancher ces sept lignes fera echouer ce fichier, et c'est exactement ce
 * qu'on lui demande.
 */
class GenerationsVenzaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les bornes de production des generations NON POSEES, pour le seul calcul
     * du chevauchement.
     *
     * Elles ne sont pas dans le referentiel, et c'est tout le sujet : ce lot ne
     * les pose pas. Elles sont ici parce qu'un chevauchement ne se constate pas
     * sans elles, et qu'une affirmation non calculable est une affirmation
     * qu'on finit par croire.
     *
     * Chaque borne est lue le 21/10/2026 dans DEUX sources concordantes,
     * Wikipedia anglais et le catalogue de drom.ru :
     *
     *   Highlander XU40  Wikipedia « May 2007 – 2013 »          drom 05.2007-12.2013
     *              XU50  Wikipedia « November 2013 – November 2019 »
     *              XU70  Wikipedia « November 2019 – present »  drom 04.2019-ouvert
     *   Sienna     XL20  Wikipedia « January 2003 – December 2009 »  drom 06.2003-12.2009
     *              XL30  Wikipedia « January 2010 – August 2020 »    drom 01.2010-05.2020
     *              XL40  Wikipedia « September 2020 – present »      drom 10.2020-ouvert
     *   Avalon     XX30  Wikipedia « December 2004 to October 2012 » drom 12.2004-10.2012
     *              XX40  Wikipedia « November 2012 to March 2018 »   drom 11.2012-05.2018
     *              XX50  Wikipedia « April 2018 to August 2022 »     drom 01.2018-ouvert
     *
     * Le verdict de chevauchement ne depend pas des desaccords de detail : la
     * XU50 finit en 2019 chez Wikipedia et en 2021 chez drom (la Chine l'a
     * prolongee), et les sept memes lignes sont a cheval dans les deux
     * lectures. C'est pourquoi une seule borne est retenue par generation.
     *
     * @var array<string, list<array{0: string, 1: int, 2: ?int}>>
     */
    private const GENERATIONS_NON_POSEES = [
        'highlander' => [
            ['XU20', 2000, 2007],
            ['XU40', 2007, 2013],
            ['XU50', 2013, 2019],
            ['XU70', 2019, null],
        ],
        'sienna' => [
            ['XL10', 1997, 2002],
            ['XL20', 2003, 2009],
            ['XL30', 2010, 2020],
            ['XL40', 2020, null],
        ],
        'avalon' => [
            ['XX10', 1994, 1999],
            ['XX20', 1999, 2004],
            ['XX30', 2004, 2012],
            ['XX40', 2012, 2018],
            ['XX50', 2018, 2022],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
            // Les trois tables dont `VehicleFactory` a besoin pour fabriquer le
            // vehicule du test de garde : sans elles, elle lit `null->id`.
            \Database\Seeders\ColorsSeeder::class,
            \Database\Seeders\EngineTypesSeeder::class,
            \Database\Seeders\DrivetrainsSeeder::class,
        ]);
    }

    private function toyota(): int
    {
        return Brand::where('slug', 'toyota')->value('id');
    }

    // ── Le Venza ─────────────────────────────────────────────────────────────

    /**
     * Les deux generations existent, avec leurs codes, leurs bornes et leurs
     * slugs.
     *
     * Les slugs sont ecrits en clair et non recalcules : un test qui
     * recalculerait `Str::slug()` passerait meme si la convention changeait, et
     * ne verifierait donc plus rien de ce que le catalogue de filtres designe.
     */
    public function test_le_venza_porte_ses_deux_generations(): void
    {
        $attendues = [
            'venza-av10' => ['AV10', 2008, 2017],
            'venza-xu80' => ['XU80', 2020, null],
        ];

        foreach ($attendues as $slug => [$generation, $debut, $fin]) {
            $modele = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->first();

            $this->assertNotNull($modele, "La generation {$slug} du Venza manque.");
            $this->assertSame('Venza', $modele->name, $slug);
            $this->assertSame($generation, $modele->generation, $slug);
            $this->assertSame($debut, $modele->production_start, "Borne basse de {$slug}.");
            $this->assertSame($fin, $modele->production_end, "Borne haute de {$slug}.");
            $this->assertTrue((bool) $modele->is_active, "{$slug} doit etre selectionnable.");
            $this->assertSame('suv', $modele->body_type, $slug);
        }
    }

    /**
     * Le trou entre les deux generations est ASSUME, et ce test le fixe.
     *
     * C'est la difference de fond avec le Tacoma, dont les quatre generations
     * couvraient 1995 a aujourd'hui bout a bout. Le Venza a ete arrete puis
     * relance : Wikipedia anglais donne la fin de production de la premiere
     * generation en 2017 et le debut de la seconde en 2020. Les annees
     * intermediaires n'ont donc aucune generation.
     *
     * Ce test existe pour qu'on ne « repare » pas ce trou par megarde en
     * etirant une borne : l'etirer ferait dire au referentiel qu'un Venza de
     * 2018 est une AV10, ce qu'aucune source ne dit.
     */
    public function test_le_trou_de_production_du_venza_est_assume_et_non_comble(): void
    {
        $generations = VehicleModel::where('brand_id', $this->toyota())
            ->where('name', 'Venza')
            ->where('is_active', true)
            ->orderBy('production_start')
            ->get();

        $this->assertCount(2, $generations, 'Le Venza doit montrer ses deux generations, et rien de plus.');

        $this->assertSame(2017, $generations->first()->production_end);
        $this->assertSame(2020, $generations->last()->production_start);

        // Et les annees du trou ne sont couvertes par rien, ligne nue
        // comprise : c'est ce qui rend le repli sans bornes necessaire.
        $toutes = VehicleModel::where('brand_id', $this->toyota())->where('name', 'Venza')->get();

        foreach ([2018, 2019] as $annee) {
            $couvrants = $toutes->filter(
                fn (VehicleModel $m) => $m->production_start !== null
                    && $annee >= $m->production_start
                    && ($m->production_end === null || $annee <= $m->production_end),
            );

            $this->assertEmpty(
                $couvrants,
                "Une ligne couvre {$annee}, annee ou le Venza n'etait pas produit : une borne a ete etiree.",
            );
        }
    }

    /**
     * La ligne nue reste en base, sort des listes, et ne revendique plus
     * d'annees.
     *
     * Les bornes vides ne sont pas de la coquetterie. `ImportConsommations` et
     * `BackfillFitments` lisent `production_start` / `production_end` SANS
     * filtrer `is_active` : une ligne nue restee a « 2009-ouvert »
     * chevaucherait ses deux propres generations et, etant la plus ancienne
     * donc lue la premiere, capterait toutes les cotes de consommation du
     * Venza. Vides, elle devient le repli de dernier recours.
     */
    public function test_la_ligne_nue_du_venza_est_desactivee_conservee_et_sans_bornes(): void
    {
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza')->first();

        $this->assertNotNull($nue, 'La ligne nue ne doit pas etre supprimee : du code la designe encore.');
        $this->assertNull($nue->generation, 'La ligne nue reste nue.');
        $this->assertFalse((bool) $nue->is_active, 'La ligne nue ferait voir « Venza » une fois de trop.');
        $this->assertNull($nue->production_start, 'Des bornes sur la ligne nue captureraient les cotes du Venza.');
        $this->assertNull($nue->production_end, 'Des bornes sur la ligne nue captureraient les cotes du Venza.');
    }

    /**
     * Et la ligne nue ne capte plus la generation produite une annee donnee.
     *
     * Mesure directe de la regle que suit `ImportConsommations` : parmi les
     * candidats d'un meme nom, le premier dont les bornes couvrent l'annee. La
     * ligne nue ne doit en couvrir aucune.
     */
    public function test_la_ligne_nue_ne_capte_plus_les_annees_du_venza(): void
    {
        $candidats = VehicleModel::where('brand_id', $this->toyota())
            ->where('name', 'Venza')
            ->orderBy('id')
            ->get();

        foreach ([2009, 2012, 2016, 2017, 2021, 2024, 2026] as $annee) {
            $couvrants = $candidats->filter(
                fn (VehicleModel $m) => $m->production_start !== null
                    && $annee >= $m->production_start
                    && ($m->production_end === null || $annee <= $m->production_end),
            );

            $this->assertNotEmpty($couvrants, "Aucune generation du Venza ne couvre {$annee}.");
            $this->assertNotContains(
                'venza',
                $couvrants->pluck('slug')->all(),
                "La ligne nue couvre encore {$annee} : elle capterait la cote de cette annee-la.",
            );
        }
    }

    /**
     * Le rejeu du seeder ne reactive pas la ligne nue.
     *
     * L'upsert de `VehicleModelsSeeder` reecrit `is_active` a chaque demarrage
     * du conteneur ; c'est `SLUGS_NUS_DESACTIVES` qui l'en empeche, et ce test
     * verifie que `venza` y a bien ete ajoute. Sans cela, la desactivation
     * posee par la migration serait annulee au premier redemarrage.
     */
    public function test_rejouer_le_referentiel_ne_reactive_pas_la_ligne_nue(): void
    {
        $this->seed(VehicleModelsSeeder::class);
        $this->seed(VehicleModelsSeeder::class);

        $this->assertFalse(
            (bool) VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza')->value('is_active'),
            'Le rejeu du referentiel a remis la ligne nue dans les listes.',
        );

        $this->assertSame(
            3,
            VehicleModel::where('brand_id', $this->toyota())->where('name', 'Venza')->count(),
            'Le rejeu a cree un Venza de plus.',
        );

        // Et il n'a pas rendu ses bornes a la ligne nue : la constante du
        // seeder les porte a null, l'upsert les reecrit a null.
        $this->assertNull(
            VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza')->value('production_start'),
            'Le rejeu a rendu ses bornes a la ligne nue : elle recapterait les cotes du Venza.',
        );
    }

    // ── Ce qui pend aux slugs, et qui ne doit rien perdre ────────────────────

    /**
     * Les trois compatibilites deplacees ont suivi leur ligne, et le compte du
     * rayon n'a pas bouge.
     *
     * Le total est mesure et non devine : il vaut ce que le catalogue porte, et
     * ce test echoue autant si une ligne disparait que si une ligne apparait.
     */
    public function test_les_compatibilites_du_venza_ont_suivi_sans_que_le_compte_bouge(): void
    {
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $total = PartFitment::count();

        // Le deplacement ne doit rien avoir laisse sur l'ancienne cible. Le
        // slug existe encore — desactive, pas supprime : on verifie ce qui
        // pend, pas ce qui existe.
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza')->firstOrFail();

        $this->assertSame(
            0,
            PartFitment::where('vehicle_model_id', $nue->id)->count(),
            'La ligne nue du Venza porte encore une compatibilite : la desactiver la rendrait inatteignable.',
        );

        $deplacees = [
            ['venza-av10', '04152-YZZA1', '1AR-FE',   2009, 2016],
            ['venza-av10', '04152-YZZA1', '2GR-FE',   2009, 2016],
            ['venza-xu80', '90915-YZZN1', 'A25A-FXS', 2021, 2024],
        ];

        foreach ($deplacees as [$slug, $sku, $moteur, $de, $a]) {
            $modele = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->firstOrFail();
            $piece  = Part::where('sku', $sku)->firstOrFail();

            $ligne = PartFitment::where('vehicle_model_id', $modele->id)
                ->where('part_id', $piece->id)
                ->where('engine_code', $moteur)
                ->first();

            $this->assertNotNull($ligne, "La compatibilite {$sku} / {$moteur} n'a pas suivi sur {$slug}.");
            $this->assertSame($de, $ligne->year_from, "Les bornes de {$slug} / {$moteur} ont bouge.");
            $this->assertSame($a, $ligne->year_to, "Les bornes de {$slug} / {$moteur} ont bouge.");

            // La generation d'accueil doit couvrir la periode de la ligne, sans
            // quoi le deplacement aurait perdu des annees en silence — une
            // compatibilite peut disparaitre sans que le compte bouge.
            $this->assertLessThanOrEqual(
                $de,
                $modele->production_start,
                "{$slug} commence apres sa compatibilite : les premieres annees ne trouveraient plus la piece.",
            );

            if ($modele->production_end !== null && $a !== null) {
                $this->assertGreaterThanOrEqual(
                    $a,
                    $modele->production_end,
                    "{$slug} s'arrete avant sa compatibilite : les dernieres annees ne trouveraient plus la piece.",
                );
            }
        }

        // Le rejeu ne change ni le compte ni la repartition.
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $this->assertSame($total, PartFitment::count(), 'Le rejeu du catalogue a change le compte des compatibilites.');
        $this->assertSame(
            0,
            PartFitment::where('vehicle_model_id', $nue->id)->count(),
            'Le rejeu a repose une compatibilite sur la ligne nue.',
        );
    }

    /**
     * Les dix-neuf lignes des cinq importations americaines sont toujours
     * dix-neuf, et on sait ou chacune pend.
     *
     * C'est la preuve qu'aucune n'a disparu : ce test echouerait aussi bien si
     * une ligne s'etait perdue dans le deplacement que si la repartition avait
     * bouge sans qu'on le dise.
     */
    public function test_les_dix_neuf_lignes_sont_toujours_dix_neuf(): void
    {
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $attendus = [
            'avalon'      => 4,
            'highlander'  => 7,
            'sienna'      => 4,
            'tacoma-iii'  => 1,
            'venza-av10'  => 2,
            'venza-xu80'  => 1,
        ];

        $mesures = [];

        foreach (array_keys($attendus) as $slug) {
            $modele = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->firstOrFail();

            $mesures[$slug] = PartFitment::where('vehicle_model_id', $modele->id)->count();
        }

        $this->assertSame($attendus, $mesures, 'La repartition des dix-neuf lignes a bouge.');
        $this->assertSame(19, array_sum($mesures), 'Les dix-neuf compatibilites confirmees ne sont plus dix-neuf.');

        // Et rien ne pend plus aux deux slugs nus vides.
        foreach (['tacoma', 'venza'] as $slug) {
            $id = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->value('id');

            $this->assertSame(0, PartFitment::where('vehicle_model_id', $id)->count(), $slug);
        }
    }

    /**
     * Un Venza de 2012 et un de 2022 trouvent chacun leur filtre, par le
     * service qui repond au client.
     *
     * Les tests ci-dessus verifient la base ; celui-ci verifie qu'un
     * deplacement de cible ne casse pas la lecture. C'est le seul endroit ou
     * l'on voit que le vendeur n'a rien perdu.
     */
    public function test_le_service_rend_les_filtres_du_venza_sur_ses_generations(): void
    {
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $service = app(CompatibilityService::class);

        $av10 = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza-av10')->firstOrFail();
        $xu80 = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza-xu80')->firstOrFail();

        $cartouche = Part::where('sku', '04152-YZZA1')->firstOrFail();
        $visse     = Part::where('sku', '90915-YZZN1')->firstOrFail();

        // Un Venza 3.5 de 2012 : la cartouche, et pas le filtre visse.
        $pieces2012 = $service->partsForCriteria($av10->id, 2012, null, null, null, '2GR-FE')->pluck('id')->all();

        $this->assertContains($cartouche->id, $pieces2012, 'Le Venza 3.5 de 2012 ne trouve plus sa cartouche.');
        $this->assertNotContains($visse->id, $pieces2012, 'Le document donne ce vehicule « Non compatible » avec la YZZN1.');

        // Un Venza hybride de 2022 : le filtre visse, et pas la cartouche.
        $pieces2022 = $service->partsForCriteria($xu80->id, 2022, null, null, null, 'A25A-FXS')->pluck('id')->all();

        $this->assertContains($visse->id, $pieces2022, 'Le Venza hybride de 2022 ne trouve plus son filtre.');
        $this->assertNotContains($cartouche->id, $pieces2022, 'Le document donne ce vehicule « Non compatible » avec la YZZA1.');
    }

    // ── Les trois modeles qui ne bougent pas, et la raison chiffree ──────────

    /**
     * Highlander, Sienna et Avalon restent nus, et actifs.
     *
     * Reprise du test que `GenerationsTacomaYarisTest` tenait pour QUATRE
     * modeles, diminue du Venza qui vient de passer. Son docbloc demandait que
     * « celui qui les posera vienne ici dire ce qu'il a fait des sept lignes » :
     * voici la reponse, et elle est la meme pour les trois qui restent — RIEN
     * N'EN A ETE FAIT, parce que les trancher appartient au proprietaire. Le
     * test suivant les recompte une par une.
     */
    public function test_les_trois_autres_importations_restent_sans_generation(): void
    {
        foreach (['highlander', 'sienna', 'avalon'] as $slug) {
            $modele = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->first();

            $this->assertNotNull($modele, "Le modele {$slug} a disparu.");
            $this->assertNull($modele->generation, "Le modele {$slug} a recu une generation : qu'a-t-on fait de ses lignes a cheval ?");
            $this->assertTrue((bool) $modele->is_active, "Le modele {$slug} porte encore ses compatibilites : le desactiver les rendrait inatteignables.");
            $this->assertNotNull($modele->production_start, "Le modele {$slug} porte encore des annees : les vider le rendrait introuvable.");
        }
    }

    /**
     * LE CALCUL QUI JUSTIFIE TOUT LE REFUS : sept lignes, et pas une de plus.
     *
     * Ce test ne relit pas un commentaire, il refait l'operation. Il prend les
     * compatibilites que `FiltresHuileBoutiqueSeeder` designe par les trois
     * slugs nus restants et, pour chacune, cherche UNE generation qui
     * CONTIENNE toute sa periode.
     *
     * ── LE CRITERE EST LA CONTENANCE, ET NON L'INTERSECTION ─────────────────
     *
     * C'est le critere du lot precedent, repris au mot : son
     * `test_les_compatibilites_ont_suivi_sans_que_le_compte_bouge` exige que la
     * generation d'accueil commence au plus tard a la borne basse de la ligne
     * et finisse au plus tot a sa borne haute. Une generation qui ne fait que
     * croiser la periode ne convient pas, et une generation qui la contient
     * convient entierement.
     *
     * CE N'EST PAS UN DETAIL D'ECRITURE, et le mesurer avec l'autre critere
     * donne un faux compte. Les plages de generations de ce referentiel SE
     * CHEVAUCHENT L'ANNEE DU CHANGEMENT — c'est la convention de tout
     * `VehicleModelsSeeder`, et `VinDecodeService::generationPour` est ecrit
     * pour trancher en retenant la plus recente. Compter les intersections
     * ferait donc paraitre a cheval toute ligne dont la derniere annee tombe
     * sur une annee de bascule, et elles sont trois. Elles ne le sont pas :
     * leur periode tient dans une seule generation.
     *
     * Ce test echouera si quelqu'un modifie les bornes d'une de ces lignes, et
     * c'est voulu : le compte de sept est la seule raison pour laquelle trois
     * modeles sur cinq attendent encore. Si ce compte tombe a zero, le refus
     * n'a plus lieu d'etre et ce fichier doit etre reecrit avec lui.
     */
    public function test_les_sept_lignes_a_cheval_sont_exactement_sept_et_nommees(): void
    {
        $aCheval  = [];
        $tiennent = [];

        foreach ($this->lignesDesSlugsNus() as [$slug, $de, $a, $moteur]) {
            $fin = $a ?? 9999;

            // Les generations dont la fenetre de production croise la periode
            // de la ligne, bornes incluses. Sert a NOMMER le probleme, jamais
            // a le decider.
            $croisees = [];

            // Les generations qui contiennent toute la periode. C'est le
            // critere, et il doit en designer exactement une.
            $contenantes = [];

            foreach (self::GENERATIONS_NON_POSEES[$slug] as [$code, $debut, $finGen]) {
                $borneHaute = $finGen ?? 9999;

                if ($de <= $borneHaute && $fin >= $debut) {
                    $croisees[] = $code;
                }

                if ($debut <= $de && $borneHaute >= $fin) {
                    $contenantes[] = $code;
                }
            }

            $etiquette = sprintf('%s %s %d-%s', $slug, $moteur, $de, $a ?? 'ouvert');

            if ($contenantes === []) {
                $aCheval[] = $etiquette.' : '.implode(' + ', $croisees);

                continue;
            }

            // Deux generations qui contiendraient la meme periode rendraient
            // le rattachement arbitraire : la ligne serait placable, mais
            // personne ne saurait ou.
            $this->assertCount(
                1,
                $contenantes,
                $etiquette.' tient dans plusieurs generations a la fois : le rattachement serait arbitraire.',
            );

            $tiennent[] = $etiquette.' : '.$contenantes[0]
                .(count($croisees) > 1 ? ' (touche '.implode(' + ', array_diff($croisees, $contenantes)).')' : '');
        }

        sort($aCheval);
        sort($tiennent);

        $this->assertSame([
            'avalon 2GR-FE 2005-2022 : XX30 + XX40 + XX50',
            'avalon 2GR-FKS 2016-2022 : XX40 + XX50',
            'highlander 1AR-FE 2009-2019 : XU40 + XU50 + XU70',
            'highlander 2GR-FE 2008-2016 : XU40 + XU50',
            'highlander 2GR-FKS 2017-2022 : XU50 + XU70',
            'highlander 2GR-FXE 2011-2016 : XU40 + XU50',
            'sienna 2GR-FE 2007-2016 : XL20 + XL30',
        ], $aCheval, 'Les lignes a cheval ne sont plus les sept connues.');

        // ── ET LES HUIT QUI TIENNENT, DONT TROIS SUR UNE ANNEE DE BASCULE ───
        //
        // Les trois marquees « touche » ont leur derniere annee sur la
        // premiere annee de production de la generation suivante. Elles
        // tiennent, mais il faut savoir que `generationPour` rendrait la
        // generation SUIVANTE pour un vehicule de cette annee-la — donc un
        // vehicule de 2019 cherche en Highlander ne trouverait pas la ligne
        // 2017-2019 posee sur la XU50. Le lot du Tacoma a rencontre et
        // documente exactement ce cas sur sa ligne 2016-2023, et l'a accepte.
        //
        // C'est rapporte ici et non corrige : passer tout le fichier des
        // annees de production aux millesimes est une decision du
        // proprietaire. Les deux lignes du Venza, elles, ne touchent aucune
        // annee de bascule — sa seule bascule serait entre 2017 et 2020, et
        // aucune de ses lignes ne s'y arrete.
        $this->assertSame([
            'avalon 2AR-FXE 2013-2018 : XX40 (touche XX50)',
            'avalon A25A-FXS 2019-2022 : XX50',
            'highlander 2GR-FXS 2017-2019 : XU50 (touche XU70)',
            'highlander A25A-FXS 2020-ouvert : XU70',
            'highlander T24A-FTS 2023-ouvert : XU70',
            'sienna 1AR-FE 2011-2013 : XL30',
            'sienna 2GR-FKS 2017-2020 : XL30 (touche XL40)',
            'sienna A25A-FXS 2021-ouvert : XL40',
        ], $tiennent,
            'Les huit lignes qui tiennent dans une seule generation ont change. '
            .'Elles ne bougent pas pour autant : deplacer les unes en laissant les sept '
            .'autres sur le slug nu cacherait une piece au vendeur qui choisit la generation.');
    }

    /**
     * Les compatibilites que le catalogue designe encore par un slug nu de
     * Highlander, Sienna ou Avalon.
     *
     * Lues dans la constante du seeder et non en base : ce sont les lignes
     * telles que le FICHIER les prescrit, et c'est ce fichier qu'un lot suivant
     * modifiera.
     *
     * @return list<array{0: string, 1: int, 2: ?int, 3: string}>
     */
    private function lignesDesSlugsNus(): array
    {
        $reflet  = new \ReflectionClass(FiltresHuileBoutiqueSeeder::class);
        $filtres = $reflet->getConstant('FILTRES');

        $lignes = [];

        foreach ($filtres as $filtre) {
            foreach ($filtre['compatibilites'] as [$slug, $de, $a, $moteur]) {
                if (isset(self::GENERATIONS_NON_POSEES[$slug])) {
                    $lignes[] = [$slug, $de, $a, $moteur];
                }
            }
        }

        $this->assertCount(
            15,
            $lignes,
            'Les trois slugs nus ne portent plus quinze lignes : une a bouge sans que ce fichier le dise.',
        );

        return $lignes;
    }

    // ── Le Vios, et la confusion qu'il aurait installee ──────────────────────

    /**
     * Le Vios reste un slug nu, et la Yaris garde son XP130.
     *
     * LE RISQUE ETAIT PRECIS, et il meritait d'etre tranche plutot que
     * contourne. Le lot precedent a corrige la Yaris 2011-2020 de « XP150 » en
     * « XP130 », en refusant d'ouvrir une ligne XP150 : « la XP150 a hayon est
     * une Yaris d'Asie, d'Amerique latine et d'Afrique du Sud ; lui ouvrir une
     * ligne serait un ajout au catalogue, pas une correction ».
     *
     * Or la troisieme generation du Vios porte EXACTEMENT ce code. Wikipedia
     * anglais, consulte le 21/10/2026, titre la troisieme generation de la
     * Yaris « Third generation (XP130, 2011; XP150, 2013) » — un seul titre
     * pour les deux codes — et ecrit que les berlines d'Asie, d'Amerique latine
     * et des Caraibes de 2013 derivent du Vios (XP150), introduit la-bas
     * « as the Yaris Sedan ».
     *
     * Poser `vios-xp150` aurait donc fait entrer par la fenetre la ligne que le
     * lot precedent avait refusee par la porte : deux slugs Toyota pour une
     * seule generation portee par deux plaques. Et ce lot-ci n'avait aucune
     * raison de le faire — le Vios ne porte AUCUNE compatibilite de filtre,
     * donc rien ne pendait a son slug nu.
     *
     * S'y ajoute que ses deux generations sont CONCURRENTES et non
     * successives : Wikipedia donne la XP150 « 2013 – present » et l'AC100
     * « 2022 – present », le nom s'etant scinde quand la Thailande a rebaptise
     * la voiture Yaris Ativ. Le referentiel ne sait representer qu'une
     * succession —
     * `CatalogueModelesTest::test_les_generations_d_un_meme_modele_ne_se_chevauchent_pas`
     * refuse deux generations qui se recouvrent. Enfin l'AC100 n'est nomme que
     * par UNE de mes deux sources : le catalogue de drom.ru, qui liste les
     * quatre autres familles du Vios, ne le connait pas.
     */
    public function test_le_vios_reste_un_slug_nu_et_la_yaris_garde_son_xp130(): void
    {
        $vios = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'vios')->first();

        $this->assertNotNull($vios, 'Le Vios a disparu du referentiel.');
        $this->assertNull($vios->generation, 'Le Vios a recu une generation : ses deux codes sont concurrents, pas successifs.');
        $this->assertTrue((bool) $vios->is_active, 'Le Vios n\'a pas de generation pour le remplacer : le desactiver le rendrait introuvable.');

        // Et surtout : aucune ligne XP150 n'a ete ouverte, sous aucun nom.
        $this->assertSame(
            [],
            VehicleModel::where('brand_id', $this->toyota())
                ->where('generation', 'XP150')
                ->pluck('slug')
                ->all(),
            'Une ligne XP150 est reapparue : le lot precedent avait refuse d\'en ouvrir une, et c\'est la meme generation.',
        );

        $this->assertSame(
            'XP130',
            VehicleModel::where('brand_id', $this->toyota())->where('slug', 'yaris-xp130')->value('generation'),
            'La correction de la Yaris a ete defaite.',
        );
    }

    // ── Le garde de la migration ────────────────────────────────────────────

    /**
     * Un slug nu ne se vide que si rien ne le porte.
     *
     * La regle vient de la consigne, mot pour mot : « Si un vehicule en
     * reference un, NE DESACTIVE PAS ». Ce test la verifie la ou elle est
     * ecrite — le garde de la migration — et non sur le seeder, qui declare
     * l'intention du referentiel.
     */
    public function test_la_migration_laisse_actif_un_slug_nu_que_reference_un_vehicule(): void
    {
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza')->firstOrFail();

        // On remet l'etat d'AVANT ce lot — active, bornee 2009-ouvert — pour
        // que le garde ait quelque chose a proteger.
        DB::table('vehicle_models')->where('id', $nue->id)->update([
            'is_active'        => true,
            'production_start' => 2009,
            'production_end'   => null,
        ]);

        \App\Models\Vehicle::factory()->create([
            'brand_id'         => $this->toyota(),
            'vehicle_model_id' => $nue->id,
        ]);

        $this->jouerLaMigration();

        $apres = VehicleModel::find($nue->id);

        $this->assertTrue(
            (bool) $apres->is_active,
            'Un vehicule reference cette ligne : la masquer priverait son vendeur de son modele.',
        );

        // Et ses bornes non plus ne sont pas vidées : le vehicule qui pend a
        // cette ligne a besoin des annees de son modele.
        $this->assertSame(2009, $apres->production_start, 'Le garde doit tout laisser, pas seulement le drapeau.');
    }

    /** Et sans vehicule, elle la vide bien, bornes comprises. */
    public function test_la_migration_vide_un_slug_nu_que_rien_ne_reference(): void
    {
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'venza')->firstOrFail();

        DB::table('vehicle_models')->where('id', $nue->id)->update([
            'is_active'        => true,
            'production_start' => 2009,
            'production_end'   => null,
        ]);

        $this->jouerLaMigration();

        $apres = VehicleModel::find($nue->id);

        $this->assertFalse((bool) $apres->is_active);
        $this->assertNull($apres->production_start);
        $this->assertNull($apres->production_end);
    }

    /**
     * Et elle ne touche pas a la ligne nue du Tacoma, qui n'est pas la sienne.
     *
     * `SLUGS_NUS_DESACTIVES` porte deux slugs desormais. Si cette migration
     * bouclait dessus, elle desactiverait `tacoma` que la migration precedente
     * a peut-etre LAISSE ACTIF a dessein, un vehicule le referencant. Une
     * migration decrit son propre changement.
     */
    public function test_la_migration_du_venza_ne_touche_pas_a_la_ligne_nue_du_tacoma(): void
    {
        $tacoma = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'tacoma')->firstOrFail();

        DB::table('vehicle_models')->where('id', $tacoma->id)->update([
            'is_active'        => true,
            'production_start' => 1995,
            'production_end'   => null,
        ]);

        $this->jouerLaMigration();

        $apres = VehicleModel::find($tacoma->id);

        $this->assertTrue((bool) $apres->is_active, 'La migration du Venza a desactive la ligne nue du Tacoma.');
        $this->assertSame(1995, $apres->production_start, 'La migration du Venza a vide les bornes du Tacoma.');
    }

    /**
     * Rejoue la migration de ce lot sur la base deja seedee.
     *
     * Elle a tourne a vide au demarrage des tests — les migrations passent
     * avant les seeders, donc la marque Toyota n'existait pas encore. C'est ce
     * second passage, sur une base peuplee, qui ressemble au deploiement.
     */
    private function jouerLaMigration(): void
    {
        $fichier = database_path('migrations/2026_10_21_000002_donner_ses_deux_generations_au_venza.php');

        $this->assertFileExists($fichier);

        (require $fichier)->up();
    }
}
