<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotes de consommation officielles, par motorisation.
 *
 * La consommation n'appartient pas a une annonce : deux Camry 2013 de la meme
 * concession consomment 8,2 ou 9,4 l/100 selon qu'elles ont le 2,5 l quatre
 * cylindres ou le 3,5 l V6. Elle appartient a la motorisation — moteur, boite,
 * transmission. Saisie par annonce, elle serait retapee a chaque fiche et
 * divergerait, comme les generations l'avaient fait.
 *
 * La finition ne figure volontairement pas dans la cle : « LE » ou « XLE » ne
 * changent la consommation que sur les hybrides, ou le poids des equipements
 * pese. Les sources officielles l'inscrivent alors dans le nom du modele, et
 * c'est la qu'on la retrouve.
 *
 * Chaque ligne porte sa source et son cycle d'essai. Sans cela on afficherait
 * un jour une cote canadienne a cote d'une cote europeenne comme si elles
 * etaient comparables : une meme voiture s'y lit 8,2 ou 6,4 selon le
 * protocole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorisations', function (Blueprint $table) {
            $table->id();

            // ── Provenance ────────────────────────────────────────────
            $table->string('source', 20);            // nrcan, ademe, gvg
            $table->string('cycle', 20);             // 5-cycle, 2-cycle, wltp, nedc

            // ── Identification, telle que la source l'ecrit ───────────
            $table->unsignedSmallInteger('model_year');
            $table->string('make_raw');              // Toyota
            $table->string('model_raw');             // Camry, ou "Camry Hybrid LE"
            $table->string('vehicle_class')->nullable();

            // ── Motorisation ─────────────────────────────────────────
            $table->decimal('engine_l', 3, 1)->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->string('transmission_code', 10)->nullable();  // AS6, M6, AV
            $table->string('fuel_code', 4)->nullable();           // X, Z, D, E, N

            // ── Cotes, en l/100 km ───────────────────────────────────
            $table->decimal('consumption_city', 4, 1)->nullable();
            $table->decimal('consumption_highway', 4, 1)->nullable();
            $table->decimal('consumption_combined', 4, 1)->nullable();
            $table->unsignedSmallInteger('co2_g_km')->nullable();

            // ── Rattachement au catalogue, quand il est possible ─────
            //
            // Nullable a dessein : la source connait des milliers de modeles
            // que le catalogue n'aura jamais, et l'import ne doit pas echouer
            // parce qu'un nom ne correspond a rien chez nous.
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Relancer l'import ne doit rien dupliquer : cette cle identifie
            // une ligne de la source.
            $table->unique([
                'source', 'model_year', 'make_raw', 'model_raw',
                'engine_l', 'cylinders', 'transmission_code', 'fuel_code',
            ], 'motorisations_source_unique');

            $table->index(['make_raw', 'model_raw', 'model_year']);
            $table->index('vehicle_model_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motorisations');
    }
};
