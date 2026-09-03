<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            // ── Critères structurés pour le matching véhicule ──────────────
            // Tous nullables : null = pas de contrainte sur ce critère.
            $table->foreignId('brand_id')
                  ->nullable()->constrained('brands')->nullOnDelete()
                  ->after('budget_max');

            $table->enum('vehicle_type', ['passenger', 'utility', 'heavy'])
                  ->nullable()
                  ->after('brand_id');

            $table->enum('body_style', [
                'sedan', 'hatchback', 'suv', 'estate', 'coupe', 'convertible',
                'pickup', 'van', 'minibus', 'bus', 'truck', 'other',
            ])->nullable()->after('vehicle_type');

            $table->unsignedSmallInteger('year_min')->nullable()->after('body_style');
            $table->unsignedSmallInteger('year_max')->nullable()->after('year_min');
        });
    }

    public function down(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['vehicle_type', 'body_style', 'year_min', 'year_max']);
        });
    }
};
