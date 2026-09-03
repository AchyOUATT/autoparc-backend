<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les valeurs par défaut techniques aux modèles de véhicules.
 * Permet au formulaire d'enregistrement de pré-remplir automatiquement
 * le type, les places, les portes et la transmission quand le modèle est choisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            // Type de véhicule déduit du modèle : passenger | utility | heavy
            $table->string('default_vehicle_type', 20)->nullable()->after('body_type');

            // Nombre de places et de portes standards
            $table->unsignedTinyInteger('default_seats')->nullable()->after('default_vehicle_type');
            $table->unsignedTinyInteger('default_doors')->nullable()->after('default_seats');

            // Transmission par défaut : manual | automatic | cvt
            $table->string('default_transmission', 20)->nullable()->after('default_doors');

            // Puissance indicative en chevaux (valeur médiane de la gamme)
            $table->unsignedSmallInteger('default_power_hp')->nullable()->after('default_transmission');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->dropColumn([
                'default_vehicle_type',
                'default_seats',
                'default_doors',
                'default_transmission',
                'default_power_hp',
            ]);
        });
    }
};
