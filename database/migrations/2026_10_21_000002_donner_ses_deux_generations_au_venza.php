<?php

use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pose les deux generations du Venza, et vide sa ligne nue.
 *
 * Suite directe de `2026_10_21_000001`, qui a fait le Tacoma et la Yaris. Meme
 * motif, meme ordre, memes gardes — la seule chose a lire ici est POURQUOI LE
 * VENZA ET LUI SEUL.
 *
 * ── CE QUE CE LOT NE FAIT PAS, ET C'EST ENCORE L'ESSENTIEL ──────────────────
 *
 * Cinq modeles d'importation americaine attendaient leurs generations apres le
 * Tacoma : Highlander, Sienna, Avalon, Venza et Vios. UN SEUL EST POSE.
 *
 * Les codes et les annees, eux, se recoupent sans difficulte pour les quatre
 * autres — le detail source par source est dans
 * `VehicleModelsSeeder::GENERATIONS_VENZA` et dans le rapport de lot. Le mur
 * n'est pas documentaire, il est dans ce qui pend aux slugs :
 *
 *     Dix-huit compatibilites de filtres, recoupees une a une contre un
 *     catalogue constructeur, pendaient a ces cinq slugs nus. SEPT D'ENTRE
 *     ELLES PORTENT DES BORNES A CHEVAL SUR DEUX GENERATIONS : quatre du
 *     Highlander, une du Sienna, deux de l'Avalon. Le Venza n'en avait
 *     aucune, le Vios n'a aucune compatibilite du tout.
 *
 * Exemple mesure : « Highlander 3.5 2GR-FE 2008-2016 » couvre la XU40
 * (production mai 2007 - 2013) ET la XU50 (novembre 2013 - novembre 2019), ce
 * que Wikipedia anglais et le catalogue de drom.ru donnent tous les deux,
 * consultes le 21/10/2026. La rattacher a l'une des deux ferait disparaitre le
 * filtre pour les annees de l'autre, sans que le compte de lignes bouge ; la
 * decouper en deux serait CREER une compatibilite. Les deux appartiennent au
 * proprietaire.
 *
 * ET LES HUIT LIGNES QUI NE SONT PAS A CHEVAL NE BOUGENT PAS DAVANTAGE. La
 * raison est ecrite au long dans `FiltresHuileBoutiqueSeeder`, au bloc YZZA1 :
 * un modele a demi deplace est pire qu'un modele pas deplace, parce qu'un
 * vendeur qui choisit la generation ne voit plus la piece restee sur la ligne
 * nue. Ces trois modeles sont indivisibles.
 *
 * Le Vios est ecarte pour une raison entierement differente, et il faut la
 * lire la ou elle se verifie : dans le rapport de lot et dans
 * `GenerationsVenzaTest::test_le_vios_reste_un_slug_nu_et_la_yaris_garde_son_xp130`.
 * En un mot : ses deux generations sont CONCURRENTES et non successives — la
 * XP150 de 2013 est toujours produite quand l'AC100 arrive en 2022 — et le
 * referentiel ne sait representer qu'une succession.
 *
 * ── POURQUOI UNE MIGRATION ──────────────────────────────────────────────────
 *
 * Render ne joue que `migrate --force` au deploiement : les seeders ne tournent
 * qu'en local et dans les tests. Sans cette migration, les generations du Venza
 * n'existeraient nulle part en production, et ses trois compatibilites seraient
 * ignorees en silence — `FiltresHuileBoutiqueSeeder` se contente d'AVERTIR
 * qu'un slug est introuvable.
 *
 * L'ORDRE DES TROIS ETAPES EST CONTRAINT :
 *
 *   1. poser les deux generations ;
 *   2. relancer le catalogue de filtres, qui deplace les trois
 *      compatibilites — il faut que les cibles existent deja ;
 *   3. vider la ligne nue, maintenant qu'elle ne porte plus rien.
 *
 * Elle ne leve jamais d'exception : le CMD du Dockerfile enchaine
 * `migrate --force && ... && php artisan serve`, et un referentiel incomplet
 * est un desagrement quand une API eteinte est une panne.
 *
 * Idempotente : les generations sont posees par existence de slug, la
 * desactivation est un `update` a valeur fixe, et le seeder des filtres
 * remplace ses compatibilites au lieu d'en empiler.
 */
return new class extends Migration
{
    /**
     * La ligne nue que CE fichier vide, et elle seule.
     *
     * `VehicleModelsSeeder::SLUGS_NUS_DESACTIVES` en porte deux desormais.
     * Boucler dessus serait pourtant une faute : si `2026_10_21_000001` a
     * LAISSE `tacoma` actif parce qu'un vehicule le referencait, ce
     * fichier-ci le desactiverait au prochain deploiement, le vehicule ayant
     * pu disparaitre entre-temps. Une migration decrit son propre changement,
     * pas celui de sa voisine.
     */
    private const SLUG_NU = 'venza';

    public function up(): void
    {
        try {
            $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');

            // Pendant les tests, les migrations tournent sur une base vide :
            // la marque n'existe pas encore et c'est `VehicleModelsSeeder` qui
            // pose tout. Rien a faire ici, et rien d'anormal.
            if ($toyota === null) {
                echo '  Marque Toyota absente : les generations du Venza seront posees par le seeder du referentiel.'.PHP_EOL;

                return;
            }

            $this->poserLesGenerationsDuVenza($toyota);
            $this->relancerLeCatalogueDesFiltres();
            $this->viderLaLigneNue($toyota);
        } catch (\Throwable $e) {
            echo '  ATTENTION : les generations du Venza n\'ont pas ete posees ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Les deux generations, lues dans le seeder pour qu'il n'y ait qu'une
     * definition.
     *
     * Insertion seulement si le slug manque, jamais de mise a jour : une ligne
     * corrigee a la main depuis l'application ne doit pas etre ecrasee par un
     * redeploiement. C'est la regle de `2026_10_18_000001` et de
     * `2026_10_21_000001`, et elle vaut ici.
     */
    private function poserLesGenerationsDuVenza(int $toyota): void
    {
        $crees = 0;

        foreach (VehicleModelsSeeder::GENERATIONS_VENZA as [$nom, $gen, $carrosserie, $segment, $de, $a]) {
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
            '  Generations du Venza : %d creee(s), %d deja presente(s).%s',
            $crees,
            count(VehicleModelsSeeder::GENERATIONS_VENZA) - $crees,
            PHP_EOL,
        );
    }

    /**
     * Relance le catalogue de filtres, qui deplace les trois compatibilites.
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
     * La ligne nue du Venza sort des listes et perd ses bornes ; sa ligne
     * reste.
     *
     * Les bornes vides ne sont pas un detail. « 2009-ouvert » chevaucherait
     * les deux generations du Venza, et deux commandes qui lisent ces bornes
     * ne filtrent pas `is_active` :
     *
     *   - `ImportConsommations` retient le PREMIER modele du meme nom dont les
     *     bornes couvrent l'annee du vehicule. La ligne nue, plus ancienne
     *     donc lue avant, capterait toutes les cotes du Venza au lieu de la
     *     bonne generation. Vides, elle devient le repli `$sansBornes`,
     *     c'est-a-dire le dernier recours au lieu du premier choix.
     *   - `BackfillFitments` inventerait des plages d'annees pour des
     *     compatibilites `generated` sur une ligne invisible ;
     *     `FitmentPlanner::plageAnnees` rend `[null, null]` sur une ligne sans
     *     borne basse et n'en invente donc plus.
     *
     * `CatalogueModelesTest::test_les_generations_d_un_meme_modele_ne_se_chevauchent_pas`
     * verrouille cet invariant : il n'examine que les lignes qui ont une borne
     * basse, donc une ligne nue bornee y serait comparee a ses propres
     * generations et le ferait echouer.
     *
     * LE CONTROLE SUR LES VEHICULES N'EST PAS DE LA POLITESSE. Un vehicule
     * enregistre sur la ligne nue est un vehicule que son vendeur a choisi
     * ainsi ; le masquer rendrait sa generation introuvable dans la liste ou
     * il faudrait la rechoisir. La base jetable de ce lot n'en portait aucun —
     * mais une mesure d'hier n'est pas un etat d'aujourd'hui, et c'est au
     * deploiement que la question se pose.
     *
     * Les deux tables sont interrogees : `vehicles` est le parc en vente,
     * `owned_vehicles` le garage des clients. Les deux pointent sur
     * `vehicle_models`.
     */
    private function viderLaLigneNue(int $toyota): void
    {
        $id = DB::table('vehicle_models')
            ->where('brand_id', $toyota)
            ->where('slug', self::SLUG_NU)
            ->value('id');

        if ($id === null) {
            echo sprintf('  %s introuvable : rien a desactiver.%s', self::SLUG_NU, PHP_EOL);

            return;
        }

        $attaches = DB::table('vehicles')->where('vehicle_model_id', $id)->count()
            + DB::table('owned_vehicles')->where('vehicle_model_id', $id)->count();

        if ($attaches > 0) {
            echo sprintf(
                '  %s LAISSE ACTIF : %d vehicule(s) le referencent, le masquer les priverait de leur modele.%s',
                self::SLUG_NU,
                $attaches,
                PHP_EOL,
            );

            return;
        }

        DB::table('vehicle_models')
            ->where('id', $id)
            ->update([
                'is_active'        => false,
                'production_start' => null,
                'production_end'   => null,
                'updated_at'       => now(),
            ]);

        echo sprintf('  %s desactive et sans bornes, sa ligne est conservee.%s', self::SLUG_NU, PHP_EOL);
    }

    /**
     * Rend la ligne nue active et bornee, et remet les trois compatibilites ou
     * elles pendaient.
     *
     * Les generations du Venza sont supprimees — et avec elles, en cascade,
     * les compatibilites qui y sont rattachees : `part_fitments` est en
     * `cascadeOnDelete`. La relance du seeder en fin de parcours les repose la
     * ou son FICHIER les designe, puisqu'un fichier de seeder n'est pas defait
     * par une migration.
     *
     * ── CE QUE CELA COUTE, MESURE ET NON SUPPOSE ────────────────────────────
     *
     * UN `migrate:rollback` SEUL PERD LES TROIS COMPATIBILITES DU VENZA. Mesure
     * le 21/10/2026 sur base jetable SQLite : 94 lignes dans `part_fitments`
     * avant, 91 apres le retour arriere, et `venza` redevenu actif en
     * 2009-ouvert mais portant ZERO ligne.
     *
     * La raison est mecanique : le fichier du seeder designe encore
     * `venza-av10` et `venza-xu80`, que ce `down()` vient de supprimer, et
     * `FiltresHuileBoutiqueSeeder` SE CONTENTE D'AVERTIR quand un slug est
     * introuvable. Les trois lignes ne reviennent sur `venza` que si le code
     * du seeder est revenu en arriere avec la base.
     *
     * CE N'EST PAS RATTRAPABLE ICI, et il ne faut pas essayer : reecrire les
     * trois lignes a la main sur `venza` serait les poser dans une table dont
     * ce seeder est seul auteur — il fait `fitments()->delete()` avant de
     * reecrire, donc elles disparaitraient a sa prochaine execution sans que
     * personne ne le voie. La reponse est de revenir en arriere cote code, ou
     * de rejouer `migrate` : les trois lignes reviennent alors d'elles-memes,
     * et le total est remonte a 94 dans la meme mesure.
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
        // seeder ne les porte plus — ce lot les a vidées en meme temps qu'il
        // posait les generations. « 2009-ouvert » est l'etat d'avant, celui
        // que `2026_10_18_000001` avait pose, et c'est a lui qu'un retour
        // arriere doit ramener.
        DB::table('vehicle_models')
            ->where('brand_id', $toyota)
            ->where('slug', self::SLUG_NU)
            ->update([
                'is_active'        => true,
                'production_start' => 2009,
                'production_end'   => null,
                'updated_at'       => now(),
            ]);

        foreach (VehicleModelsSeeder::GENERATIONS_VENZA as [$nom, $gen]) {
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
