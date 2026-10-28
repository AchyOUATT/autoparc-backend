<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_vehicle_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owned_vehicle_id')->nullable()->constrained()->nullOnDelete();
            // Nullable UNIQUE enforces the one-current-listing rule even on concurrent requests.
            $table->unsignedBigInteger('active_user_id')->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->string('title', 120);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('mileage_km');
            $table->unsignedBigInteger('price');
            $table->string('currency', 3)->default('XOF');
            $table->string('city', 100);
            $table->string('fuel_type', 20)->nullable();
            $table->string('transmission', 20)->nullable();
            $table->text('description');
            $table->string('seller_name', 80);
            $table->string('verified_phone', 20)->nullable();
            $table->boolean('whatsapp_enabled')->default(false);
            $table->timestamp('phone_verified_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('private_listing_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('private_vehicle_listing_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_listing_photos');
        Schema::dropIfExists('private_vehicle_listings');
    }
};
