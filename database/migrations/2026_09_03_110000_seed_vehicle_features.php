<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Migration neutralisée — voir Database\Seeders\VehicleFeaturesSeeder.
 *
 * Cette migration attachait des équipements aux véhicules. Elle ne pouvait pas
 * fonctionner : les migrations s'exécutent avant les seeders, donc `features` et
 * `vehicles` étaient encore vides. Elle sortait alors sur un garde-fou, sans
 * rien faire et sans rien signaler, puis se marquait appliquée — si bien qu'elle
 * ne se rejouait jamais après le peuplement. La page « caractéristiques » de
 * l'application restait vide sur toute installation neuve.
 *
 * Le corps est vidé plutôt que le fichier supprimé : la migration est déjà
 * enregistrée dans les bases existantes (local et production), et retirer le
 * fichier rendrait leur historique incohérent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Volontairement vide : le peuplement est fait par VehicleFeaturesSeeder.
    }

    public function down(): void
    {
        // Volontairement vide : rien à annuler.
    }
};
