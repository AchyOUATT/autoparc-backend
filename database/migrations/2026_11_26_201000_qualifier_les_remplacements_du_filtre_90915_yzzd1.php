<?php

use App\Support\Toyota90915Yzzd1Import;
use Illuminate\Database\Migrations\Migration;

/**
 * Qualifie les remplacements du filtre 90915-YZZD1 dans la table unique
 * `oem_number_relations` (créée par la migration 200000) :
 *  - 90915-YZZB1 / YZZB9 / YZZG2 → manufacturer_supersession / manufacturer_official ;
 *  - 90915-20001 / 20003         → distributor_replacement / dealer_catalog.
 *
 * Rejoue l'import, idempotent : aucune compatibilité dupliquée, aucun lien
 * déjà présent réécrit, aucune compatibilité copiée d'un ancien numéro.
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
