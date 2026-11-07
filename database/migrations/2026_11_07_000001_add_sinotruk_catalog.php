<?php

use Database\Seeders\SinotrukCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Transactional import: exceptions propagate, never mark a partial import complete.
        (new SinotrukCatalogSeeder)->run();
    }

    public function down(): void
    {
        // Intentionally non-destructive: provenance and references cannot be inferred
        // from a slug. Keep pre-existing, imported and subsequently referenced rows.
        // Re-applying up() is safe; removal requires an explicitly reviewed data change.
    }
};
