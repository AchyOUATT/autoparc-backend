<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trims', function (Blueprint $table) {
            // Nullable : certaines finitions existent avec plusieurs motorisations,
            // dans ce cas on laisse le client preciser lui-meme.
            $table->foreignId('default_engine_type_id')->nullable()->after('rank')
                ->constrained('engine_types')->nullOnDelete();
            $table->foreignId('default_drivetrain_id')->nullable()->after('default_engine_type_id')
                ->constrained('drivetrains')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_engine_type_id');
            $table->dropConstrainedForeignId('default_drivetrain_id');
        });
    }
};
