<?php

use App\Support\Toyota10001RetraitMarcheMois;
use Illuminate\Database\Migrations\Migration;

/**
 * Retire le marché JP et les mois de fabrication posés par
 * 2026_11_26_210000 sur les cinq lignes CUSCO du filtre 90915-10001 : ils
 * agissent comme critères éliminatoires, et rien ne prouve que le catalogue
 * CUSCO (japonais, pièces adaptables) soit restrictif ou exhaustif.
 *
 * Additive en esprit et idempotente : un champ n'est vidé que s'il vaut encore
 * exactement la valeur posée par 210000, sur une ligne encore au niveau
 * `aftermarket_crossref`. Le niveau, l'URL CUSCO et les notes sont conservés.
 * Cible par SKU, slug, moteur et années, jamais par id.
 *
 * `down()` remet ces valeurs sur les champs encore vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota10001RetraitMarcheMois::run();
    }

    public function down(): void
    {
        Toyota10001RetraitMarcheMois::rollback();
    }
};
