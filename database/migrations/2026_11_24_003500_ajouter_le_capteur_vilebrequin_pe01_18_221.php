<?php

use App\Support\MazdaPe0118221Import;
use Illuminate\Database\Migrations\Migration;

/**
 * Ajoute ou met à jour le capteur de vilebrequin compatible Mazda PE01-18-221
 * (adaptable Chine, 20 000 F, stock 2) et pose les applications sourcées
 * dont le modèle est déjà au catalogue.
 *
 * Idempotent : retrouve la pièce par SKU, ne réécrit pas un prix, un stock
 * ou une publication déjà posés, et ne duplique pas les lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        MazdaPe0118221Import::run();
    }

    public function down(): void
    {
        // Données de référence déjà utilisables ; le replay est idempotent.
        // La fiche peut déjà exister en production avec un stock saisi.
    }
};
