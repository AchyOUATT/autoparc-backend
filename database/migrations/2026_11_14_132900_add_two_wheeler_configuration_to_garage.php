<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration deux-roues du garage, sans etendre body_style.
 *
 * Les applications deja publiees (v131 a v133) ignorent les cles JSON
 * inconnues. vehicle_types null sur un point de controle conserve le
 * comportement actuel (tous les types).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('owned_vehicles') && ! Schema::hasColumn('owned_vehicles', 'two_wheeler_configuration')) {
            Schema::table('owned_vehicles', function (Blueprint $table) {
                $table->json('two_wheeler_configuration')->nullable()->after('vehicle_type');
            });
        }

        if (Schema::hasTable('vehicle_check_items') && ! Schema::hasColumn('vehicle_check_items', 'vehicle_types')) {
            Schema::table('vehicle_check_items', function (Blueprint $table) {
                $table->json('vehicle_types')->nullable()->after('body_types');
            });
        }

        if (Schema::hasTable('private_vehicle_listings')) {
            Schema::table('private_vehicle_listings', function (Blueprint $table) {
                if (! Schema::hasColumn('private_vehicle_listings', 'vehicle_type')) {
                    $table->string('vehicle_type', 20)->nullable()->after('color_id');
                }
                if (! Schema::hasColumn('private_vehicle_listings', 'two_wheeler_configuration')) {
                    $table->json('two_wheeler_configuration')->nullable()->after('vehicle_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('owned_vehicles') && Schema::hasColumn('owned_vehicles', 'two_wheeler_configuration')) {
            Schema::table('owned_vehicles', function (Blueprint $table) {
                $table->dropColumn('two_wheeler_configuration');
            });
        }

        if (Schema::hasTable('vehicle_check_items') && Schema::hasColumn('vehicle_check_items', 'vehicle_types')) {
            Schema::table('vehicle_check_items', function (Blueprint $table) {
                $table->dropColumn('vehicle_types');
            });
        }

        if (Schema::hasTable('private_vehicle_listings')) {
            Schema::table('private_vehicle_listings', function (Blueprint $table) {
                $columns = array_values(array_filter([
                    Schema::hasColumn('private_vehicle_listings', 'two_wheeler_configuration') ? 'two_wheeler_configuration' : null,
                    Schema::hasColumn('private_vehicle_listings', 'vehicle_type') ? 'vehicle_type' : null,
                ]));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
