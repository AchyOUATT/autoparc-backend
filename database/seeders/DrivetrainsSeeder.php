<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DrivetrainsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'FWD', 'label' => 'Traction avant'],
            ['code' => 'RWD', 'label' => 'Propulsion arrière'],
            ['code' => 'AWD', 'label' => 'Transmission intégrale permanente'],
            ['code' => '4WD', 'label' => '4x4 débrayable'],
        ];

        foreach ($rows as $row) {
            DB::table('drivetrains')->upsert($row, ['code'], ['label']);
        }
    }
}
