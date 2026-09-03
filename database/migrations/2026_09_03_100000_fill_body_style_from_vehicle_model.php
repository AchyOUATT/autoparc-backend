<?php

use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Déduit body_style de vehicle_models.body_type pour les véhicules existants. */
    public function up(): void
    {
        // Cross-db compatible : utilise Eloquent plutôt que SQL dialectal
        Vehicle::with('vehicleModel')->get()->each(function (Vehicle $v) {
            $bt    = strtolower($v->vehicleModel?->body_type ?? '');
            $style = match (true) {
                $bt === 'berline'                             => 'sedan',
                in_array($bt, ['citadine', 'compacte'])       => 'hatchback',
                $bt === 'suv'                                 => 'suv',
                $bt === 'pick-up'                             => 'pickup',
                in_array($bt, ['utilitaire', 'monospace'])    => 'van',
                $bt === 'break'                               => 'estate',
                str_contains($bt, 'tout') || $bt === 'terrain'=> 'suv',
                default                                       => null,
            };
            if ($style !== null) {
                $v->updateQuietly(['body_style' => $style]);
            }
        });
    }

    public function down(): void
    {
        Vehicle::query()->update(['body_style' => null]);
    }
};
