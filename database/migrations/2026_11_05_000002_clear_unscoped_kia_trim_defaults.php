<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The original four QL rows mixed market-specific powertrains into
        // global trim defaults. See docs/kia-catalogue.md and the US MY2020
        // Kia features table: even the drivetrain varies within a trim.
        // Correct only the exact seeded pairs, retaining custom corrections,
        // trim IDs, applications and every explicit vehicle characteristic.
        DB::transaction(function () {
            $modelIds = DB::table('vehicle_models')
                ->whereIn('brand_id', DB::table('brands')->where('slug', 'kia')->select('id'))
                ->where('slug', 'sportage-ql')->pluck('id');

            foreach ([['LX', 'LX', 'petrol', 'FWD'], ['EX', 'EX', 'petrol', 'FWD'],
                ['SX', 'SX', 'diesel', 'AWD'], ['GT Line', 'GTL', 'diesel', 'AWD']] as [$name, $code, $engine, $drive]) {
                $engineId = DB::table('engine_types')->where('code', $engine)->value('id');
                $driveId = DB::table('drivetrains')->where('code', $drive)->value('id');

                if ($engineId === null || $driveId === null) {
                    continue;
                }

                DB::table('trims')->whereIn('vehicle_model_id', $modelIds)
                    ->where('name', $name)->where('code', $code)
                    ->where('default_engine_type_id', $engineId)->where('default_drivetrain_id', $driveId)
                    ->update(['default_engine_type_id' => null, 'default_drivetrain_id' => null, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Restoring unsupported defaults would manufacture technical information.
    }
};
