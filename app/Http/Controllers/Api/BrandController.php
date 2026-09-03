<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Resources\VehicleModelResource;
use App\Models\Brand;
use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $brands = Brand::query()
            ->with('country')
            ->withCount('vehicleModels')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:80', Rule::unique('brands', 'name')],
            'country_id' => ['nullable', 'exists:countries,id'],
            'logo_path'  => ['nullable', 'string', 'max:255'],
            'oem_prefix' => ['nullable', 'string', 'max:20'],
        ]);

        return (new BrandResource(Brand::create($data)))->response()->setStatusCode(201);
    }

    public function show(Brand $brand): BrandResource
    {
        return new BrandResource($brand->load(['country', 'vehicleModels.trims']));
    }

    public function update(Request $request, Brand $brand): BrandResource
    {
        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:80', Rule::unique('brands', 'name')->ignore($brand->id)],
            'country_id' => ['nullable', 'exists:countries,id'],
            'logo_path'  => ['nullable', 'string', 'max:255'],
            'oem_prefix' => ['nullable', 'string', 'max:20'],
            'is_active'  => ['boolean'],
        ]);

        $brand->update($data);

        return new BrandResource($brand->refresh());
    }

    /** Modeles d'une marque. */
    public function models(Brand $brand)
    {
        return VehicleModelResource::collection($brand->vehicleModels()->with('trims')->orderBy('name')->get());
    }

    public function storeModel(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:80'],
            'generation'       => ['nullable', 'string', 'max:40'],
            'body_type'        => ['nullable', 'string', 'max:40'],
            'segment'          => ['nullable', 'string', 'max:10'],
            'production_start' => ['nullable', 'integer', 'min:1950'],
            'production_end'   => ['nullable', 'integer', 'min:1950', 'gte:production_start'],
        ]);

        $model = $brand->vehicleModels()->create($data);

        return (new VehicleModelResource($model))->response()->setStatusCode(201);
    }

    /** Ajout d'un niveau de finition a un modele. */
    public function storeTrim(Request $request, VehicleModel $vehicleModel): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:60'],
            'code'        => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string'],
            'rank'        => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        $trim = $vehicleModel->trims()->create($data);

        return response()->json(['data' => $trim], 201);
    }
}
