<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute un code châssis facultatif aux compatibilités.
 *
 * Toyota change parfois de référence de filtre d'un châssis à l'autre avec
 * le même moteur (90915-10001 contre 90915-10003). La génération du modèle
 * ne suffit pas toujours à le dire. La colonne est nullable : les lignes
 * existantes et les anciennes applications restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_fitments', function (Blueprint $table) {
            $table->string('chassis_code', 80)->nullable()->after('engine_code');
        });

        Schema::table('accessory_fitments', function (Blueprint $table) {
            $table->string('chassis_code', 80)->nullable()->after('drivetrain_id');
        });
    }

    public function down(): void
    {
        Schema::table('part_fitments', function (Blueprint $table) {
            $table->dropColumn('chassis_code');
        });

        Schema::table('accessory_fitments', function (Blueprint $table) {
            $table->dropColumn('chassis_code');
        });
    }
};
