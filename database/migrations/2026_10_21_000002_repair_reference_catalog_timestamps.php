<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Query Builder seeders omitted dates or retained an old non-null date
        // after changing content. Also re-announce historical trim descriptions
        // now included in sync. Migrations run this once, not on every seed.
        $now = now();

        foreach (['brands', 'vehicle_models', 'trims'] as $table) {
            DB::table($table)->whereNull('created_at')->update(['created_at' => $now]);
            DB::table($table)->update(['updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // The original missing dates cannot be reconstructed. Do not erase
        // valid dates or make references disappear from incremental sync.
    }
};
