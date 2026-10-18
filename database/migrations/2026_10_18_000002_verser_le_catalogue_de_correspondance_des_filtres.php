<?php

use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Verse en base le catalogue de correspondance des filtres a huile.
 *
 * Le document fournit 39 compatibilites nouvelles pour les trois references
 * deja publiees — YZZA1, YZZA6, YZZN1. Elles sont ecrites dans
 * `FiltresHuileBoutiqueSeeder`, et pas ailleurs, pour une raison mecanique :
 * ce seeder fait `fitments()->delete()` avant de reecrire. Toute ligne posee
 * par un autre chemin — migration qui insererait directement, second seeder —
 * serait effacee a sa prochaine execution, sans que personne ne le voie. Il
 * n'y a donc qu'un seul auteur des compatibilites de ces pieces, et il faut
 * que ca reste vrai.
 *
 * Reste a le faire tourner en ligne. `2026_10_02_000005` l'avait deja appele,
 * mais une migration ne se rejoue pas : la production ne reverrait jamais le
 * seeder. D'ou cette seconde migration, qui rappelle le meme seeder — c'est
 * le seul point d'entree qui s'execute cote serveur, Render jouant
 * `migrate --force` au deploiement et jamais les seeders, et le plan gratuit
 * n'offrant aucun acces shell. « A lancer a la main » n'existe pas ici.
 *
 * Elle ne leve jamais d'exception. Le CMD du Dockerfile enchaine
 * `migrate --force && ... && php artisan serve` : une migration qui echoue
 * empeche le serveur de demarrer. Un catalogue incomplet est un desagrement,
 * une API eteinte est une panne.
 *
 * Le seeder est idempotent — il retrouve ses pieces par SKU et remplace leurs
 * compatibilites au lieu d'en empiler. La rejouer ne duplique rien, et le
 * prochain catalogue que fournira le proprietaire suivra le meme chemin :
 * completer le bloc de donnees du seeder, puis une migration d'une ligne.
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
            echo '  ATTENTION : le catalogue de correspondance n\'a pas ete verse ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Rien a defaire.
     *
     * Cette migration n'a rien creee en propre : elle a relance un seeder dont
     * les lignes appartiennent aux pieces de `2026_10_02_000005`, et c'est le
     * `down()` de celle-la qui les emporte. Revenir en arriere ici en
     * supprimant des compatibilites detruirait aussi les seize lignes du
     * premier versement, qui ne viennent pas de cette migration.
     */
    public function down(): void
    {
        //
    }
};
