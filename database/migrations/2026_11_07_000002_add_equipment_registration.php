<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('asset_kind', 20)->default('road_vehicle')->index();
            $table->string('equipment_type', 40)->nullable()->index();
            $table->json('equipment_details')->nullable();
            $table->enum('vehicle_type', ['passenger', 'utility', 'heavy'])->nullable()->default('passenger')->change();
            $table->foreignId('brand_id')->nullable()->change();
            $table->foreignId('vehicle_model_id')->nullable()->change();
            $table->foreignId('engine_type_id')->nullable()->change();
            $table->unsignedSmallInteger('manufacturing_year')->nullable()->change();
        });
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->string('equipment_category', 40)->nullable()->index();
        });
        foreach ([
            'caterpillar' => ['generator' => ['cat-de150-gc']],
            'john-deere' => ['agricultural_tractor' => ['jd-5090m', 'jd-5100m', 'jd-5115m']],
            'mccormick' => ['agricultural_tractor' => ['mccormick-mtx-110', 'mccormick-mtx-125', 'mccormick-mtx-140']],
            'jcb' => ['backhoe_loader' => ['jcb-3cx', 'jcb-3dx']],
        ] as $brand => $categories) {
            foreach ($categories as $category => $slugs) {
                DB::table('vehicle_models')->whereIn('brand_id', DB::table('brands')->select('id')->where('slug', $brand))
                    ->whereIn('slug', $slugs)->update(['equipment_category' => $category, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('vehicles')->where('asset_kind', 'equipment')->exists()
            || DB::table('vehicles')->where(fn ($query) => $query->whereNull('vehicle_type')->orWhereNull('brand_id')
                ->orWhereNull('vehicle_model_id')->orWhereNull('engine_type_id')->orWhereNull('manufacturing_year'))->exists()) {
            throw new RuntimeException('Retrait refusé : des engins ou des identités partielles existent. Aucune donnée ne sera supprimée.');
        }
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->dropColumn('equipment_category');
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['asset_kind', 'equipment_type', 'equipment_details']);
            $table->enum('vehicle_type', ['passenger', 'utility', 'heavy'])->nullable(false)->default('passenger')->change();
            $table->foreignId('brand_id')->nullable(false)->change();
            $table->foreignId('vehicle_model_id')->nullable(false)->change();
            $table->foreignId('engine_type_id')->nullable(false)->change();
            $table->unsignedSmallInteger('manufacturing_year')->nullable(false)->change();
        });
    }
};
