<?php

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RentalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', CustomerType::values())->default(CustomerType::Individual->value);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('tax_id')->nullable();          // IFU / RCCM
            $table->string('id_document_type')->nullable(); // CNIB, passeport
            $table->string('id_document_number')->nullable();
            $table->string('driving_licence_number')->nullable();
            $table->date('driving_licence_expiry')->nullable();
            $table->string('phone');
            $table->string('phone_alt')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_blacklisted')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Vente de vehicule
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('agreed_price', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('registration_fees', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->char('currency', 3)->default('XOF');
            $table->enum('payment_status', PaymentStatus::values())->default(PaymentStatus::Pending->value);
            $table->boolean('faults_disclosed')->default(false); // pannes portees a la connaissance de l'acheteur
            $table->date('sold_at');
            $table->date('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Location de vehicule
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', RentalStatus::values())->default(RentalStatus::Reserved->value);
            $table->boolean('with_driver')->default(false);
            $table->string('driver_name')->nullable();

            $table->dateTime('start_at');
            $table->dateTime('expected_return_at');
            $table->dateTime('actual_return_at')->nullable();

            $table->decimal('daily_rate', 12, 2);
            $table->unsignedInteger('billed_days')->default(1);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->boolean('deposit_returned')->default(false);
            $table->decimal('extra_charges', 12, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->enum('payment_status', PaymentStatus::values())->default(PaymentStatus::Pending->value);

            $table->unsignedInteger('mileage_start_km')->nullable();
            $table->unsignedInteger('mileage_end_km')->nullable();
            $table->unsignedInteger('mileage_limit_km')->nullable();
            $table->decimal('extra_km_rate', 10, 2)->nullable();
            $table->unsignedTinyInteger('fuel_level_start')->nullable(); // en %
            $table->unsignedTinyInteger('fuel_level_end')->nullable();
            $table->string('pickup_location')->nullable();
            $table->string('return_location')->nullable();
            $table->text('checkout_notes')->nullable();
            $table->text('checkin_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'start_at', 'expected_return_at']);
            $table->index('status');
        });

        // Commandes de pieces detachees
        Schema::create('part_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete(); // pieces posees sur un vehicule du parc
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', OrderStatus::values())->default(OrderStatus::Draft->value);
            $table->enum('payment_status', PaymentStatus::values())->default(PaymentStatus::Pending->value);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->date('ordered_at');
            $table->date('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('part_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->string('designation');       // fige au moment de la commande
            $table->string('oem_reference')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        // Paiements polymorphes : vente, location ou commande de pieces
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');
            $table->string('reference')->unique();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('XOF');
            $table->enum('method', PaymentMethod::values());
            $table->string('transaction_id')->nullable(); // ref Orange Money / Moov Money / virement
            $table->dateTime('paid_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('part_order_items');
        Schema::dropIfExists('part_orders');
        Schema::dropIfExists('rentals');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
    }
};
