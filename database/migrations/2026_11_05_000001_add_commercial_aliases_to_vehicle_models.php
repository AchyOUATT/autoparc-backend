<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table) {
            // Commercial names only: an alias does not establish technical compatibility.
            $table->json('aliases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_models', fn (Blueprint $table) => $table->dropColumn('aliases'));
    }
};
