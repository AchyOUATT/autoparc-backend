<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OemNumberResource;
use App\Http\Resources\PartResource;
use App\Models\OemNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OemNumberController extends Controller
{
    public function index(Request $request)
    {
        $query = OemNumber::query()->with(['brand', 'fitments.vehicleModel.brand']);

        if ($request->filled('q')) {
            $query->where('normalized_number', 'like', '%'.OemNumber::normalize($request->string('q')->toString()).'%');
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->filled('vehicle_model_id')) {
            $query->whereHas('fitments', fn ($f) => $f->where('vehicle_model_id', $request->vehicle_model_id));
        }

        return OemNumberResource::collection(
            $query->paginate($request->integer('per_page', 20))->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'                     => ['required', 'string', 'max:60'],
            'brand_id'                   => ['nullable', 'exists:brands,id'],
            'label'                      => ['nullable', 'string', 'max:150'],
            'superseded_by_id'           => ['nullable', 'exists:oem_numbers,id'],
            'fitments'                   => ['array'],
            'fitments.*.vehicle_model_id' => ['required_with:fitments', 'exists:vehicle_models,id'],
            'fitments.*.trim_id'         => ['nullable', 'exists:trims,id'],
            'fitments.*.engine_type_id'  => ['nullable', 'exists:engine_types,id'],
            'fitments.*.drivetrain_id'   => ['nullable', 'exists:drivetrains,id'],
            'fitments.*.engine_code'     => ['nullable', 'string', 'max:50'],
            'fitments.*.year_from'       => ['nullable', 'integer', 'min:1950'],
            'fitments.*.year_to'         => ['nullable', 'integer', 'min:1950'],
            'fitments.*.position'        => ['nullable', 'string', 'max:60'],
        ]);

        $oem = OemNumber::firstOrCreate(
            [
                'normalized_number' => OemNumber::normalize($data['number']),
                'brand_id'          => $data['brand_id'] ?? null,
            ],
            collect($data)->except('fitments')->all()
        );

        foreach ($data['fitments'] ?? [] as $fitment) {
            $oem->fitments()->create($fitment);
        }

        return (new OemNumberResource($oem->load(['brand', 'fitments.vehicleModel'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(OemNumber $oemNumber): OemNumberResource
    {
        return new OemNumberResource($oemNumber->load([
            'brand', 'supersededBy', 'fitments.vehicleModel.brand', 'fitments.trim', 'fitments.engineType',
        ]));
    }

    /** Pieces disponibles pour un numero OEM. */
    public function parts(OemNumber $oemNumber)
    {
        return PartResource::collection(
            $oemNumber->currentReference()
                ->parts()
                ->with(['category', 'manufacturer'])
                ->get()
        );
    }

    /** Declare qu'une reference est remplacee par une autre. */
    public function supersede(Request $request, OemNumber $oemNumber): OemNumberResource
    {
        $data = $request->validate([
            'superseded_by_id' => ['required', 'exists:oem_numbers,id', 'different:'.$oemNumber->id],
        ]);

        $oemNumber->update([
            'is_superseded'    => true,
            'superseded_by_id' => $data['superseded_by_id'],
        ]);

        return new OemNumberResource($oemNumber->refresh()->load('supersededBy'));
    }
}
