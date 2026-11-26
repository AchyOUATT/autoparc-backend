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

    /**
     * Retour arrière : retire les lignes posées par up() (même empreinte) et
     * les générations créées, sauf celles que quelque chose référence
     * désormais (véhicule, finition, motorisation…), qui restent. Vérifié sur
     * une copie de la production : part_fitments et vehicle_models reviennent
     * à l'identique.
     */
    public function down(): void
    {
        Toyota90915Yzzd1AnciensModelesImport::retirer();
    }
};
