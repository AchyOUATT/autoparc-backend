<?php

use App\Support\Toyota90915Yzzd1AnciensModelesImport;
use Illuminate\Database\Migrations\Migration;

/**
 * Pose seize générations anciennes Toyota / Lexus (définies dans
 * `VehicleModelsSeeder::GENERATIONS_FILTRE_YZZD1`) puis les applications
 * sourcées du filtre 90915-YZZD1 sur ces générations.
 *
 * Additive et idempotente : insertion seulement si le slug manque, aucune
 * ligne existante modifiée, aucune compatibilité dupliquée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota90915Yzzd1AnciensModelesImport::run();
    }

    public function down(): void
    {
        // Non destructif : des véhicules peuvent déjà pointer vers ces fiches.
    }
};
