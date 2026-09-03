<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agences / showrooms du parc, répartis sur plusieurs villes.
 * Un véhicule et une pièce peuvent être rattachés à une location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');                // "Showroom Paspanga", "Agence Bobo"
            $table->string('city');                // "Ouagadougou", "Bobo-Dioulasso", …
            $table->string('address')->nullable(); // adresse postale
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city', 'is_active']);
        });

        // Rattachement structuré des véhicules à une agence
        // (complète / remplace le champ site texte libre)
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('location_id')
                  ->nullable()
                  ->after('site')
                  ->constrained('locations')
                  ->nullOnDelete();
        });

        // Rattachement des pièces à une agence / dépôt
        Schema::table('parts', function (Blueprint $table) {
            $table->foreignId('location_id')
                  ->nullable()
                  ->after('storage_location')
                  ->constrained('locations')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Location::class);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Location::class);
        });
        Schema::dropIfExists('locations');
    }
};
