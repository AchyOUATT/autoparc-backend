<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('disk', 80)->default('local');
            $table->string('path');
            $table->date('expires_on')->nullable();
            $table->string('status', 20)->default('provided');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'document_type']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('equipment_documents') && DB::table('equipment_documents')->exists()) {
            throw new RuntimeException('Retrait refusé : des justificatifs d’engins existent. Aucune donnée ne sera supprimée.');
        }
        Schema::dropIfExists('equipment_documents');
    }
};
