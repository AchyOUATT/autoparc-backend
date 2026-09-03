<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table pivot polymorphique : lie un partenaire à une pièce OU un accessoire.
        Schema::create('partnerables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->morphs('partnerable');              // partnerable_id + partnerable_type
            $table->string('role', 60)->nullable();     // 'fournisseur' | 'importateur' | 'distributeur' | ...
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'partnerable_id', 'partnerable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnerables');
    }
};
