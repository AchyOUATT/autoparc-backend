<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache un vehicule du garage a sa motorisation.
 *
 * C'est ce qui donne enfin une consommation a un vehicule : non pas un chiffre
 * recopie sur sa fiche, mais un lien vers la cote officielle du moteur qu'il
 * porte. Une Camry 2013 consomme 8,2 ou 9,4 l/100 selon qu'elle a le 2,5 l
 * quatre cylindres ou le 3,5 l V6 — la finition, elle, ne dit rien.
 *
 * Nullable : la plupart des garages ne renseigneront jamais ce champ, et un
 * vehicule sans motorisation connue doit rester utilisable. L'application
 * propose le choix, elle ne l'impose pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->foreignId('motorisation_id')
                ->nullable()
                ->after('engine_type_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('motorisation_id');
        });
    }
};
