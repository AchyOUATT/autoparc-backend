<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EngineTypesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'petrol',   'label' => 'Essence',    'uses_fuel' => true,  'uses_battery' => false],
            ['code' => 'diesel',   'label' => 'Diesel',     'uses_fuel' => true,  'uses_battery' => false],
            ['code' => 'electric', 'label' => 'Électrique', 'uses_fuel' => false, 'uses_battery' => true],
            ['code' => 'hybrid',   'label' => 'Hybride',    'uses_fuel' => true,  'uses_battery' => true],
        ];

        foreach ($rows as $row) {
            DB::table('engine_types')->upsert($row, ['code'], ['label', 'uses_fuel', 'uses_battery']);
        }
    }
}
