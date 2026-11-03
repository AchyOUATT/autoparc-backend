<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffVehicleInspectionRequest;
use App\Http\Resources\StaffVehicleInspectionManagementResource;
use App\Http\Resources\StaffVehicleInspectionResource;
use App\Models\PrivateVehicleListing;
use App\Models\StaffVehicleInspection;
use App\Models\Vehicle;
use App\Services\StaffVehicleInspections;
use Illuminate\Http\Request;

class StaffVehicleInspectionController extends Controller
{
    public function index(Request $request, string $source, int $sourceId, StaffVehicleInspections $inspections)
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        $target = $inspections->target($source, $sourceId);
        $page = $target->staffInspections()->with(['actor', 'withdrawnBy'])
            ->latest('id')->paginate($data['per_page'] ?? 20);
        $page->getCollection()->each(function ($inspection) use ($target) { $inspection->inspectionTarget = $target; });

        return StaffVehicleInspectionManagementResource::collection($page);
    }

    public function store(StoreStaffVehicleInspectionRequest $request, string $source, int $sourceId, StaffVehicleInspections $inspections)
    {
        return (new StaffVehicleInspectionManagementResource(
            $inspections->create($source, $sourceId, $request->validated(), $request->user())
        ))->response()->setStatusCode(201);
    }

    public function withdraw(Request $request, StaffVehicleInspection $inspection, StaffVehicleInspections $inspections)
    {
        $inspections->target($inspection->source(), $inspection->sourceId());
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return new StaffVehicleInspectionManagementResource($inspections->withdraw($inspection, $data['reason'], $request->user()));
    }

    public function show(StaffVehicleInspection $inspection)
    {
        if ($inspection->source() === 'private_listing') {
            abort_unless(config('private_sales.enabled'), 404);
            $target = PrivateVehicleListing::published()->findOrFail($inspection->sourceId());
        } else {
            $target = Vehicle::visibleToPublic()->findOrFail($inspection->sourceId());
        }
        abort_unless(StaffVehicleInspections::isCurrent($inspection, $target), 404);

        return (new StaffVehicleInspectionResource($inspection))->response()->header('Cache-Control', 'no-store');
    }
}
