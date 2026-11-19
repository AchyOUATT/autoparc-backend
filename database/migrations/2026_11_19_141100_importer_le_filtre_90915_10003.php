<?php

use App\Support\Toyota10003VerifiedImport;
use Illuminate\Database\Migrations\Migration;

/**
 * Crée le 90915-10003 (inactif, sans prix ni stock inventés) et pose les
 * applications CUSCO dont le modèle est déjà au catalogue.
 *
 * Idempotent : rejouer n'empile pas les lignes, ne réécrit pas un prix
 * déjà posé, et ne touche pas aux compatibilités du 90915-YZZN1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota10003VerifiedImport::run();
    }

    public function down(): void
    {
        // Données de référence déjà utilisables ; le replay est idempotent.
    }
};
