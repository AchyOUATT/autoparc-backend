<?php

use Database\Seeders\FiltresIndustrielsBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Verse en base les onze filtres poids lourds et engins.
 *
 * Perkins, John Deere, Caterpillar, Fleetguard, JCB — toutes en stock chez le
 * commercant, toutes d'origine, toutes photographiees. Premieres references
 * HORS automobile du catalogue.
 *
 * ── POURQUOI UNE MIGRATION POUR LANCER UN SEEDER ───────────────────────────
 *
 * Meme raison mecanique que `2026_10_19_000001` : `migrate --force` au
 * demarrage est LE SEUL point d'entree qui s'execute cote serveur. Render ne
 * joue pas les seeders, et le plan gratuit n'offre aucun acces shell. Un
 * seeder que rien n'appelle est un fichier mort — c'est exactement le reproche
 * fait a `catalog:import-trims` sur la branche externe, et il vaut ici aussi.
 *
 * ── ELLE NE LEVE JAMAIS D'EXCEPTION ────────────────────────────────────────
 *
 * Le CMD du Dockerfile enchaine `migrate --force && ... && php artisan serve` :
 * une migration qui echoue empeche le serveur de demarrer. Onze filtres
 * absents sont un desagrement, une API eteinte est une panne.
 *
 * Le cas le plus probable d'echec est d'ailleurs connu : le seeder cherche les
 * categories « Filtre a huile » et « Filtre a carburant » posees par
 * `PartCategoriesSeeder`. Si elles manquent, il le dit article par article et
 * n'en pose aucun — sans rien casser.
 *
 * ── CE QUE CE LOT MET EN LIGNE, ET CE QU'IL N'Y MET PAS ────────────────────
 *
 * Rien de visible. Les onze articles entrent avec `is_active = false` et un
 * prix a zero : ils n'apparaissent dans aucun catalogue public tant que le
 * commercant n'a pas pose un prix et une quantite depuis l'application.
 *
 * C'est un choix explicite. Les filtres industriels valent plusieurs fois les
 * 3 000 F du catalogue Toyota ; reprendre ce chiffre « quitte a reajuster »
 * aurait mis en ligne onze prix faux, visibles par un client, pendant le temps
 * qu'il faut pour s'en apercevoir.
 *
 * Aucune compatibilite non plus : ni Dongfeng, ni Caterpillar, ni JCB, ni
 * John Deere, ni McCormick n'existent au referentiel vehicules. Il n'y a rien
 * a quoi les rattacher, et la lecon du catalogue Toyota est qu'une
 * compatibilite posee sans source verifiee coute plus cher qu'une
 * compatibilite absente.
 *
 * Idempotente : le seeder retrouve ses pieces par SKU, ne touche au prix, au
 * stock et a la publication qu'a la creation, et laisse a la corbeille ce que
 * le commercant y a mis. Un second passage ne cree rien — mesure.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            // ── CETTE MIGRATION NE CREE PAS SES CATEGORIES, ET C'EST VOULU
            //
            // Le seeder cherche « Filtre a huile » et « Filtre a carburant »
            // sans jamais en creer : sans elles il saute les onze references
            // en le disant, et ne casse rien.
            //
            // J'AI D'ABORD FAIT L'INVERSE, ET C'ETAIT UNE FAUTE. Lancer
            // `PartCategoriesSeeder` ici rendait le lot autosuffisant sur une
            // base neuve — mais `migrate` tourne AUSSI dans chaque test, et
            // les 89 categories se retrouvaient posees avant que les tests ne
            // creent les leurs. Mesure : 49 erreurs et 1 echec sur la suite,
            // toutes sur la contrainte d'unicite de `part_categories.slug`.
            //
            // Les migrations du catalogue Toyota ont la meme dependance et
            // n'ont jamais eu ce probleme precisement parce qu'elles sont
            // INERTES en test : sans categorie, leur seeder abandonne.
            //
            // La production, elle, les a — verifie le 24/10/2026 sur
            // `/api/catalog/part-categories` : 89 categories en ligne, dont
            // les deux qui nous interessent, et 17 pieces publiques rangees
            // dans « Filtre a huile ». Le prerequis est donc satisfait la ou
            // il compte, et l'inertie en test est une propriete, pas un
            // manque.
            Artisan::call('db:seed', [
                '--class' => FiltresIndustrielsBoutiqueSeeder::class,
                '--force' => true,
            ]);

            echo Artisan::output();
        } catch (\Throwable $e) {
            echo '  ATTENTION : les filtres industriels n\'ont pas ete verses ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Les onze references partent, mais pas celles que le commercant a
     * touchees.
     *
     * Un `down()` qui supprimerait tout emporterait le prix et la quantite
     * poses depuis l'application — c'est-a-dire le seul travail humain de ce
     * lot. On ne retire donc que ce qui est reste EXACTEMENT dans l'etat ou
     * cette migration l'a cree : non publie, prix et stock a zero.
     */
    public function down(): void
    {
        \App\Models\Part::query()
            ->whereIn('sku', [
                '26560145', '26560143', '4415122',
                'RE504836', 'RE59754',
                '462-1171', '435-6493',
                'LF4054', 'FF5018', 'FS1280',
                '320/07155',
            ])
            ->where('is_active', false)
            ->where('selling_price', 0)
            ->where('stock_quantity', 0)
            ->delete();
    }
};
