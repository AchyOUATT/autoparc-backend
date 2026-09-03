<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Mon garage" : vehicules personnels du client, distincts du parc commercial (vehicles).
        // Aucun champ commercial ici (prix, statut de vente/location...).
        Schema::create('owned_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->foreignId('trim_id')->nullable()->constrained()->nullOnDelete();

            // Nullable : si absents, on retombe sur les valeurs par defaut de la finition.
            $table->foreignId('engine_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drivetrain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('color_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('manufacturing_year');
            $table->string('vin', 17)->nullable();
            $table->string('plate_number')->nullable();
            $table->string('nickname')->nullable(); // ex: "Ma Corolla"
            $table->unsignedInteger('mileage_km')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'vehicle_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_vehicles');
    }
};
