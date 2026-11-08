<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The checked-in Kia batch follows the same validation as manual imports. */
class KiaCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::table('brands')->where('slug', 'kia')->exists()) {
            return;
        }

        $exitCode = Artisan::call('catalog:import-trims', [
            'file' => database_path('catalogue/kia-trims-verified.json'),
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Import du catalogue Kia refusé : '.Artisan::output());
        }
    }
}
