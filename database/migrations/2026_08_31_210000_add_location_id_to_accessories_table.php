<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement des accessoires à une agence / showroom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->foreignId('location_id')
                  ->nullable()
                  ->after('storage_location')
                  ->constrained('locations')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Location::class);
        });
    }
};
