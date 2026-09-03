<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Http\Resources\VehicleModelResource;
use App\Http\Resources\VehicleResource;
use App\Http\Resources\OemNumberResource;
use App\Models\OwnedVehicle;
use App\Models\Vehicle;
use App\Services\CompatibilityService;
use Illuminate\Http\Request;

/** Passerelle vehicules <-> pieces detachees via les numeros OEM. */
class CompatibilityController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(protected CompatibilityService $compatibility)
    {
    }

    /** GET /api/my/vehicles/{ownedVehicle}/compatible-parts */
    public function partsForOwnedVehicle(Request $request, OwnedVehicle $ownedVehicle)
    {
        $this->authorize('view', $ownedVehicle);

        $parts = $this->compatibility
            ->partsForOwnedVehicle($ownedVehicle, $request->only(['category_id', 'in_stock', 'condition']))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return PartResource::collection($parts);
    }

    /** GET /api/vehicles/{vehicle}/compatible-parts */
    public function partsForVehicle(Request $request, Vehicle $vehicle)
    {
        $parts = $this->compatibility
            ->partsForVehicle($vehicle, $request->only(['category_id', 'in_stock', 'condition']))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return PartResource::collection($parts);
    }

    /** GET /api/catalog/parts?vehicle_model_id=..&year=.. (recherche sans vehicule du parc) */
    public function partsForCriteria(Request $request)
    {
        $data = $request->validate([
            'vehicle_model_id' => ['required', 'exists:vehicle_models,id'],
            'year'             => ['nullable', 'integer', 'min:1950'],
            'trim_id'          => ['nullable', 'exists:trims,id'],
            'engine_type_id'   => ['nullable', 'exists:engine_types,id'],
            'drivetrain_id'    => ['nullable', 'exists:drivetrains,id'],
        ]);

        $parts = $this->compatibility->partsForCriteria(
            $data['vehicle_model_id'],
            $data['year'] ?? null,
            $data['trim_id'] ?? null,
            $data['engine_type_id'] ?? null,
            $data['drivetrain_id'] ?? null
        )->paginate($request->integer('per_page', 20))->withQueryString();

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
