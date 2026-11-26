<?php

use App\Support\Toyota90915Yzzd1AnciensModelesImport;
use Illuminate\Database\Migrations\Migration;

/**
 * Pose la preuve structurée (evidence_level / evidence_sources, créées par la
 * migration 200000) sur les compatibilités des générations anciennes posées
 * par la migration 160000, en rejouant l'import (idempotent) : seules ses
 * propres lignes sans preuve sont qualifiées, rien n'est dupliqué ni réécrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota90915Yzzd1AnciensModelesImport::run();
    }

    public function down(): void
    {
        // Données de référence : le rejeu est idempotent, aucune suppression.
    }
};
