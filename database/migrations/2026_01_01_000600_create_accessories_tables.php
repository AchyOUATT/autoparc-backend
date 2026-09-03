<?php

use App\Enums\AccessoryCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessories', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('category', AccessoryCategory::values());
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->string('dimensions')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();

            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->decimal('vat_rate', 5, 2)->default(18.00);

            $table->integer('stock_quantity')->default(0);
            $table->unsignedInteger('stock_alert_threshold')->default(0);
            $table->string('storage_location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'is_active']);
        });

        // Compatibilite d'un accessoire avec un modele / finition / motorisation / plage d'annees
        Schema::create('accessory_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accessory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('engine_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('drivetrain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_model_id', 'year_from', 'year_to']);
        });

        // Commandes d'accessoires (meme structure que part_orders)
        Schema::create('accessory_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete(); // accessoire pose sur un vehicule du parc
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

        Schema::create('accessory_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accessory_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accessory_id')->constrained()->restrictOnDelete();
            $table->string('designation');       // fige au moment de la commande
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_order_items');
        Schema::dropIfExists('accessory_orders');
        Schema::dropIfExists('accessory_fitments');
        Schema::dropIfExists('accessories');
    }
};
