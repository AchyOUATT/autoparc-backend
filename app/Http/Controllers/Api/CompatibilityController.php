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
use App\Services\PartCompatibilityAssessment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** Passerelle vehicules <-> pieces detachees via les numeros OEM. */
class CompatibilityController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function __construct(
        protected CompatibilityService $compatibility,
        protected PartCompatibilityAssessment $assessment,
    )
    {
    }

    /** GET /api/my/vehicles/{ownedVehicle}/compatible-parts */
    public function partsForOwnedVehicle(CompatiblePartsRequest $request, OwnedVehicle $ownedVehicle)
    {
        $this->authorize('view', $ownedVehicle);

        $parts = $this->compatibility
            ->partsForOwnedVehicle($ownedVehicle, $request->filters());

        return $this->paginateAssessed($parts, $this->compatibility->ownedVehicleCriteria($ownedVehicle), $request);
    }

    /** GET /api/vehicles/{vehicle}/compatible-parts */
    public function partsForVehicle(CompatiblePartsRequest $request, Vehicle $vehicle)
    {
        $parts = $this->compatibility
            ->partsForVehicle($vehicle, $request->filters());

        return $this->paginateAssessed($parts, $this->compatibility->vehicleCriteria($vehicle), $request);
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
        );

        return $this->paginateAssessed($parts, $data, $request);
    }

    private function paginateAssessed(Builder $parts, array $criteria, CompatiblePartsRequest $request)
    {
        if (! $request->user()?->isStaff()) {
            $parts->visibleToPublic();
        }

        $parts->with('sellerAccount');

        if (! $request->includeCandidates()) {
            $this->assessment->constrainConfirmed($parts, $criteria);
        }

        return $this->withAssessments($parts->paginate($request->perPage())->withQueryString(), $criteria);
    }

    private function withAssessments(LengthAwarePaginator $parts, array $criteria)
    {
        $models = $parts->getCollection();
        $models->loadMissing(['fitments', 'oemNumbers.fitments']);

        foreach ($models as $part) {
            $part->setAttribute('compatibility_assessment', $this->assessment->assess($part, $criteria));
        }

        // Evidence is fetched in batches for the assessment. It must not turn
        // these paginated lists into full fitment exports or trigger N+1 loads.
        foreach ($models as $part) {
            $part->unsetRelation('fitments');
            foreach ($part->oemNumbers as $oem) {
                $oem->unsetRelation('fitments');
            }
        }

        return PartResource::collection($parts);
    }

    /** GET /api/oem/{number}/vehicles : vehicules du parc concernes par une reference OEM. */
    public function vehiclesForOem(string $number)
    {
        $vehicles = $this->compatibility->vehiclesForOem($number);

        if (! request()->user()?->isStaff()) {
            $visibleIds = Vehicle::visibleToPublic()->whereKey($vehicles->modelKeys())->pluck('id');
            $vehicles = $vehicles->whereIn('id', $visibleIds);
        }

        return VehicleResource::collection($vehicles->loadMissing('sellerAccount'));
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
