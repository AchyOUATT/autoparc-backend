<?php

use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Publie le 90915-YZZD4 et le 90915-10001.
 *
 * Pourquoi une SECONDE migration alors que la precedente appelle deja le meme
 * seeder : une migration ne s'execute qu'une fois. Enrichir le seeder apres
 * coup ne republie rien — le deploiement suivant ne trouve aucune migration en
 * attente et passe son chemin. C'est exactement ce qui vient d'arriver : deux
 * references ajoutees au seeder, un deploiement, et rien en base.
 *
 * Chaque enrichissement du catalogue demande donc sa propre migration. C'est
 * verbeux, mais c'est aussi un journal : on sait ce qui a ete publie, et quand.
 *
 * ATTENTION, et c'est le prix de cette mecanique. Le seeder converge les DIX
 * references vers ce que declare son tableau : il reecrit leur libelle, leur
 * prix, leur categorie, et REMPLACE leurs compatibilites. Une modification
 * faite entre-temps depuis l'application — un prix ajuste, une compatibilite
 * ajoutee a la main — sera donc ecrasee.
 *
 * Tant que le catalogue se pilote depuis ce fichier, c'est sans consequence.
 * Le jour ou le commercant modifiera ses fiches depuis l'application, il
 * faudra cesser d'appeler le seeder au deploiement et ne plus publier que par
 * l'ecran de modification.
 *
 * Comme la precedente, elle ne leve jamais d'exception : le CMD du Dockerfile
 * enchaine `migrate && serve`, et une API eteinte coute plus cher qu'un
 * catalogue incomplet.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('db:seed', [
                '--class' => FiltresHuileBoutiqueSeeder::class,
                '--force' => true,
            ]);

            echo Artisan::output();
        } catch (\Throwable $e) {
            echo '  ATTENTION : les filtres n\'ont pas ete publies ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /** Ne retire que les deux references que cette migration ajoute. */
    public function down(): void
    {
        \App\Models\Part::whereIn('sku', ['90915-YZZD4', '90915-10001'])->forceDelete();
    }
};
