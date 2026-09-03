<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->enum('body_style', [
                'sedan',       // Berline
                'hatchback',   // Citadine / hayon
                'suv',         // SUV / 4×4
                'estate',      // Break
                'coupe',       // Coupé
                'convertible', // Cabriolet
                'pickup',      // Pickup
                'van',         // Fourgonnette
                'minibus',     // Minibus (9–26 places)
                'bus',         // Bus / car (transport en commun)
                'truck',       // Camion
                'other',       // Autre
            ])->nullable()->after('vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('body_style');
        });
    }
};
