<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->unsignedInteger('engine_displacement_cc')->nullable();
            // Commercial litres are supplied separately; never infer exact cc.
            $table->decimal('engine_displacement_l', 6, 3)->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->string('engine_layout', 20)->nullable();
            $table->unsignedInteger('power_hp')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->dropColumn(['engine_displacement_cc', 'engine_displacement_l', 'cylinders', 'engine_layout', 'power_hp']);
        });
    }
};
