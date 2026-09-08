<?php

use App\Enums\VehicleDealType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mise en avant commerciale : « Bonne affaire », « Vente flash », « Destockage ».
 *
 * Colonne nullable — null vaut « pas de promotion ». Indexee car elle sert de
 * filtre pour le carrousel de la page vehicules, qui la lit a chaque ouverture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->enum('deal_type', VehicleDealType::values())
                ->nullable()
                ->after('price_negotiable')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['deal_type']);
            $table->dropColumn('deal_type');
        });
    }
};
