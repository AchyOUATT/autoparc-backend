<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An absent reading is unknown. Existing zero readings cannot be
        // distinguished from old defaults, so no stored value is rewritten.
        foreach (['vehicle_registration_details', 'private_vehicle_listings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('mileage_km')->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['vehicle_registration_details', 'private_vehicle_listings'] as $tableName) {
            if (DB::table($tableName)->whereNull('mileage_km')->exists()) {
                throw new RuntimeException('Cannot restore required mileage while unknown readings exist.');
            }
        }

        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            $table->unsignedInteger('mileage_km')->nullable(false)->change();
        });
        Schema::table('vehicle_registration_details', function (Blueprint $table) {
            $table->unsignedInteger('mileage_km')->nullable(false)->default(0)->change();
        });
    }
};
