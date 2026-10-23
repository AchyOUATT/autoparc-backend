<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les quatre generations du Tacoma, et le vrai code de la Yaris.
 *
 * Deux gestes de referentiel dont le risque n'est pas dans la donnee mais dans
 * ce qui y pend. Ce fichier verrouille les trois choses qui peuvent casser en
 * silence.
 *
 * *Une compatibilite ne se perd pas en changeant de cible.* La ligne « Tacoma
 * 3.5 2GR-FKS 2016-2023 » pendait au slug nu `tacoma` ; elle pend desormais a
 * `tacoma-iii`. Le compte global des compatibilites du rayon doit etre
 * identique des deux cotes du deplacement, et la cible doit etre une
 * generation qui couvre VRAIMENT la periode de la ligne.
 *
 * *Un slug nu se desactive, il ne se supprime pas.* Sa ligne reste, et c'est
 * le seul moyen de ne pas casser ce qui la designe encore.
 *
 * *Les trois autres importations americaines restent nues.* Sept de leurs
 * compatibilites portent des bornes a cheval sur deux generations : les
 * scinder appartient au proprietaire. Un lot suivant qui poserait leurs
 * generations sans trancher ces sept lignes ferait echouer ce test, et c'est
 * exactement ce qu'on lui demande.
 *
 * ELLES ETAIENT QUATRE, ET LE VENZA EST SORTI DE LA LISTE. Le lot du
 * 21/10/2026 qui a suivi celui-ci lui a pose ses deux generations, AV10 et
 * XU80 : ses trois compatibilites tenaient chacune dans une seule generation,
 * donc aucune des sept lignes a cheval n'etait a lui. Les sept restantes sont
 * au Highlander (quatre), au Sienna (une) et a l'Avalon (deux), et aucune n'a
 * ete tranchee — `GenerationsVenzaTest` les recompte une par une et porte la
 * reponse que le docbloc ci-dessous reclamait.
 */
class GenerationsTacomaYarisTest extends TestCase
{
    use RefreshDatabase;

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

    // ── Le Tacoma ────────────────────────────────────────────────────────────

    /**
     * Les quatre generations existent, avec leurs bornes et leurs slugs.
     *
     * Les slugs sont ecrits en clair et non recalcules : un test qui
     * recalculerait `Str::slug()` passerait meme si la convention changeait, et
     * ne verifierait donc plus rien de ce que le catalogue de filtres designe.
     */
    public function test_le_tacoma_porte_ses_quatre_generations(): void
    {
        $attendues = [
            // MILLESIMES, SANS CHEVAUCHEMENT. Les bornes posees d'abord
            // etaient des annees de PRODUCTION chevauchantes, et elles
            // faisaient perdre son filtre a un Tacoma de 2023 :
            // `generationPour` tranche un chevauchement en retenant la
            // generation la plus recente, donc 2023 tombait sur la IV, qui ne
            // porte aucune compatibilite. Mesure : 1 piece avant, 0 apres.
            // Voir le commentaire de `GENERATIONS_TACOMA`.
            'tacoma-i'   => ['I',   1995, 2004],
            'tacoma-ii'  => ['II',  2005, 2015],
            'tacoma-iii' => ['III', 2016, 2023],
            'tacoma-iv'  => ['IV',  2024, null],
        ];

        foreach ($attendues as $slug => [$generation, $debut, $fin]) {
            $modele = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->first();

            $this->assertNotNull($modele, "La generation {$slug} du Tacoma manque.");
            $this->assertSame('Tacoma', $modele->name, $slug);
            $this->assertSame($generation, $modele->generation, $slug);
            $this->assertSame($debut, $modele->production_start, "Borne basse de {$slug}.");
            $this->assertSame($fin, $modele->production_end, "Borne haute de {$slug}.");
            $this->assertTrue((bool) $modele->is_active, "{$slug} doit etre selectionnable.");
            $this->assertSame('pick-up', $modele->body_type, $slug);
        }
    }

    /**
     * Les quatre generations couvrent 1995 a aujourd'hui sans trou.
     *
     * C'est la condition pour que desactiver `tacoma` ne retire aucun vehicule
     * du champ des choix possibles : la ligne nue allait de 1995 a aujourd'hui,
     * les quatre generations doivent en faire autant, bout a bout.
     */
    public function test_les_generations_du_tacoma_ne_laissent_aucune_annee_sans_choix(): void
    {
        $generations = VehicleModel::where('brand_id', $this->toyota())
            ->where('name', 'Tacoma')
            ->where('is_active', true)
            ->orderBy('production_start')
            ->get();

        $this->assertSame(1995, $generations->first()->production_start);
        $this->assertNull($generations->last()->production_end);

        // Chaque generation reprend l'annee SUIVANT la fin de la precedente,
        // au plus tard : `start(N+1) <= end(N) + 1`.
        //
        // CE TEST EXIGEAIT D'ABORD UN CHEVAUCHEMENT (`start(N+1) <= end(N)`),
        // sur la convention d'annees de production du reste du fichier. Le
        // Tacoma est passe aux MILLESIMES parce que le chevauchement lui
        // faisait perdre une piece — 2023 tombait sur la generation IV, vide,
        // `generationPour` retenant la plus recente. Des bornes jointives
        // (2004 puis 2005) ne laissent AUCUNE annee orpheline, ce que la
        // formulation precedente ne savait pas exprimer.
        //
        // Le `+ 1` autorise les deux conventions : jointive comme ici,
        // chevauchante comme ailleurs. C'est bien l'ABSENCE DE TROU qui est
        // testee, et non la convention employee pour l'obtenir.
        $precedente = null;

        foreach ($generations as $generation) {
            if ($precedente !== null) {
                $this->assertNotNull($precedente->production_end, 'Seule la derniere generation reste ouverte.');
                $this->assertLessThanOrEqual(
                    $precedente->production_end + 1,
                    $generation->production_start,
                    "Un trou entre {$precedente->slug} et {$generation->slug} : les annees intermediaires n'auraient aucune generation.",
                );
            }

            $precedente = $generation;
        }
    }

    /**
     * La ligne nue reste en base, sort des listes, et ne revendique plus
     * d'annees.
     *
     * Les bornes vides ne sont pas de la coquetterie. `ImportConsommations` et
     * `BackfillFitments` lisent `production_start` / `production_end` SANS
     * filtrer `is_active` : une ligne nue restee a « 1995-ouvert »
     * chevaucherait ses quatre propres generations et, etant la plus ancienne
     * donc lue la premiere, capterait toutes les cotes de consommation du
     * Tacoma. Vides, elle devient le repli de dernier recours.
     */
    public function test_la_ligne_nue_du_tacoma_est_desactivee_conservee_et_sans_bornes(): void
    {
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'tacoma')->first();

        $this->assertNotNull($nue, 'La ligne nue ne doit pas etre supprimee : du code la designe encore.');
        $this->assertNull($nue->generation, 'La ligne nue reste nue.');
        $this->assertFalse((bool) $nue->is_active, 'La ligne nue ferait voir « Tacoma » une fois de trop.');
        $this->assertNull($nue->production_start, 'Des bornes sur la ligne nue captureraient les cotes du Tacoma.');
        $this->assertNull($nue->production_end, 'Des bornes sur la ligne nue captureraient les cotes du Tacoma.');
    }

    /**
     * Et la ligne nue ne capte plus la generation produite une annee donnee.
     *
     * Mesure directe de la regle que suit `ImportConsommations` : parmi les
     * candidats d'un meme nom, le premier dont les bornes couvrent l'annee.
     * La ligne nue ne doit en couvrir aucune.
     */
    public function test_la_ligne_nue_ne_capte_plus_les_annees_du_tacoma(): void
    {
        $candidats = VehicleModel::where('brand_id', $this->toyota())
            ->where('name', 'Tacoma')
            ->orderBy('id')
            ->get();

        foreach ([1996, 2004, 2010, 2015, 2020, 2023, 2025] as $annee) {
            $couvrants = $candidats->filter(
                fn (VehicleModel $m) => $m->production_start !== null
                    && $annee >= $m->production_start
                    && ($m->production_end === null || $annee <= $m->production_end),
            );

            $this->assertNotEmpty($couvrants, "Aucune generation du Tacoma ne couvre {$annee}.");
            $this->assertNotContains(
                'tacoma',
                $couvrants->pluck('slug')->all(),
                "La ligne nue couvre encore {$annee} : elle capterait la cote de cette annee-la.",
            );
        }
    }

    /**
     * Le rejeu du seeder ne reactive pas la ligne nue.
     *
     * L'upsert de `VehicleModelsSeeder` reecrit `is_active` a chaque
     * demarrage du conteneur. Il ecrivait `true` en dur : sans le calcul par
     * `SLUGS_NUS_DESACTIVES`, la desactivation posee par la migration serait
     * annulee au premier redemarrage, en local comme dans les tests.
     */
    public function test_rejouer_le_referentiel_ne_reactive_pas_la_ligne_nue(): void
    {
        $this->seed(VehicleModelsSeeder::class);
        $this->seed(VehicleModelsSeeder::class);

        $this->assertFalse(
            (bool) VehicleModel::where('brand_id', $this->toyota())->where('slug', 'tacoma')->value('is_active'),
            'Le rejeu du referentiel a remis la ligne nue dans les listes.',
        );

        $this->assertSame(
            5,
            VehicleModel::where('brand_id', $this->toyota())->where('name', 'Tacoma')->count(),
            'Le rejeu a cree un Tacoma de plus.',
        );
    }

    // ── La Yaris ─────────────────────────────────────────────────────────────

    /**
     * La Yaris de 2011-2020 est une XP130, et il n'y a pas de XP150.
     *
     * La ligne portait « XP150 » sur une periode qui est celle de la XP130.
     * C'est le code qui a ete corrige, pas les annees : elles ne bougent pas,
     * et c'est ce que ce test fixe — une correction qui aurait aussi deplace
     * les bornes serait une autre decision.
     */
    public function test_la_yaris_de_2011_porte_le_code_xp130(): void
    {
        $xp130 = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'yaris-xp130')->first();

        $this->assertNotNull($xp130, 'La Yaris 2011-2020 doit porter le code XP130.');
        $this->assertSame('XP130', $xp130->generation);
        $this->assertSame(2011, $xp130->production_start, 'Les bornes ne devaient pas bouger.');
        $this->assertSame(2020, $xp130->production_end, 'Les bornes ne devaient pas bouger.');

        $this->assertNull(
            VehicleModel::where('brand_id', $this->toyota())->where('slug', 'yaris-xp150')->first(),
            'La ligne a ete corrigee, pas dedoublee : ouvrir une XP150 est un ajout au catalogue.',
        );

        // Et la correction n'a pas laisse deux Yaris sur la meme plage.
        $this->assertCount(
            1,
            VehicleModel::where('brand_id', $this->toyota())->where('name', 'Yaris')->where('production_start', 2011)->get(),
            'Deux Yaris sur 2011 : le renommage a insere au lieu de corriger.',
        );
    }

    // ── Ce qui pend aux slugs, et qui ne doit rien perdre ────────────────────

    /**
     * Les deux compatibilites deplacees ont suivi leur ligne, et le compte du
     * rayon n'a pas bouge.
     *
     * Le total est mesure et non devine : il vaut ce que le catalogue porte, et
     * ce test echoue autant si une ligne disparait que si une ligne apparait.
     */
    public function test_les_compatibilites_ont_suivi_sans_que_le_compte_bouge(): void
    {
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $total = PartFitment::count();

        // Le deplacement ne doit rien avoir laisse sur les deux anciennes
        // cibles. Les slugs existent encore — `tacoma` desactive, et
        // `yaris-xp150` renomme donc absent : on verifie ce qui pend, pas ce
        // qui existe.
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'tacoma')->firstOrFail();

        $this->assertSame(
            0,
            PartFitment::where('vehicle_model_id', $nue->id)->count(),
            'La ligne nue du Tacoma porte encore une compatibilite : la desactiver la rendrait inatteignable.',
        );

        // Et les deux cibles nouvelles les portent.
        $deplacees = [
            ['tacoma-iii',  '04152-YZZA1', '2GR-FKS', 2016, 2023],
            ['yaris-xp130', '90915-YZZN1', '1NZ-FE',  2011, 2019],
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
    }

    /**
     * Aucune compatibilite n'est restee orpheline.
     *
     * Le seeder des filtres se CONTENTE D'AVERTIR quand un slug est
     * introuvable, et l'avertissement part dans la sortie d'une migration que
     * personne ne relit. Une faute de frappe sur `tacoma-iii` ferait donc
     * perdre la ligne sans erreur. Ce test compte les lignes attendues par le
     * fichier et les compare a celles qui sont en base.
     */
    public function test_aucun_slug_du_catalogue_de_filtres_n_est_introuvable(): void
    {
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $reflet = new \ReflectionClass(FiltresHuileBoutiqueSeeder::class);
        $filtres = $reflet->getConstant('FILTRES');

        $attendues = 0;
        $introuvables = [];

        foreach ($filtres as $filtre) {
            foreach ($filtre['compatibilites'] as $ligne) {
                $attendues++;

                if (VehicleModel::where('slug', $ligne[0])->doesntExist()) {
                    $introuvables[] = $filtre['sku'].' -> '.$ligne[0];
                }
            }
        }

        $this->assertSame([], $introuvables, 'Des compatibilites designent un modele qui n\'existe pas.');
        $this->assertSame($attendues, PartFitment::count(), 'Le compte en base ne correspond pas au fichier.');
    }

    /**
     * Les trois autres importations americaines restent nues, et actives.
     *
     * Sept de leurs compatibilites portent des bornes a cheval sur deux
     * generations : « Highlander 3.5 2GR-FE 2008-2016 » traverse la XU40 et la
     * XU50, « Avalon 3.5 2GR-FE 2005-2022 » traverse les XX30, XX40 et XX50.
     * Scinder une compatibilite est une decision du proprietaire, et poser les
     * generations sans la prendre ferait perdre des annees de couverture tout
     * en laissant le compte de lignes intact.
     *
     * Ce test n'interdit donc pas de les poser un jour : il exige que celui qui
     * les posera vienne ici dire ce qu'il a fait des sept lignes.
     *
     * LE VENZA EST VENU LE DIRE, ET IL EST SORTI DE CETTE LISTE. Aucune des
     * sept n'etait a lui : ses trois lignes tenaient chacune dans une seule
     * generation. Les sept restent entieres, et la liste est passee de quatre
     * modeles a trois — voir `GenerationsVenzaTest`, qui les recalcule.
     */
    public function test_les_trois_autres_importations_restent_sans_generation(): void
    {
        foreach (['highlander', 'sienna', 'avalon'] as $slug) {
            $modele = VehicleModel::where('brand_id', $this->toyota())->where('slug', $slug)->first();

            $this->assertNotNull($modele, "Le modele {$slug} a disparu.");
            $this->assertNull($modele->generation, "Le modele {$slug} a recu une generation : qu'a-t-on fait de ses lignes a cheval ?");
            $this->assertTrue((bool) $modele->is_active, "Le modele {$slug} porte encore ses compatibilites : le desactiver les rendrait inatteignables.");
        }
    }

    // ── Ce que `is_active = false` fait, et ce qu'il ne fait pas ────────────

    /**
     * MESURE : la route publique des modeles NE CACHE PAS la ligne inactive.
     *
     * Ce test ne fixe pas un comportement souhaitable, il fixe le comportement
     * REEL — et c'est pour cela qu'il existe. `VehicleModelsSeeder` affirme en
     * commentaire que la desactivation ne suffit pas sur cette route ; un
     * commentaire qui affirme un comportement doit etre vrai, et c'est ici
     * qu'on le verifie.
     *
     * `BrandController::models` ne filtre pas `is_active`, et
     * `VehicleModelResource` n'expose meme pas la colonne : le client n'a donc
     * aucun moyen de trancher. Un vendeur voit « Tacoma » cinq fois sur cette
     * route.
     *
     * CE TEST DOIT ETRE INVERSE LE JOUR OU LA ROUTE EST CORRIGEE, et le
     * commentaire du seeder reecrit avec lui. Rendre la route conforme est un
     * changement de comportement public, donc une decision du proprietaire.
     */
    public function test_mesure_la_route_publique_des_modeles_ignore_le_drapeau_actif(): void
    {
        $toyota = Brand::find($this->toyota());

        $reponse = $this->getJson("/api/catalog/brands/{$toyota->id}/models")->assertOk();

        $slugs = collect($reponse->json('data'))->pluck('slug')->all();

        $this->assertContains('tacoma', $slugs,
            'La route filtre desormais is_active : le commentaire de VehicleModelsSeeder est a reecrire.');

        $this->assertCount(5, array_filter($slugs, fn ($s) => str_starts_with($s, 'tacoma')),
            'Cinq lignes « Tacoma » sur cette route, dont une inactive : c\'est la mesure, pas un souhait.');

        // Et le client ne peut pas filtrer lui-meme : la ressource n'expose
        // pas la colonne.
        $this->assertArrayNotHasKey('is_active', collect($reponse->json('data'))->first(),
            'La ressource expose desormais is_active : le commentaire de VehicleModelsSeeder est a reecrire.');
    }

    /**
     * MESURE : la synchronisation du cache local, elle, expedie le drapeau.
     *
     * `CatalogSyncController` ne filtre pas davantage, mais il envoie
     * `is_active` dans `vehicle_models`. L'application a donc de quoi trancher
     * cote cache, a condition de s'en servir — ce que ce depot ne peut pas
     * verifier.
     */
    public function test_mesure_la_synchronisation_expedie_le_drapeau_actif(): void
    {
        $reponse = $this->getJson('/api/catalog/sync')->assertOk();

        $tacoma = collect($reponse->json('vehicle_models'))->firstWhere('slug', 'tacoma');

        $this->assertNotNull($tacoma, 'La ligne nue doit rester synchronisee : des references la designent.');
        $this->assertArrayHasKey('is_active', $tacoma,
            'Sans ce drapeau, le cache local ne pourrait pas ecarter la ligne nue.');
        $this->assertFalse((bool) $tacoma['is_active']);
    }

    /**
     * Un slug nu ne se desactive que si rien ne le porte.
     *
     * La regle vient de la consigne, mot pour mot : « Si un vehicule en
     * reference un, NE DESACTIVE PAS ». Ce test la verifie la ou elle est
     * ecrite — le garde de la migration — et non sur le seeder, qui declare
     * l'intention du referentiel.
     */
    public function test_la_migration_laisse_actif_un_slug_nu_que_reference_un_vehicule(): void
    {
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'tacoma')->firstOrFail();

        // On remet l'etat d'AVANT ce lot — active, bornee 1995-ouvert — pour
        // que le garde ait quelque chose a proteger.
        DB::table('vehicle_models')->where('id', $nue->id)->update([
            'is_active'        => true,
            'production_start' => 1995,
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
        $this->assertSame(1995, $apres->production_start, 'Le garde doit tout laisser, pas seulement le drapeau.');
    }

    /** Et sans vehicule, elle la desactive bien, bornes comprises. */
    public function test_la_migration_desactive_un_slug_nu_que_rien_ne_reference(): void
    {
        $nue = VehicleModel::where('brand_id', $this->toyota())->where('slug', 'tacoma')->firstOrFail();

        DB::table('vehicle_models')->where('id', $nue->id)->update([
            'is_active'        => true,
            'production_start' => 1995,
            'production_end'   => null,
        ]);

        $this->jouerLaMigration();

        $apres = VehicleModel::find($nue->id);

        $this->assertFalse((bool) $apres->is_active);
        $this->assertNull($apres->production_start);
        $this->assertNull($apres->production_end);
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
        $fichier = database_path('migrations/2026_10_21_000001_donner_ses_generations_au_tacoma_et_son_code_a_la_yaris.php');

        $this->assertFileExists($fichier);

        (require $fichier)->up();
    }
}
