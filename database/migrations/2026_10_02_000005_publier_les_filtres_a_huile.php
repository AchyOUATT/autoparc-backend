<?php

use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Publie le catalogue de filtres a huile de la boutique.
 *
 * Meme mecanique que le vidage du catalogue de demonstration, et pour la meme
 * raison : le plan gratuit de Render n'offre pas de Shell, et `php artisan
 * migrate --force` est le seul point d'entree qui s'execute cote serveur.
 *
 * Elle ne leve jamais d'exception. Le CMD du Dockerfile enchaine
 * `migrate --force && ... && php artisan serve` : une migration qui echoue
 * empeche le serveur de demarrer. Un catalogue incomplet est un desagrement,
 * une API eteinte est une panne.
 *
 * Le seeder est rejouable, donc rien ne se duplique si cette migration est
 * rejouee sur une base deja servie.
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

    /**
     * Retire ce que cette migration a publie, et rien d'autre.
     *
     * Les huit SKU sont ceux du seeder. Les compatibilites et les numeros
     * rattaches partent en cascade avec les pieces.
     */
    public function down(): void
    {
        \App\Models\Part::whereIn('sku', [
            '90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZD2',
            '90915-YZZN1', '90915-YZZN2', '04152-YZZA1', '04152-YZZA6',
        ])->forceDelete();
    }
};
