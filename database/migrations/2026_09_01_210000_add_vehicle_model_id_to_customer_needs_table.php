<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            $table->foreignId('vehicle_model_id')
                  ->nullable()
                  ->constrained('vehicle_models')
                  ->nullOnDelete()
                  ->after('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_model_id');
        });
    }
};
