<?php

use App\Support\LandCruiserJ200GxrTrimImport;
use Illuminate\Database\Migrations\Migration;

/**
 * Ajoute la finition GX-R (code GXR) au Toyota Land Cruiser J200 et replace
 * la gamme du Golfe dans l'ordre GX < GX-R < VX < VX-R.
 *
 * Idempotente : retrouve le modèle par slug et la finition par nom. Le
 * replay au démarrage du conteneur (`migrate --force`) est donc sûr.
 */
return new class extends Migration
{
    public function up(): void
    {
        LandCruiserJ200GxrTrimImport::run();
    }

    public function down(): void
    {
        // Non destructif : des véhicules peuvent déjà pointer vers cette
        // finition. La retirer demande une revue explicite. Le replay est
        // idempotent.
    }
};
