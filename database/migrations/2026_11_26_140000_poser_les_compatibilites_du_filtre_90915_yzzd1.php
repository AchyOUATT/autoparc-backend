<?php

use App\Support\Toyota90915Yzzd1Import;
use Illuminate\Database\Migrations\Migration;

/**
 * Pose les compatibilités sourcées du filtre à huile Toyota 90915-YZZD1,
 * jusqu'ici sans aucune application.
 *
 * Idempotent et additif : retrouve l'article par SKU, ne touche ni au prix,
 * ni au stock, ni aux numéros OEM, et ne duplique aucune ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota90915Yzzd1Import::run();
    }

    public function down(): void
    {
        // Données de référence : le rejeu est idempotent, aucune suppression.
    }
};
