<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_vehicle_inspections', function (Blueprint $table) {
            $table->id();
            // Native FKs keep the two identity namespaces distinct. Reports survive user removal.
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('private_vehicle_listing_id')->nullable()->constrained()->nullOnDelete();
            // Immutable identity snapshots retain history without preventing account/product deletion.
            $table->string('source', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('active_key', 80)->nullable()->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at');
            $table->unsignedInteger('mileage_km');
            $table->timestamp('valid_until')->nullable();
            $table->text('summary');
            $table->json('items');
            $table->string('result', 20);
            $table->unsignedInteger('checked_count');
            $table->unsignedInteger('attention_count');
            $table->unsignedInteger('blocking_count');
            $table->string('status', 20)->default('active');
            $table->string('technical_fingerprint', 64);
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'id']);
            $table->index(['private_vehicle_listing_id', 'id'], 'private_inspection_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_vehicle_inspections');
    }
};
