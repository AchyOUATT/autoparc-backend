<?php

use App\Enums\PartCondition;
use App\Enums\PartType;
use App\Enums\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();              // reference interne
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('part_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manufacturer_reference')->nullable(); // ref equipementier (ex: Bosch 0986452041)
            $table->enum('type', PartType::values())->default(PartType::Aftermarket->value);
            $table->enum('condition', PartCondition::values())->default(PartCondition::New->value);

            // Piece issue d'un vehicule de casse (tracabilite)
            $table->foreignId('donor_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->string('dimensions')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();

            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->decimal('vat_rate', 5, 2)->default(18.00);

            $table->integer('stock_quantity')->default(0);
            $table->unsignedInteger('stock_alert_threshold')->default(0);
            $table->string('storage_location')->nullable(); // allee / rayon / bac
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['part_category_id', 'is_active']);
            $table->index('manufacturer_reference');
        });

        // Numeros OEM : c'est la cle de correspondance piece <-> vehicule
        Schema::create('oem_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('number');                    // tel qu'imprime : 90915-YZZD4
            $table->string('normalized_number')->index(); // 90915YZZD4 (recherche insensible)
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete(); // constructeur emetteur
            $table->string('label')->nullable();
            $table->boolean('is_superseded')->default(false);
            $table->foreignId('superseded_by_id')->nullable()->constrained('oem_numbers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['normalized_number', 'brand_id']);
        });

        // Une piece peut couvrir plusieurs numeros OEM (cross-reference)
        Schema::create('oem_number_part', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('oem_number_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['part_id', 'oem_number_id']);
        });

        // Applicabilite d'un numero OEM a un modele / finition / motorisation / plage d'annees
        Schema::create('oem_number_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oem_number_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('engine_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('drivetrain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('engine_code')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('position')->nullable(); // avant gauche, arriere droit...
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_model_id', 'year_from', 'year_to']);
        });

        // Compatibilite declaree directement piece -> modele (sans passer par un OEM)
        Schema::create('part_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('engine_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('drivetrain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('engine_code')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('position')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_model_id', 'year_from', 'year_to']);
        });

        // Pieces preconisees pour reparer une panne declaree
        Schema::create('fault_part', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_fault_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['vehicle_fault_id', 'part_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->enum('type', StockMovementType::values());
            $table->integer('quantity');            // signe applique par le service
            $table->integer('stock_after');
            $table->nullableMorphs('source');       // commande, reparation, inventaire
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['part_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('fault_part');
        Schema::dropIfExists('part_fitments');
        Schema::dropIfExists('oem_number_fitments');
        Schema::dropIfExists('oem_number_part');
        Schema::dropIfExists('oem_numbers');
        Schema::dropIfExists('parts');
    }
};
