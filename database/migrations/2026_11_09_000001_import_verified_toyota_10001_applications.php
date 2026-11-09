<?php

use App\Support\Toyota10001VerifiedImport;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Toyota10001VerifiedImport::run();
    }

    public function down(): void
    {
        // Additive reference data can already be used by customer vehicles.
        // Keep it and its provenance on rollback; replay is idempotent.
    }
};
