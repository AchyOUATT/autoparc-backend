<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompatiblePartsCriteriaRequest;
use App\Http\Requests\CompatiblePartsRequest;
use App\Http\Resources\PartResource;
use App\Http\Resources\VehicleModelResource;
use App\Http\Resources\VehicleResource;
use App\Http\Resources\OemNumberResource;
use App\Models\OwnedVehicle;
use App\Models\Vehicle;
use App\Services\CompatibilityService;

/** Passerelle vehicules <-> pieces detachees via les numeros OEM. */
class CompatibilityController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(protected CompatibilityService $compatibility)
    {
    }

    /** GET /api/my/vehicles/{ownedVehicle}/compatible-parts */
    public function partsForOwnedVehicle(CompatiblePartsRequest $request, OwnedVehicle $ownedVehicle)
    {
        $this->authorize('view', $ownedVehicle);

        $parts = $this->compatibility
            ->partsForOwnedVehicle($ownedVehicle, $request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return PartResource::collection($parts);
    }

    /** GET /api/vehicles/{vehicle}/compatible-parts */
    public function partsForVehicle(CompatiblePartsRequest $request, Vehicle $vehicle)
    {
        $parts = $this->compatibility
            ->partsForVehicle($vehicle, $request->filters())
            ->paginate($request->perPage())
            ->withQueryString();

        return PartResource::collection($parts);
    }

    /** GET /api/catalog/compatible-parts?vehicle_model_id=..&year=.. (recherche sans vehicule du parc) */
    public function partsForCriteria(CompatiblePartsCriteriaRequest $request)
    {
        $data = $request->validated();

        $parts = $this->compatibility->partsForCriteria(
            $data['vehicle_model_id'],
            $data['year'] ?? null,
            $data['trim_id'] ?? null,
            $data['engine_type_id'] ?? null,
            $data['drivetrain_id'] ?? null,
            // Le sixieme argument existait depuis le debut et n'etait jamais
            // passe : le service savait filtrer par code moteur, la route ne
            // le lui demandait pas. Mesure avant correction, Camry XV70 de
            // 2019 : les DEUX filtres rendus, la cartouche du 2GR-FKS et le
            // visse de l'A25A. Voir CompatiblePartsCriteriaRequest.
            $data['engine_code'] ?? null
        )->paginate($request->perPage())->withQueryString();

        return PartResource::collection($parts);
    }

    /** GET /api/oem/{number}/vehicles : vehicules du parc concernes par une reference OEM. */
    public function vehiclesForOem(string $number)
    {
        return VehicleResource::collection($this->compatibility->vehiclesForOem($number));
    }

    /** GET /api/oem/{number}/models : modeles couverts par une reference OEM. */
    public function modelsForOem(string $number)
    {
        return VehicleModelResource::collection($this->compatibility->modelsForOem($number));
    }

    /** GET /api/oem/{number}/cross-references : references equivalentes. */
    public function crossReferences(string $number)
    {
        return OemNumberResource::collection($this->compatibility->crossReferences($number));
    }
}
