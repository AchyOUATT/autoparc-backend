<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Explicitly requested cleanup of current vehicle readings. New zero
        // readings saved after this one-time migration remain valid.
        DB::transaction(function () {
            $registered = fn () => DB::table('vehicle_registration_details')->where('mileage_km', 0)->select('vehicle_id');
            $imports = fn () => DB::table('vehicle_import_details')->where('odometer_at_import_km', 0)->select('vehicle_id');
            $private = fn () => DB::table('private_vehicle_listings')->where('mileage_km', 0)->select('id');
            $now = now();

            // A changed source must never silently revive an old inspection
            // if someone later records zero again. Keep the historical report.
            DB::table('staff_vehicle_inspections')->where('status', 'active')
                ->where(fn ($query) => $query
                    ->whereIn('vehicle_id', $registered())
                    ->orWhereIn('vehicle_id', $imports())
                    ->orWhereIn('private_vehicle_listing_id', $private()))
                ->update([
                    'status' => 'withdrawn', 'active_key' => null,
                    'withdrawn_at' => $now, 'updated_at' => $now,
                    'withdrawal_reason' => 'Legacy zero mileage cleared at the administrator request.',
                ]);

            DB::table('vehicles')->where(fn ($query) => $query
                ->whereIn('id', $registered())->orWhereIn('id', $imports()))
                ->update(['updated_at' => $now]);

            foreach ([
                'vehicle_registration_details' => 'mileage_km',
                'vehicle_import_details' => 'odometer_at_import_km',
                'private_vehicle_listings' => 'mileage_km',
                'owned_vehicles' => 'mileage_km',
            ] as $table => $column) {
                $values = [$column => null, 'updated_at' => $now];
                if ($table === 'private_vehicle_listings') {
                    $values['revision'] = DB::raw('revision + 1');
                }
                DB::table($table)->where($column, 0)->update($values);
            }
        });
    }

    public function down(): void
    {
        // Original zeros cannot be reconstructed from unknown readings.
        // Restoring them would invent measurements and revive withdrawn checks.
    }
};
