<?php

use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pose les quatre generations du Tacoma, et rend son vrai code a la Yaris.
 *
 * Deux gestes, et un seul fichier parce qu'un seul des deux peut aboutir sans
 * l'autre : la relance du catalogue de filtres qui les conclut est commune.
 *
 * ── CE QUE CE LOT NE FAIT PAS, ET C'EST L'ESSENTIEL ─────────────────────────
 *
 * Une liste de candidats proposait des generations pour SIX modeles —
 * Highlander, Sienna, Avalon, Venza, Vios et Tacoma. Un seul est pose. Le
 * detail de chaque refus est dans `VehicleModelsSeeder::GENERATIONS_TACOMA` et
 * dans le rapport de lot ; la raison de fond tient en une phrase :
 *
 *     Dix-neuf compatibilites de filtres, recoupees une a une contre un
 *     catalogue constructeur, pendent a ces slugs nus — et SEPT d'entre elles
 *     portent des bornes d'annees a cheval sur deux generations.
 *
 * Exemple mesure : la ligne « Highlander 3.5 2GR-FE 2008-2016 » couvre la XU40
 * (millesimes 2008-2013) ET la XU50 (millesimes 2014-2019), ce que Wikipedia
 * anglais et cars.com donnent tous les deux, consultes le 21/10/2026. La
 * rattacher a l'une des deux ferait disparaitre le filtre pour les
 * annees de l'autre — une compatibilite perdue en substance, le compte de
 * lignes restant le meme. La decouper en deux lignes serait CREER une
 * compatibilite, ce qui appartient au proprietaire. Et poser les generations
 * en laissant la ligne nue active ferait voir « Highlander » quatre fois a un
 * vendeur, ce que la desactivation des slugs nus existe justement pour eviter.
 *
 * Le Tacoma n'a qu'une seule compatibilite et elle tient entierement dans une
 * generation : il passe. Les quatre autres attendent une decision, pas une
 * source.
 *
 * ── POURQUOI UNE MIGRATION ──────────────────────────────────────────────────
 *
 * Render ne joue que `migrate --force` au deploiement : les seeders ne
 * tournent qu'en local et dans les tests. Sans cette migration, les
 * generations du Tacoma n'existeraient nulle part en production, la Yaris y
 * garderait son mauvais code, et la compatibilite du Tacoma serait ignoree en
 * silence — `FiltresHuileBoutiqueSeeder` se contente d'avertir qu'un slug est
 * introuvable.
 *
 * L'ORDRE DES QUATRE ETAPES EST CONTRAINT, et c'est tout le risque de ce
 * fichier :
 *
 *   1. poser les generations du Tacoma ;
 *   2. renommer la ligne de la Yaris AVANT que tout seeder ne la revoie, pour
 *      la raison mecanique que `2026_10_03_000003` a deja ecrite — l'upsert de
 *      `VehicleModelsSeeder` porte sur (brand_id, slug), donc un slug non
 *      renomme ferait INSERER une seconde Yaris au prochain demarrage ;
 *   3. relancer le catalogue de filtres, qui deplace la compatibilite du
 *      Tacoma et celle de la Yaris — il faut que les cibles existent deja ;
 *   4. desactiver la ligne nue du Tacoma, maintenant qu'elle ne porte plus
 *      rien.
 *
 * Elle ne leve jamais d'exception : le CMD du Dockerfile enchaine
 * `migrate --force && ... && php artisan serve`, et un referentiel incomplet
 * est un desagrement quand une API eteinte est une panne.
 *
 * Idempotente : les generations sont posees par existence de slug, le renommage
 * ne vise que la ligne encore fautive, la desactivation est un `update` a
 * valeur fixe, et le seeder des filtres remplace ses compatibilites au lieu
 * d'en empiler.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');

            // Pendant les tests, les migrations tournent sur une base vide :
            // la marque n'existe pas encore et c'est `VehicleModelsSeeder` qui
            // pose tout. Rien a faire ici, et rien d'anormal.
            if ($toyota === null) {
                echo '  Marque Toyota absente : generations et renommage seront poses par le seeder du referentiel.'.PHP_EOL;

                return;
            }

            $this->poserLesGenerationsDuTacoma($toyota);
            $this->renommerLaYaris($toyota);
            $this->relancerLeCatalogueDesFiltres();
            $this->desactiverLesSlugsNus($toyota);
        } catch (\Throwable $e) {
            echo '  ATTENTION : les generations du Tacoma n\'ont pas ete posees ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Les quatre generations, lues dans le seeder pour qu'il n'y ait qu'une
     * definition.
     *
     * Insertion seulement si le slug manque, jamais de mise a jour : une ligne
     * corrigee a la main depuis l'application ne doit pas etre ecrasee par un
     * redeploiement. C'est la regle de `2026_10_18_000001`, et elle vaut ici.
     */
    private function poserLesGenerationsDuTacoma(int $toyota): void
    {
        $crees = 0;

        foreach (VehicleModelsSeeder::GENERATIONS_TACOMA as [$nom, $gen, $carrosserie, $segment, $de, $a]) {
            $slug = Str::slug($nom.'-'.$gen);

            if (DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('vehicle_models')->insert([
                'brand_id'         => $toyota,
                'name'             => $nom,
                'slug'             => $slug,
                'generation'       => $gen,
                'body_type'        => $carrosserie,
                'segment'          => $segment,
                'production_start' => $de,
                'production_end'   => $a,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $crees++;
        }

        echo sprintf(
            '  Generations du Tacoma : %d creee(s), %d deja presente(s).%s',
            $crees,
            count(VehicleModelsSeeder::GENERATIONS_TACOMA) - $crees,
            PHP_EOL,
        );
    }

    /**
     * La Yaris 2011-2020 s'appelait XP150 ; c'est une XP130.
     *
     * Les trois conditions du `where` ne sont pas de la ceinture de securite :
     * elles identifient LA ligne fautive et aucune autre. `generation` encore
     * a « XP150 » exclut une correction deja faite, `production_start` a 2011
     * exclut la vraie XP150 si quelqu'un l'ajoute un jour, et la contrainte
     * d'unicite sur (brand_id, slug) ferait echouer l'`update` si
     * `yaris-xp130` existait deja — d'ou le controle prealable, qui prefere
     * ne rien faire et le dire.
     */
    private function renommerLaYaris(int $toyota): void
    {
        $cible = Str::slug('Yaris-XP130');

        if (DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', $cible)->exists()) {
            echo '  Yaris XP130 deja presente : la ligne XP150 n\'est pas renommee.'.PHP_EOL;

            return;
        }

        $renommees = DB::table('vehicle_models')
            ->where('brand_id', $toyota)
            ->where('name', 'Yaris')
            ->where('generation', 'XP150')
            ->where('production_start', 2011)
            ->update([
                'generation' => 'XP130',
                'slug'       => $cible,
                'updated_at' => now(),
            ]);

        echo sprintf('  Yaris 2011-2020 : %d ligne(s) renommee(s) XP150 -> XP130.%s', $renommees, PHP_EOL);
    }

    /**
     * Relance le catalogue de filtres, qui deplace les deux compatibilites.
     *
     * Et il n'y a pas d'autre chemin : ce seeder fait `fitments()->delete()`
     * avant de reecrire, donc une ligne posee ici a la main serait effacee a
     * sa prochaine execution sans que personne ne le voie. Il est seul auteur
     * des compatibilites de ces pieces — la raison est ecrite au long dans
     * `2026_10_19_000001`.
     */
    private function relancerLeCatalogueDesFiltres(): void
    {
        Artisan::call('db:seed', [
            '--class' => FiltresHuileBoutiqueSeeder::class,
            '--force' => true,
        ]);

        echo Artisan::output();
    }

    /**
     * La ligne nue du Tacoma sort des listes, et sa ligne reste.
     *
     * Elle perd aussi ses bornes de production, et ce n'est pas un detail :
     * « 1995-ouvert » chevaucherait ses quatre propres generations, et deux
     * commandes qui lisent ces bornes ne filtrent pas `is_active`.
     * `ImportConsommations` retient le premier modele qui couvre l'annee du
     * vehicule, et la ligne nue, plus ancienne, serait lue avant les
     * generations : elle capterait toutes les cotes du Tacoma. Des bornes
     * vides en font le repli de dernier recours au lieu du premier choix. Le
     * detail est sur la ligne du Tacoma dans
     * `VehicleModelsSeeder::MODELES_IMPORT_AMERIQUE_DU_NORD`.
     *
     * LE CONTROLE SUR LES VEHICULES N'EST PAS DE LA POLITESSE. Un vehicule
     * enregistre sur la ligne nue est un vehicule que son vendeur a choisi
     * ainsi ; le masquer rendrait sa generation introuvable dans la liste ou
     * il faudrait la rechoisir. La derniere mesure du proprietaire ne donnait
     * aucun vehicule sur ces lignes en production, et la base jetable de ce lot
     * n'en portait aucun non plus — mais une mesure d'hier n'est pas un etat
     * d'aujourd'hui, et c'est au deploiement que la question se pose.
     *
     * Les deux tables sont interrogees : `vehicles` est le parc en vente,
     * `owned_vehicles` le garage des clients. Les deux pointent sur
     * `vehicle_models`.
     */
    private function desactiverLesSlugsNus(int $toyota): void
    {
        foreach (VehicleModelsSeeder::SLUGS_NUS_DESACTIVES as $slug) {
            $id = DB::table('vehicle_models')
                ->where('brand_id', $toyota)
                ->where('slug', $slug)
                ->value('id');

            if ($id === null) {
                continue;
            }

            $attaches = DB::table('vehicles')->where('vehicle_model_id', $id)->count()
                + DB::table('owned_vehicles')->where('vehicle_model_id', $id)->count();

            if ($attaches > 0) {
                echo sprintf(
                    '  %s LAISSE ACTIF : %d vehicule(s) le referencent, le masquer les priverait de leur modele.%s',
                    $slug,
                    $attaches,
                    PHP_EOL,
                );

                continue;
            }

            DB::table('vehicle_models')
                ->where('id', $id)
                ->update([
                    'is_active'        => false,
                    'production_start' => null,
                    'production_end'   => null,
                    'updated_at'       => now(),
                ]);

            echo sprintf('  %s desactive et sans bornes, sa ligne est conservee.%s', $slug, PHP_EOL);
        }
    }

    /**
     * Rend la ligne nue active, la Yaris a son ancien code, et remet les deux
     * compatibilites ou elles pendaient.
     *
     * Les generations du Tacoma sont supprimees — et avec elles, en cascade,
     * la compatibilite rattachee a la troisieme : `part_fitments` est en
     * `cascadeOnDelete`. La relance du seeder en fin de parcours la repose sur
     * `tacoma`, puisque le fichier du seeder, lui, n'est pas defait par une
     * migration. Si le depot a deja ete revenu en arriere cote code, le seeder
     * la repose la ou son fichier la designe, ce qui est le comportement voulu.
     *
     * Une generation portant des vehicules n'est pas supprimee :
     * `vehicles.vehicle_model_id` et `owned_vehicles.vehicle_model_id` sont en
     * `restrictOnDelete` et la suppression echouerait purement et simplement.
     */
    public function down(): void
    {
        $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');

        if ($toyota === null) {
            return;
        }

        // La ligne nue redevient active d'abord : si la suite echoue, le
        // referentiel reste utilisable.
        //
        // Ses bornes sont REECRITES EN DUR, et c'est voulu : la constante du
        // seeder ne les porte plus — elle les a vidées en meme temps que ce
        // lot. « 1995-ouvert » est l'etat d'avant, celui que
        // `2026_10_18_000001` avait pose, et c'est a lui qu'un retour arriere
        // doit ramener.
        DB::table('vehicle_models')
            ->where('brand_id', $toyota)
            ->where('slug', 'tacoma')
            ->update([
                'is_active'        => true,
                'production_start' => 1995,
                'production_end'   => null,
                'updated_at'       => now(),
            ]);

        // Le renommage inverse, et seulement sur la ligne que ce fichier a
        // renommee : une XP130 posee autrement n'est pas la nôtre.
        if (! DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', 'yaris-xp150')->exists()) {
            DB::table('vehicle_models')
                ->where('brand_id', $toyota)
                ->where('name', 'Yaris')
                ->where('generation', 'XP130')
                ->where('production_start', 2011)
                ->update([
                    'generation' => 'XP150',
                    'slug'       => 'yaris-xp150',
                    'updated_at' => now(),
                ]);
        }

        foreach (VehicleModelsSeeder::GENERATIONS_TACOMA as [$nom, $gen]) {
            $id = DB::table('vehicle_models')
                ->where('brand_id', $toyota)
                ->where('slug', Str::slug($nom.'-'.$gen))
                ->value('id');

            if ($id === null) {
                continue;
            }

            $attaches = DB::table('vehicles')->where('vehicle_model_id', $id)->count()
                + DB::table('owned_vehicles')->where('vehicle_model_id', $id)->count();

            if ($attaches > 0) {
                echo sprintf('  %s %s conserve : %d vehicule(s) y sont rattache(s).%s', $nom, $gen, $attaches, PHP_EOL);

                continue;
            }

            DB::table('vehicle_models')->where('id', $id)->delete();
        }

        Artisan::call('db:seed', [
            '--class' => FiltresHuileBoutiqueSeeder::class,
            '--force' => true,
        ]);
    }
};
