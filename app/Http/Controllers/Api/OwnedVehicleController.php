<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOwnedVehicleRequest;
use App\Http\Requests\UpdateOwnedVehicleRequest;
use App\Http\Resources\OwnedVehicleResource;
use App\Models\OwnedVehicle;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Mon garage" : un client gere ses propres vehicules (jamais ceux des autres). */
class OwnedVehicleController extends Controller
{
    use AuthorizesRequests;

    protected array $relations = ['brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain', 'color'];

    public function index(Request $request)
    {
        $vehicles = OwnedVehicle::query()
            ->ownedBy($request->user()->id)
            ->with($this->relations)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return OwnedVehicleResource::collection($vehicles);
    }

    public function store(StoreOwnedVehicleRequest $request): JsonResponse
    {
        $this->authorize('create', OwnedVehicle::class);

        $vehicle = OwnedVehicle::create(array_merge(
            $request->validated(),
            ['user_id' => $request->user()->id]
        ));

        return (new OwnedVehicleResource($vehicle->load($this->relations)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(OwnedVehicle $ownedVehicle): OwnedVehicleResource
    {
        $this->authorize('view', $ownedVehicle);

        return new OwnedVehicleResource($ownedVehicle->load($this->relations));
    }

    public function update(UpdateOwnedVehicleRequest $request, OwnedVehicle $ownedVehicle): OwnedVehicleResource
    {
        $this->authorize('update', $ownedVehicle);

        $ownedVehicle->update($request->validated());

        return new OwnedVehicleResource($ownedVehicle->refresh()->load($this->relations));
    }

    public function destroy(OwnedVehicle $ownedVehicle): JsonResponse
    {
        $this->authorize('delete', $ownedVehicle);

        $ownedVehicle->delete();

        return response()->json(['message' => 'Vehicule retire de votre garage.']);
    }
}
