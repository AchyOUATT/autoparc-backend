<?php

namespace App\Http\Controllers\Api;

use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\Vehicle;
use App\Services\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(protected ReferenceGenerator $references)
    {
    }

    public function index(Request $request)
    {
        $sales = Sale::query()
            ->with(['customer', 'vehicle.brand', 'vehicle.vehicleModel'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sold_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sold_at', '<=', $request->to))
            ->latest('sold_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return SaleResource::collection($sales);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $vehicle = Vehicle::with('faults')->findOrFail($data['vehicle_id']);

        if (! $vehicle->availability->allowsSale()) {
            return response()->json(['message' => "Ce vehicule n'est pas propose a la vente."], 422);
        }

        if ($vehicle->status === VehicleStatus::Sold) {
            return response()->json(['message' => 'Ce vehicule est deja vendu.'], 422);
        }

        // Obligation d'information : pannes ouvertes non divulguees.
        $undisclosed = $vehicle->faults->filter(
            fn ($f) => $f->status->isOpen() && ! $f->disclosed_to_buyer
        );

        if ($undisclosed->isNotEmpty() && ! ($data['faults_disclosed'] ?? false)) {
            return response()->json([
                'message' => 'Des pannes ouvertes ne sont pas encore declarees a l\'acheteur.',
                'faults'  => $undisclosed->pluck('title'),
            ], 422);
        }

        $sale = DB::transaction(function () use ($data, $vehicle) {
            $total = $data['agreed_price']
                - ($data['discount'] ?? 0)
                + ($data['tax_amount'] ?? 0)
                + ($data['registration_fees'] ?? 0);

            $sale = Sale::create(array_merge($data, [
                'reference'    => $this->references->next('VTE'),
                'total_amount' => $total,
                'currency'     => $vehicle->currency,
                'sold_by'      => auth()->id(),
            ]));

            $vehicle->update(['status' => VehicleStatus::Sold]);

            return $sale;
        });

        return (new SaleResource($sale->load(['customer', 'vehicle'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($sale->load(['customer', 'vehicle.brand', 'vehicle.vehicleModel', 'payments']));
    }
}
