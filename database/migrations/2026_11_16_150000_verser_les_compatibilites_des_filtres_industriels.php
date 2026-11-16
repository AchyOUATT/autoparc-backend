<?php

use Database\Seeders\EnginsIndustrielsSeeder;
use Database\Seeders\FiltresIndustrielsBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Met a jour les onze filtres industriels et leurs compatibilites sourcees.
 *
 * Les articles sont deja en base, non publies, depuis `2026_10_24_000002`.
 * Cette migration relance les deux seeders : elle corrige les descriptifs
 * (type officiel, equivalents, reserves) et ajoute les machines que les
 * guides John Deere et les fiches Caterpillar nomment explicitement.
 *
 * Meme raison mecanique que les versements precedents : Render joue
 * `migrate --force` au demarrage, jamais les seeders. Elle ne leve jamais
 * d'exception, pour ne pas eteindre l'API.
 *
 * Idempotente : pieces retrouvees par SKU, machines par slug, compatibilites
 * remplacees et non empilees. Prix, stock et publication ne sont pas
 * reecrits une fois l'article cree.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('db:seed', [
                '--class' => FiltresIndustrielsBoutiqueSeeder::class,
                '--force' => true,
            ]);
            echo Artisan::output();

            Artisan::call('db:seed', [
                '--class' => EnginsIndustrielsSeeder::class,
                '--force' => true,
            ]);
            echo Artisan::output();
        } catch (\Throwable $e) {
            echo '  ATTENTION : les compatibilites des filtres industriels n\'ont pas ete versees ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * On ne retire que les machines AJOUTEES par ce passage, et seulement
     * si elles sont restees inutilisees.
     */
    public function down(): void
    {
        $slugs = [
            'jd-5075m', 'jd-5410', 'jd-5510',
            'cat-140-gc', 'cat-de200-gc', 'cat-de220-gc', 'cat-xqp150',
        ];

        \App\Models\VehicleModel::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->whereDoesntHave('vehicles')
            ->each(function ($modele) {
                $modele->partFitments()->delete();
                $modele->delete();
            });
    }
};
