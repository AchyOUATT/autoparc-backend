<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trim_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trim_id')->constrained()->cascadeOnDelete();
            $table->string('market', 20);
            // A model year is deliberately distinct from the production year.
            // A source for one year must not extend to an entire generation.
            $table->unsignedSmallInteger('model_year_from');
            $table->unsignedSmallInteger('model_year_to');
            $table->json('sources');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(
                ['trim_id', 'market', 'model_year_from', 'model_year_to'],
                'trim_applications_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trim_applications');
    }
};
