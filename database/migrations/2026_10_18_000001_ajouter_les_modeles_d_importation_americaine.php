<?php

use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pose au referentiel les cinq Toyota d'importation americaine.
 *
 * Highlander, Sienna, Venza, Avalon et Tacoma. Elles manquaient, et leur
 * absence etait coherente : ce referentiel est choisi a la main pour le marche
 * local — il porte Fortuner, Vios, Rush, Avensis et Auris, qu'aucune source
 * nord-americaine ne pourrait fournir, et des finitions du Golfe. Le
 * proprietaire leve cette absence en connaissance de cause, parce que ces
 * vehicules arrivent en Afrique de l'Ouest par l'importation americaine.
 * `VehicleModelsSeeder::MODELES_IMPORT_AMERIQUE_DU_NORD` porte la raison en
 * detail, et la definition des cinq lignes — celle que cette migration lit,
 * pour qu'il n'y en ait qu'une.
 *
 * Pourquoi une migration ET le seeder : le seeder du referentiel n'est pas
 * joue au deploiement, Render ne lancant que `migrate --force`. Sans cette
 * migration, les cinq modeles n'existeraient qu'en local et dans les tests, et
 * les dix-neuf compatibilites qui les designent seraient ignorees en
 * production — en silence, puisque le seeder des filtres se contente de
 * signaler un slug introuvable.
 *
 * Elle s'execute avant `..._000002_verser_le_catalogue_de_correspondance...`,
 * qui rattache ces compatibilites : l'ordre des noms de fichier suffit, et il
 * est necessaire.
 *
 * `firstOrCreate` sur (marque, slug) : une republication ne cree rien de
 * nouveau, et ne touche pas un modele que quelqu'un aurait corrige a la main
 * depuis. C'est la difference avec l'upsert du seeder, qui lui reecrit les
 * colonnes — ici on pose, on ne corrige pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');

            // Pendant les tests, les migrations tournent sur une base vide :
            // la marque n'existe pas encore et c'est `VehicleModelsSeeder` qui
            // pose les modeles. Rien a faire ici, et rien d'anormal.
            if ($toyota === null) {
                echo '  Marque Toyota absente : les cinq modeles seront poses par le seeder du referentiel.'.PHP_EOL;

                return;
            }

            $crees = 0;

            foreach (VehicleModelsSeeder::MODELES_IMPORT_AMERIQUE_DU_NORD as [$nom, $gen, $carrosserie, $segment, $de, $a]) {
                $slug = Str::slug($nom.($gen ? '-'.$gen : ''));

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
                '  Modeles d\'importation americaine : %d cree(s), %d deja present(s).%s',
                $crees,
                count(VehicleModelsSeeder::MODELES_IMPORT_AMERIQUE_DU_NORD) - $crees,
                PHP_EOL,
            );
        } catch (\Throwable $e) {
            // Meme regle que les autres migrations de donnees de ce depot : le
            // CMD du Dockerfile enchaine `migrate --force && ... && serve`. Un
            // referentiel incomplet est un desagrement, une API eteinte est une
            // panne.
            echo '  ATTENTION : les modeles d\'importation americaine n\'ont pas ete poses ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Retire les cinq modeles, et avec eux les compatibilites qui les
     * designent — elles partent en cascade, `part_fitments.vehicle_model_id`
     * etant `cascadeOnDelete`.
     *
     * Ne supprime que des modeles sans vehicule rattache. Ce controle n'est pas
     * de la politesse : `vehicles.vehicle_model_id` et
     * `owned_vehicles.vehicle_model_id` sont en `restrictOnDelete`, et la
     * suppression echouerait purement et simplement. Un modele choisi par un
     * client dans son garage n'est plus un element de referentiel qu'on retire
     * sans perdre des donnees metier : on le laisse, et on le dit.
     */
    public function down(): void
    {
        $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');

        if ($toyota === null) {
            return;
        }

        foreach (VehicleModelsSeeder::MODELES_IMPORT_AMERIQUE_DU_NORD as [$nom, $gen]) {
            $slug = Str::slug($nom.($gen ? '-'.$gen : ''));

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
                echo sprintf('  %s conserve : %d vehicule(s) y sont rattache(s).%s', $slug, $attaches, PHP_EOL);

                continue;
            }

            DB::table('vehicle_models')->where('id', $id)->delete();
        }
    }
};
