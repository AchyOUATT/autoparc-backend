<?php

namespace App\Http\Controllers\Api;

use App\Enums\FaultStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleFaultRequest;
use App\Http\Resources\VehicleFaultResource;
use App\Models\Vehicle;
use App\Models\VehicleFault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleFaultController extends Controller
{
    public function index(Request $request, Vehicle $vehicle)
    {
        $faults = $vehicle->faults()
            ->with('parts')
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->severity))
            ->latest('detected_at')
            ->get();

        return VehicleFaultResource::collection($faults);
    }

    public function store(StoreVehicleFaultRequest $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validated();

        $fault = DB::transaction(function () use ($vehicle, $data) {
            $fault = $vehicle->faults()->create(collect($data)->except('parts')->all());

            foreach ($data['parts'] ?? [] as $part) {
                $fault->parts()->attach($part['part_id'], ['quantity' => $part['quantity'] ?? 1]);
            }

            return $fault;
        });

        return (new VehicleFaultResource($fault->load('parts')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreVehicleFaultRequest $request, Vehicle $vehicle, VehicleFault $fault): VehicleFaultResource
    {
        abort_unless($fault->vehicle_id === $vehicle->id, 404);

        $data = $request->validated();
        $fault->update(collect($data)->except('parts')->all());

        if (array_key_exists('parts', $data)) {
            $sync = collect($data['parts'])->mapWithKeys(
                fn ($p) => [$p['part_id'] => ['quantity' => $p['quantity'] ?? 1]]
            );
            $fault->parts()->sync($sync);
        }

        return new VehicleFaultResource($fault->refresh()->load('parts'));
    }

    /** Cloture d'une panne apres reparation. */
    public function resolve(Request $request, Vehicle $vehicle, VehicleFault $fault): VehicleFaultResource
    {
        abort_unless($fault->vehicle_id === $vehicle->id, 404);

        $data = $request->validate([
            'actual_repair_cost' => ['nullable', 'numeric', 'min:0'],
            'repaired_at'        => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
        ]);

        $fault->update([
            'status'             => FaultStatus::Repaired,
            'actual_repair_cost' => $data['actual_repair_cost'] ?? $fault->actual_repair_cost,
            'repaired_at'        => $data['repaired_at'] ?? now()->toDateString(),
        ]);

        return new VehicleFaultResource($fault->refresh());
    }

    public function destroy(Vehicle $vehicle, VehicleFault $fault): JsonResponse
    {
        abort_unless($fault->vehicle_id === $vehicle->id, 404);

        $fault->delete();

        return response()->json(['message' => 'Panne supprimee.']);
    }
}
