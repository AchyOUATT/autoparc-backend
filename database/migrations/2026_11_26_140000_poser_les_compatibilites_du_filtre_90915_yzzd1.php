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

    /**
     * Retour arrière : retire exactement ce que up() a posé (lignes de même
     * identité et de même note, anciens numéros et leurs liens), sans toucher
     * à une ligne modifiée depuis ni à l'article. Vérifié sur une copie de la
     * production : après retour, part_fitments, oem_numbers et oem_number_part
     * reviennent à l'identique.
     */
    public function down(): void
    {
        Toyota90915Yzzd1Import::retirer();
    }
};
