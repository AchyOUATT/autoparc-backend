<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vehicles', 'private_vehicle_listings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('fault_declaration_state', 20)->default('unknown');
                $table->timestamp('fault_declared_at')->nullable();
            });
        }
        Schema::table('vehicle_faults', function (Blueprint $table) {
            $table->foreignId('declared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('staff_managed_at')->nullable();
        });
        Schema::create('private_listing_faults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('private_vehicle_listing_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('severity', 16)->default('minor');
            $table->string('status', 20)->default('declared');
            $table->boolean('affects_drivability')->default(false);
            $table->boolean('is_safety_critical')->default(false);
            $table->boolean('disclosed_to_buyer')->default(true);
            $table->timestamps();
            $table->index(['private_vehicle_listing_id', 'status']);
        });
        // Existing rows prove a recorded fault, never a declaration that there are none.
        DB::table('vehicles')->whereExists(fn ($q) => $q->selectRaw('1')->from('vehicle_faults')
            ->whereColumn('vehicle_faults.vehicle_id', 'vehicles.id'))
            ->update(['fault_declaration_state' => 'has_faults']);
    }

    public function down(): void
    {
        Schema::dropIfExists('private_listing_faults');
        Schema::table('vehicle_faults', fn (Blueprint $table) => $table->dropConstrainedForeignId('declared_by_user_id'));
        Schema::table('vehicle_faults', fn (Blueprint $table) => $table->dropColumn('staff_managed_at'));
        foreach (['vehicles', 'private_vehicle_listings'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['fault_declaration_state', 'fault_declared_at']));
        }
    }
};
