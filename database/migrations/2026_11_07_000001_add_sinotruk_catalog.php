<?php

use Database\Seeders\SinotrukCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // RefreshDatabase builds schema-only fixtures for unrelated tests. Those
        // tests opt into catalogue seeders themselves. Exercise this data migration
        // explicitly in SinotrukCatalogTest instead of preloading every fixture.
        // This guard has no effect outside PHPUnit's testing environment.
        if (app()->runningUnitTests() && ! config('catalogue.test_sinotruk_migration', false)) {
            return;
        }

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
