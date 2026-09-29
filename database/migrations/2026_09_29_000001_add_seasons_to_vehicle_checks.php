<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le controle saisonnier, et la lecon du premier type de controle.
 *
 * `reason` etait un enum a deux valeurs. Le deuxieme type de controle montre
 * que c'etait le mauvais choix : un enum se modifie par une migration a chaque
 * valeur ajoutee, MySQL et PostgreSQL ne le font pas de la meme maniere, et la
 * liste est manifestement destinee a s'allonger — saisonnier aujourd'hui,
 * periodique et visite technique demain, etat des lieux de location ensuite.
 * Une chaine courte validee cote applicatif coute une verification de plus et
 * fait disparaitre la migration a chaque fois.
 *
 * `seasons` dit a quel controle saisonnier un point appartient. C'est un axe
 * distinct de `months`, qui dit quand un point merite d'etre pose dans un
 * controle avant voyage : le filtre a air se verifie en passant avant un long
 * trajet de saison seche (months), et il est l'un des points centraux du
 * controle de saison seche (seasons). Confondre les deux ferait disparaitre le
 * point de l'un ou de l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_checks', function (Blueprint $table) {
            $table->string('reason', 20)->default('trip')->change();
        });

        Schema::table('vehicle_check_items', function (Blueprint $table) {
            // ['pluies'] ou ['seche'] — App\Support\Saison. Nul pour un point
            // qui n'appartient a aucun controle saisonnier.
            $table->json('seasons')->nullable()->after('months');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_check_items', function (Blueprint $table) {
            $table->dropColumn('seasons');
        });

        Schema::table('vehicle_checks', function (Blueprint $table) {
            $table->enum('reason', ['trip', 'periodic'])->default('trip')->change();
        });
    }
};
