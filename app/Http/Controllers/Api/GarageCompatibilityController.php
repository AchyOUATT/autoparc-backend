<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Services\CompatibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GarageCompatibilityController extends Controller
{
    public function __construct(private readonly CompatibilityService $svc) {}

    /**
     * Pour chaque véhicule du garage du client connecté, indique si la pièce
     * demandée est compatible selon les fitments déclarés.
     *
     * GET /my/parts/{part}/garage-compatibility
     *
     * Réponse :
     *   { data: [{ vehicle_id, display_name, year, compatible, compatibility_ready }] }
     *
     * Logique de compatibilité :
     *   - compatible = true  → au moins un fitment correspond au véhicule
     *   - compatible = false + compatibility_ready = true  → données précises mais aucun fitment
     *   - compatible = false + compatibility_ready = false → données incomplètes, résultat incertain
     */
    public function checkPart(Request $request, Part $part): JsonResponse
    {
        $vehicles = OwnedVehicle::where('user_id', $request->user()->id)
            ->with(['brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain'])
            ->get();

        $results = $vehicles->map(fn (OwnedVehicle $vehicle) => [
            'vehicle_id'          => $vehicle->id,
            'display_name'        => $vehicle->nickname ?: $vehicle->designation,
            'year'                => $vehicle->manufacturing_year,
            'compatible'          => $this->svc
                ->partsForOwnedVehicle($vehicle)
                ->where('parts.id', $part->id)
                ->exists(),
            'compatibility_ready' => $vehicle->hasPreciseEngineData(),
        ]);

        return response()->json(['data' => $results]);
    }
}
