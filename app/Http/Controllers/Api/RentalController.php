<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRentalRequest;
use App\Http\Resources\RentalResource;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Services\RentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentalController extends Controller
{
    public function __construct(protected RentalService $rentals)
    {
    }

    public function index(Request $request)
    {
        $rentals = Rental::query()
            ->with(['customer', 'vehicle.brand', 'vehicle.vehicleModel'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->when($request->filled(['from', 'to']), fn ($q) => $q->overlapping($request->from, $request->to))
            ->latest('start_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return RentalResource::collection($rentals);
    }

    public function store(StoreRentalRequest $request): JsonResponse
    {
        $customer = Customer::findOrFail($request->customer_id);

        if (! $request->boolean('with_driver') && ! $customer->canRent()) {
            return response()->json([
                'message' => 'Client non eligible : permis manquant, expire ou client sur liste noire.',
            ], 422);
        }

        $rental = $this->rentals->reserve(array_merge($request->validated(), [
            'handled_by' => auth()->id(),
        ]));

        return (new RentalResource($rental->load(['customer', 'vehicle'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Rental $rental): RentalResource
    {
        return new RentalResource($rental->load(['customer', 'vehicle.brand', 'vehicle.vehicleModel', 'payments']));
    }

    /** Remise des cles. */
    public function checkout(Request $request, Rental $rental): RentalResource
    {
        $data = $request->validate([
            'mileage_start_km' => ['nullable', 'integer', 'min:0'],
            'fuel_level_start' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $rental = $this->rentals->checkout(
            $rental,
            $data['mileage_start_km'] ?? null,
            $data['fuel_level_start'] ?? null
        );

        return new RentalResource($rental->refresh()->load(['customer', 'vehicle']));
    }

    /** Restitution du vehicule. */
    public function checkin(Request $request, Rental $rental): RentalResource
    {
        $data = $request->validate([
            'actual_return_at' => ['nullable', 'date'],
            'mileage_end_km'   => ['nullable', 'integer', 'min:0'],
            'fuel_level_end'   => ['nullable', 'integer', 'min:0', 'max:100'],
            'extra_charges'    => ['nullable', 'numeric', 'min:0'],
            'checkin_notes'    => ['nullable', 'string'],
        ]);

        $rental = $this->rentals->checkin($rental, $data);

        return new RentalResource($rental->refresh()->load(['customer', 'vehicle']));
    }

    /** Disponibilite d'un vehicule sur une periode. */
    public function availability(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'start_at' => ['required', 'date'],
            'end_at'   => ['required', 'date', 'after:start_at'],
        ]);

        return response()->json([
            'vehicle_id' => $vehicle->id,
            'available'  => $vehicle->isAvailableForRentBetween($data['start_at'], $data['end_at']),
            'conflicts'  => RentalResource::collection(
                $vehicle->rentals()->active()->overlapping($data['start_at'], $data['end_at'])->get()
            ),
        ]);
    }
}
