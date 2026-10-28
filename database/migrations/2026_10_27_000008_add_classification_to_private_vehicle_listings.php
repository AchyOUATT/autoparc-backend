<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            // Nullable for announcements created by the first local prototype.
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_libre', 80)->nullable();
            $table->foreignId('trim_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drivetrain_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('color_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot approved with the announcement, independent of catalog renames.
            $table->json('vehicle_identity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            foreach (['brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['model_libre', 'vehicle_identity']);
        });
    }
};
