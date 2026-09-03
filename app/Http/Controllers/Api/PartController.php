<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartRequest;
use App\Http\Resources\PartResource;
use App\Models\OemNumber;
use App\Models\Part;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartController extends Controller
{
    public function __construct(protected StockService $stock)
    {
    }

    public function index(Request $request)
    {
        $parts = Part::query()
            ->with(['category', 'manufacturer', 'oemNumbers', 'location'])
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('oem'), fn ($q) => $q->matchingOem($request->string('oem')->toString()))
            ->when($request->filled('category_id'), fn ($q) => $q->where('part_category_id', $request->category_id))
            ->when($request->filled('manufacturer_id'), fn ($q) => $q->where('manufacturer_id', $request->manufacturer_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('condition'), fn ($q) => $q->where('condition', $request->condition))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('is_available', true))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->filled('price_max'), fn ($q) => $q->where('selling_price', '<=', $request->price_max))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->orderBy($request->input('sort_by', 'name'), $request->input('sort_dir', 'asc'))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return PartResource::collection($parts);
    }

    public function store(StorePartRequest $request): JsonResponse
    {
        $data = $request->validated();

        $part = DB::transaction(function () use ($data) {
            $part = Part::create(collect($data)->except(['oem_numbers', 'fitments'])->all());

            $this->syncOemNumbers($part, $data['oem_numbers'] ?? []);

            foreach ($data['fitments'] ?? [] as $fitment) {
                $part->fitments()->create($fitment);
            }

            return $part;
        });

        return (new PartResource($part->load(['category', 'manufacturer', 'oemNumbers', 'fitments'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Part $part): PartResource
    {
        return new PartResource($part->load([
            'category', 'manufacturer', 'oemNumbers.brand', 'oemNumbers.fitments.vehicleModel.brand',
            'fitments.vehicleModel.brand', 'fitments.trim', 'fitments.engineType', 'donorVehicle', 'media',
        ]));
    }

    public function update(StorePartRequest $request, Part $part): PartResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($part, $data) {
            $part->update(collect($data)->except(['oem_numbers', 'fitments'])->all());

            if (array_key_exists('oem_numbers', $data)) {
                $this->syncOemNumbers($part, $data['oem_numbers'], replace: true);
            }

            if (array_key_exists('fitments', $data)) {
                $part->fitments()->delete();
                foreach ($data['fitments'] as $fitment) {
                    $part->fitments()->create($fitment);
                }
            }
        });

        return new PartResource($part->refresh()->load(['category', 'manufacturer', 'oemNumbers', 'fitments']));
    }

    /** Bascule la disponibilité (disponible ↔ indisponible). */
    public function toggleAvailability(Part $part): JsonResponse
    {
        $part->update(['is_available' => ! $part->is_available]);

        return response()->json([
            'id'           => $part->id,
            'is_available' => $part->is_available,
        ]);
    }

    public function destroy(Part $part): JsonResponse
    {
        $part->delete();

        return response()->json(['message' => 'Piece archivee.']);
    }

    /** Entree, sortie ou correction de stock. */
    public function adjustStock(Request $request, Part $part): PartResource
    {
        $data = $request->validate([
            'operation' => ['required', 'in:increase,decrease,set'],
            'quantity'  => ['required', 'integer', 'min:0'],
            'reason'    => ['nullable', 'string', 'max:180'],
        ]);

        match ($data['operation']) {
            'increase' => $this->stock->increase($part, $data['quantity'], $data['reason'] ?? null),
            'decrease' => $this->stock->decrease($part, $data['quantity'], $data['reason'] ?? null),
            'set'      => $this->stock->adjust($part, $data['quantity'], $data['reason'] ?? null),
        };

        return new PartResource($part->refresh());
    }

    /**
     * Cree ou reutilise les numeros OEM puis les rattache a la piece.
     * La normalisation garantit que 90915-YZZD4 et 90915YZZD4 pointent sur la meme entree.
     */
    protected function syncOemNumbers(Part $part, array $oemNumbers, bool $replace = false): void
    {
        $sync = [];

        foreach ($oemNumbers as $entry) {
            $oem = OemNumber::firstOrCreate(
                [
                    'normalized_number' => OemNumber::normalize($entry['number']),
                    'brand_id'          => $entry['brand_id'] ?? null,
                ],
                [
                    'number' => $entry['number'],
                    'label'  => $entry['label'] ?? null,
                ]
            );

            $sync[$oem->id] = ['is_primary' => $entry['is_primary'] ?? false];
        }

        $replace ? $part->oemNumbers()->sync($sync) : $part->oemNumbers()->syncWithoutDetaching($sync);
    }
}
