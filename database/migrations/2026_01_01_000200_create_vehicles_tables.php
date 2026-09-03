<?php

use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Enums\FaultCategory;
use App\Enums\FaultSeverity;
use App\Enums\FaultStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();          // reference interne du parc
            $table->string('vin', 17)->nullable()->unique(); // chassis

            // Discriminant : non immatricule (import) vs deja immatricule
            $table->enum('registration_status', RegistrationStatus::values())
                  ->default(RegistrationStatus::Unregistered->value);

            // Identite technique
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->foreignId('trim_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('drivetrain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('color_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('manufacturing_year');
            $table->enum('transmission', Transmission::values())->nullable();
            $table->unsignedTinyInteger('gear_count')->nullable();
            $table->unsignedSmallInteger('engine_displacement_cc')->nullable();
            $table->unsignedSmallInteger('power_hp')->nullable();
            $table->unsignedSmallInteger('power_kw')->nullable();
            $table->unsignedSmallInteger('torque_nm')->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->unsignedTinyInteger('seats')->nullable();
            $table->unsignedTinyInteger('doors')->nullable();
            $table->string('engine_code')->nullable();

            // Consommations : thermique (L/100 km) et electrique (kWh/100 km)
            $table->decimal('consumption_urban', 5, 2)->nullable();
            $table->decimal('consumption_extra_urban', 5, 2)->nullable();
            $table->decimal('consumption_combined', 5, 2)->nullable();
            $table->decimal('electric_consumption_kwh', 5, 2)->nullable();
            $table->decimal('battery_capacity_kwh', 6, 2)->nullable();
            $table->unsignedSmallInteger('electric_range_km')->nullable();
            $table->unsignedSmallInteger('co2_g_km')->nullable();
            $table->unsignedSmallInteger('fuel_tank_liters')->nullable();

            // Commercial
            $table->enum('condition', VehicleCondition::values())->default(VehicleCondition::Used->value);
            $table->enum('status', VehicleStatus::values())->default(VehicleStatus::InStock->value);
            $table->enum('availability', VehicleAvailability::values())->default(VehicleAvailability::Sale->value);
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->decimal('rental_daily_rate', 12, 2)->nullable();
            $table->decimal('rental_weekly_rate', 12, 2)->nullable();
            $table->decimal('rental_monthly_rate', 12, 2)->nullable();
            $table->decimal('rental_deposit', 12, 2)->nullable();
            $table->unsignedInteger('rental_mileage_limit_per_day')->nullable();
            $table->char('currency', 3)->default('XOF');
            $table->boolean('price_negotiable')->default(true);

            $table->string('site')->nullable();   // parc / agence
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['registration_status', 'status']);
            $table->index(['brand_id', 'vehicle_model_id', 'manufacturing_year']);
            $table->index('availability');
        });

        // Details specifiques aux vehicules NON IMMATRICULES (import)
        Schema::create('vehicle_import_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('origin_country_id')->constrained('countries')->restrictOnDelete(); // pays de provenance
            $table->foreignId('purchase_country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('auction_lot_no')->nullable();
            $table->string('port_of_loading')->nullable();
            $table->string('port_of_entry')->nullable();
            $table->string('bill_of_lading_no')->nullable();
            $table->string('container_no')->nullable();
            $table->date('shipping_date')->nullable();
            $table->date('arrival_date')->nullable();
            $table->boolean('customs_cleared')->default(false);
            $table->string('customs_declaration_no')->nullable();
            $table->decimal('customs_duty_amount', 14, 2)->nullable();
            $table->decimal('freight_cost', 14, 2)->nullable();
            $table->enum('steering_side', ['left', 'right'])->default('left');
            $table->unsignedInteger('odometer_at_import_km')->nullable();
            $table->string('foreign_plate')->nullable();   // plaque du pays d'origine
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Details specifiques aux vehicules DEJA IMMATRICULES
        Schema::create('vehicle_registration_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plate_number')->index();
            $table->foreignId('registration_country_id')->constrained('countries')->restrictOnDelete();
            $table->string('registration_certificate_no')->nullable(); // carte grise
            $table->date('first_registration_date')->nullable();
            $table->date('last_transfer_date')->nullable();
            $table->unsignedInteger('mileage_km')->default(0);
            $table->unsignedTinyInteger('previous_owners_count')->nullable();
            $table->date('technical_inspection_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->string('insurance_company')->nullable();
            $table->boolean('service_book_available')->default(false);
            $table->date('last_service_date')->nullable();
            $table->unsignedInteger('last_service_mileage_km')->nullable();
            $table->boolean('has_accident_history')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Pannes declarees : principalement pour les vehicules deja immatricules
        Schema::create('vehicle_faults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();  // code defaut OBD-II (P0300...)
            $table->enum('category', FaultCategory::values());
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('severity', FaultSeverity::values())->default(FaultSeverity::Minor->value);
            $table->enum('status', FaultStatus::values())->default(FaultStatus::Declared->value);
            $table->boolean('affects_drivability')->default(false);
            $table->boolean('is_safety_critical')->default(false);
            $table->boolean('disclosed_to_buyer')->default(true); // transparence a la vente
            $table->date('detected_at')->nullable();
            $table->unsignedInteger('mileage_at_detection_km')->nullable();
            $table->string('reported_by')->nullable();
            $table->decimal('estimated_repair_cost', 12, 2)->nullable();
            $table->decimal('actual_repair_cost', 12, 2)->nullable();
            $table->date('repaired_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index('severity');
        });

        Schema::create('feature_vehicle', function (Blueprint $table) {
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->primary(['feature_id', 'vehicle_id']);
        });

        // Medias polymorphes (photos vehicules et pieces, documents douane, carte grise...)
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable');
            $table->string('collection')->default('gallery'); // gallery, documents, damages
            $table->string('path');
            $table->string('disk')->default('public');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('feature_vehicle');
        Schema::dropIfExists('vehicle_faults');
        Schema::dropIfExists('vehicle_registration_details');
        Schema::dropIfExists('vehicle_import_details');
        Schema::dropIfExists('vehicles');
    }
};
