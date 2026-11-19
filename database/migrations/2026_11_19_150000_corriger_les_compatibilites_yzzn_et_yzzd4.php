<?php

use App\Support\CorrigerLesFiltresYzznEtYzzd4;
use Illuminate\Database\Migrations\Migration;

/**
 * Recale les filtres YZZN1, YZZN2, 10003 et YZZD4 uniquement sur les lignes
 * sourcées (modèle + moteur + période, et châssis quand la source en donne un).
 * Les analogies de moteur restent « à vérifier ». Idempotent : rejouer
 * n'empile pas les lignes, ne touche ni aux prix ni au stock, et laisse
 * intactes les autres pièces.
 */
return new class extends Migration
{
    public function up(): void
    {
        CorrigerLesFiltresYzznEtYzzd4::run();
    }

    public function down(): void
    {
        // Données de référence déjà utilisables ; le replay est idempotent.
    }
};
