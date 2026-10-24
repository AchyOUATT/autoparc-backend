<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Services\CompatibilityService;
use App\Services\PartCompatibilityAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GarageCompatibilityController extends Controller
{
    public function __construct(
        private readonly CompatibilityService $svc,
        private readonly PartCompatibilityAssessment $assessment,
    ) {}

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
     *   - confirmed → toutes les contraintes d'une déclaration correspondent
     *   - candidate → des informations ou une vérification manquent
     *   - unknown   → aucune déclaration applicable ne permet de conclure
     * Les anciens booléens ne deviennent vrais que pour confirmed.
     */
    public function checkPart(Request $request, Part $part): JsonResponse
    {
        abort_unless(Part::visibleToPublic()->whereKey($part->id)->exists(), 404);

        $vehicles = OwnedVehicle::where('user_id', $request->user()->id)
            ->with(['brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain'])
            ->get();

        $part->loadMissing(['fitments', 'oemNumbers.fitments']);

        $results = $vehicles->map(fn (OwnedVehicle $vehicle) => array_merge([
            'vehicle_id'          => $vehicle->id,
            'display_name'        => $vehicle->nickname ?: $vehicle->designation,
            'year'                => $vehicle->manufacturing_year,
        ], $this->assessment->assess($part, $this->svc->ownedVehicleCriteria($vehicle))));

        return response()->json(['data' => $results]);
    }
}
