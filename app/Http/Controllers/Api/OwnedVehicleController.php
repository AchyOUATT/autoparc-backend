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

    // dernierControle est charge par latestOfMany : une seule requete pour toute
    // la liste, au lieu d'une par vehicule pour trouver son dernier passage.
    //
    // fuelFills est la seule relation chargee EN ENTIER, et il le faut : la cle
    // 'fuel_tracking' de la ressource repose sur la chaine complete des
    // passages — un intervalle se calcule de proche en proche depuis le premier
    // plein, on ne peut pas n'en charger que les derniers. Elle doit figurer
    // ici, c'est-a-dire sur index, show, store et update a la fois : omise d'un
    // seul de ces quatre points, la cle resterait juste partout ailleurs et le
    // garage ferait silencieusement une requete par vehicule.
    protected array $relations = ['brand', 'vehicleModel', 'trim', 'engineType', 'motorisation', 'drivetrain', 'color', 'dernierControle', 'fuelFills'];

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
