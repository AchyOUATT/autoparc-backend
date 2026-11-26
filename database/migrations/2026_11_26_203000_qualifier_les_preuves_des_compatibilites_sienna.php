<?php

use App\Support\ToyotaSiennaReferentielImport;
use Illuminate\Database\Migrations\Migration;

/**
 * Pose la preuve structurée (evidence_level / evidence_sources, créées par la
 * migration 200000) sur les compatibilités Sienna posées par la migration
 * 170000, en rejouant l'import (idempotent) : seules ses propres lignes sans
 * preuve sont qualifiées, rien n'est dupliqué ni réécrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        ToyotaSiennaReferentielImport::run();
    }

    public function down(): void
    {
        // Données de référence : le rejeu est idempotent, aucune suppression.
    }
};
