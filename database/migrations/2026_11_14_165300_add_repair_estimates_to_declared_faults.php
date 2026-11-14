<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chiffrage public facultatif, distinct de estimated_repair_cost (suivi interne).
 *
 * Les applications v131 a v133 ignorent les cles inconnues. Un client ancien
 * qui met a jour un defaut sans envoyer repair_estimate conserve le chiffrage.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['vehicle_faults', 'private_listing_faults'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'repair_necessity')) {
                    $blueprint->string('repair_necessity', 20)->nullable();
                }
                if (! Schema::hasColumn($table, 'repair_cost_min')) {
                    $blueprint->unsignedBigInteger('repair_cost_min')->nullable();
                }
                if (! Schema::hasColumn($table, 'repair_cost_max')) {
                    $blueprint->unsignedBigInteger('repair_cost_max')->nullable();
                }
                if (! Schema::hasColumn($table, 'repair_estimate_source')) {
                    $blueprint->string('repair_estimate_source', 20)->nullable();
                }
                if (! Schema::hasColumn($table, 'repair_estimated_at')) {
                    $blueprint->date('repair_estimated_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('fault_repair_quotes')) {
            Schema::create('fault_repair_quotes', function (Blueprint $table) {
                $table->id();
                $table->morphs('fault');
                $table->string('kind', 16);
                $table->string('original_name', 255);
                $table->string('mime_type', 80);
                $table->unsignedInteger('size');
                $table->string('disk', 40);
                $table->string('path', 255);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['fault_type', 'fault_id', 'kind']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_repair_quotes');
        foreach (['vehicle_faults', 'private_listing_faults'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $columns = array_values(array_filter([
                    Schema::hasColumn($table, 'repair_estimated_at') ? 'repair_estimated_at' : null,
                    Schema::hasColumn($table, 'repair_estimate_source') ? 'repair_estimate_source' : null,
                    Schema::hasColumn($table, 'repair_cost_max') ? 'repair_cost_max' : null,
                    Schema::hasColumn($table, 'repair_cost_min') ? 'repair_cost_min' : null,
                    Schema::hasColumn($table, 'repair_necessity') ? 'repair_necessity' : null,
                ]));
                if ($columns !== []) {
                    $blueprint->dropColumn($columns);
                }
            });
        }
    }
};
