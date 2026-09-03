<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pays : sert de pays de provenance (import) et de pays d'immatriculation.
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->char('iso3', 3)->unique();
            $table->string('name');
            $table->string('nationality')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->boolean('is_common_origin')->default(false); // provenance frequente a l'import
            $table->timestamps();
        });

        // Marques automobiles (Toyota, Hyundai, ...)
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('oem_prefix')->nullable(); // aide au parsing des numeros OEM
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Modeles rattaches a une marque (Corolla, Hilux, ...)
        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('generation')->nullable();   // ex: E210
            $table->string('body_type')->nullable();    // berline, SUV, pick-up, break...
            $table->string('segment')->nullable();      // A, B, C, D...
            $table->unsignedSmallInteger('production_start')->nullable();
            $table->unsignedSmallInteger('production_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['brand_id', 'slug']);
        });

        // Niveaux de finition (LE, SE, Limited, GLS, ...)
        Schema::create('trims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('rank')->default(0); // hierarchie de gamme
            $table->timestamps();

            $table->unique(['vehicle_model_id', 'name']);
        });

        // Motricites : traction, propulsion, integrale...
        Schema::create('drivetrains', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();  // FWD, RWD, AWD, 4WD
            $table->string('label');
            $table->timestamps();
        });

        // Types de motorisation : essence, gasoil, electrique, hybride...
        Schema::create('engine_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();  // petrol, diesel, electric, hybrid, phev, lpg
            $table->string('label');
            $table->boolean('uses_fuel')->default(true);
            $table->boolean('uses_battery')->default(false);
            $table->timestamps();
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('hex_code', 7)->nullable();
            $table->string('finish')->nullable(); // opaque, metallise, nacre, mat
            $table->timestamps();
        });

        // Equipements / options (climatisation, camera de recul, ...)
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category')->nullable(); // confort, securite, multimedia
            $table->timestamps();
        });

        // Familles de pieces detachees (arborescence)
        Schema::create('part_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('part_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Equipementiers (Bosch, Denso, Valeo...)
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_oem_supplier')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('part_categories');
        Schema::dropIfExists('features');
        Schema::dropIfExists('colors');
        Schema::dropIfExists('engine_types');
        Schema::dropIfExists('drivetrains');
        Schema::dropIfExists('trims');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('countries');
    }
};
