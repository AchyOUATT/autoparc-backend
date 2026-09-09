<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Echeances d'entretien du garage client.
 *
 * Le garage ne stockait que des caracteristiques figees (marque, modele, VIN).
 * Ces quatre colonnes y ajoutent des dates qui expirent : ce sont elles qui
 * rendent les rappels recurrents par construction, sans rien avoir a inventer.
 *
 * Les trois echeances retenues sont celles que tout proprietaire subit :
 * visite technique, assurance, vidange. Toutes nullables — un proprietaire
 * qui n'en renseigne aucune ne recoit simplement aucun rappel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->date('technical_inspection_expiry')->nullable()->after('mileage_km');
            $table->date('insurance_expiry')->nullable()->after('technical_inspection_expiry');
            $table->date('last_service_date')->nullable()->after('insurance_expiry');
            $table->unsignedInteger('last_service_mileage_km')->nullable()->after('last_service_date');

            // Intervalle de vidange choisi par le proprietaire (5 000, 10 000…).
            // Null = pas de suivi kilometrique, seules les dates comptent.
            $table->unsignedInteger('service_interval_km')->nullable()->after('last_service_mileage_km');

            // Les deux dates sont interrogees ensemble par la tache de rappel.
            $table->index(['technical_inspection_expiry', 'insurance_expiry'], 'owned_vehicles_deadlines_index');
        });
    }

    public function down(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->dropIndex('owned_vehicles_deadlines_index');
            $table->dropColumn([
                'technical_inspection_expiry',
                'insurance_expiry',
                'last_service_date',
                'last_service_mileage_km',
                'service_interval_km',
            ]);
        });
    }
};
