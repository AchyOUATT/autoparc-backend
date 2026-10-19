<?php

use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Verse en base le catalogue de compatibilite range par familles.
 *
 * Le document du 18/10/2026 classe tout le programme Toyota / Lexus en neuf
 * familles de filtres, chacune avec ses numeros de vente par marche. Rapporte
 * a ce que la boutique portait deja, il apporte SIX references de vente
 * absentes — 90915-YZZF1, 90915-30002, 04152-YZZA3, 04152-YZZA5,
 * 04152-YZZA4, 04152-51010 — et vingt compatibilites au statut « Confirme »,
 * dont le Land Cruiser 200 V8, premier vehicule du parc local que la famille
 * des cartouches V8 atteigne.
 *
 * Tout cela est ecrit dans `FiltresHuileBoutiqueSeeder`, et nulle part
 * ailleurs, pour la raison mecanique deja posee par `2026_10_18_000002` : ce
 * seeder fait `fitments()->delete()` avant de reecrire. Une ligne posee par un
 * autre chemin serait effacee a sa prochaine execution sans que personne ne le
 * voie. Il n'y a qu'un seul auteur des compatibilites de ces pieces.
 *
 * D'ou cette migration de plus : une migration deja jouee ne se rejoue pas, et
 * la production ne reverrait donc jamais le seeder. `migrate --force` au
 * deploiement est le seul point d'entree qui s'execute cote serveur — Render
 * ne joue pas les seeders et le plan gratuit n'offre aucun acces shell.
 *
 * Elle ne leve jamais d'exception. Le CMD du Dockerfile enchaine
 * `migrate --force && ... && php artisan serve` : une migration qui echoue
 * empeche le serveur de demarrer. Un catalogue incomplet est un desagrement,
 * une API eteinte est une panne.
 *
 * Idempotente : le seeder retrouve ses pieces par SKU, remplace leurs
 * compatibilites au lieu d'en empiler, et laisse le prix et le stock aux
 * valeurs de la boutique. Un second passage ne change rien — c'est mesure par
 * `test_rejouer_le_seeder_ne_change_rien`, qui compare les empreintes ligne a
 * ligne et pas seulement les comptes.
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
            echo '  ATTENTION : le catalogue des neuf familles n\'a pas ete verse ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Rien a defaire.
     *
     * Cette migration n'a rien cree en propre : elle a relance un seeder dont
     * les pieces appartiennent a `2026_10_02_000005`, et c'est le `down()` de
     * celle-la qui les emporte. Supprimer ici les six references nouvelles
     * ferait perdre au passage le prix et le stock que le commercant leur
     * aurait donnes depuis l'application, pour un retour en arriere qui ne les
     * visait pas.
     */
    public function down(): void
    {
        //
    }
};
