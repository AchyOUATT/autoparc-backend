<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le taux, et le prix propose par le particulier, conserves AVEC l'annonce.
 *
 * La colonne `price` reste le prix PUBLIC, frais compris : le catalogue, les
 * filtres, le tri et la page de partage doivent travailler sur ce que
 * l'acheteur voit. `private_pricing` garde la trace de ce qui l'a produit.
 *
 * Les annonces deja en ligne restent a null : elles ont ete enregistrees
 * quand aucun frais n'existait, donc leur prix EST celui que le vendeur a
 * propose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            $table->json('private_pricing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            $table->dropColumn('private_pricing');
        });
    }
};
