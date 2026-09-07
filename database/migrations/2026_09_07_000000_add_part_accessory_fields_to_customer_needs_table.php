<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les critères de matching pour les besoins de type "part" et "accessory".
     */
    public function up(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            // ── Pièce détachée ────────────────────────────────────────
            $table->foreignId('part_category_id')
                  ->nullable()
                  ->after('year_max')
                  ->constrained('part_categories')
                  ->nullOnDelete();

            $table->string('oem_number', 100)->nullable()->after('part_category_id');

            // ── Accessoire ────────────────────────────────────────────
            $table->string('accessory_category', 50)->nullable()->after('oem_number');

            // ── Fabricant (commun pièce + accessoire) ─────────────────
            $table->foreignId('need_manufacturer_id')
                  ->nullable()
                  ->after('accessory_category')
                  ->constrained('manufacturers')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            $table->dropForeign(['part_category_id']);
            $table->dropForeign(['need_manufacturer_id']);
            $table->dropColumn([
                'part_category_id', 'oem_number',
                'accessory_category', 'need_manufacturer_id',
            ]);
        });
    }
};
