<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute engine_code (optionnel) à owned_vehicles.
 *
 * Utilisé par CompatibilityService::partsForOwnedVehicle pour affiner
 * la recherche de pièces quand le code moteur est connu (ex: "1NZ", "K20",
 * "OM651"). Renseigné automatiquement lors du décodage VIN via NHTSA,
 * ou saisi manuellement par le client.
 *
 * Non-nullable avec default null : rétrocompatible avec les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->string('engine_code', 20)->nullable()->after('vin');
        });
    }

    public function down(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->dropColumn('engine_code');
        });
    }
};
