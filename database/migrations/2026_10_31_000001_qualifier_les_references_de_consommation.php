<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motorisations', function (Blueprint $table) {
            // A source configuration and a publication revision are different identities.
            $table->dropUnique('motorisations_source_unique');
            $table->string('identity_key', 64)->nullable()->index();
            $table->boolean('is_current')->default(true);
            $table->decimal('power_kw', 7, 1)->nullable();
            $table->unsignedInteger('displacement_cc')->nullable();
            $table->string('market', 20)->nullable();
            $table->string('year_kind', 24)->default('unknown');
            $table->string('method', 24)->default('unknown');
            $table->string('hybrid_type', 24)->nullable();
            $table->string('reference_status', 24)->default('legacy');
            $table->json('provenance')->nullable();
            $table->timestamp('verified_at')->nullable();
            // These optional links require documentary verification, never a name guess.
            $table->foreignId('trim_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trim_application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine_code', 20)->nullable();
        });

        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('specification_market', 20)->nullable();
        });

        Schema::create('consumption_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->index();
            $table->string('scope', 24);
            $table->string('status', 24);
            $table->json('files')->nullable();
            $table->unsignedInteger('written_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        // Application rollback can retain this additive schema. Never delete imported
        // evidence or merge revisions merely to make the old uniqueness fit again.
        if (DB::table('motorisations')->where('reference_status', '!=', 'legacy')->exists()) {
            throw new RuntimeException('Des references documentees existent : conserver le schema et revenir a la version applicative precedente.');
        }
        Schema::dropIfExists('consumption_imports');
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->dropColumn(['model_year', 'specification_market']);
        });
        Schema::table('motorisations', function (Blueprint $table) {
            $table->unique([
                'source', 'model_year', 'make_raw', 'model_raw', 'engine_l',
                'cylinders', 'transmission_code', 'fuel_code',
            ], 'motorisations_source_unique');
            $table->dropConstrainedForeignId('trim_application_id');
            $table->dropConstrainedForeignId('trim_id');
            $table->dropIndex(['identity_key']);
            $table->dropColumn([
                'identity_key', 'is_current', 'power_kw', 'displacement_cc', 'market',
                'year_kind', 'method', 'hybrid_type', 'reference_status', 'provenance',
                'verified_at', 'engine_code',
            ]);
        });
    }
};
