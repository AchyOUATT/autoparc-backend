<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Vide le catalogue de demonstration, une fois, au prochain deploiement.
 *
 * Le plan gratuit de Render n'offre pas de Shell : `catalog:purge` ne peut pas
 * etre lancee a la main en ligne. Le seul point d'entree qui s'execute deja
 * cote serveur est `php artisan migrate --force`, dans le CMD du Dockerfile.
 * D'ou cette migration de donnees — elle est enregistree dans la table des
 * migrations, donc elle ne passe qu'une fois, et sur une base neuve elle ne
 * trouve rien a supprimer.
 *
 * Elle ne leve jamais d'exception, et ce n'est pas une negligence. Le CMD
 * enchaine `migrate --force && ... && php artisan serve` : une migration qui
 * echoue empeche le serveur de demarrer, et un catalogue non vide est un
 * desagrement quand une API eteinte est une panne. Si la commande refuse — un
 * historique de commandes, par exemple — son refus est ecrit dans le journal du
 * deploiement et le demarrage continue.
 *
 * Ce qu'elle supprime et ce qu'elle garde est decrit dans PurgeCatalogue. Deux
 * raisons de l'appeler plutot que de recopier ses requetes ici : l'ordre des
 * suppressions est dicte par des cles etrangeres en RESTRICT et se trompe
 * facilement, et il est eprouve par PurgeCatalogueTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        $code = Artisan::call('catalog:purge', ['--force' => true]);

        echo Artisan::output();

        if ($code !== 0) {
            echo '  ATTENTION : le catalogue n\'a pas ete vide. Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Rien a defaire : des lignes supprimees ne se recreent pas.
     *
     * Un `down()` vide est ici la seule reponse honnete. Rejouer les seeders de
     * demonstration rendrait un autre catalogue, avec d'autres identifiants, ce
     * qui ressemblerait a une restauration sans en etre une.
     */
    public function down(): void
    {
        //
    }
};
