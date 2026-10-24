<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Api\VehicleController as StaffVehicleController;
use App\Http\Requests\Partner\StoreVehicleRequest;
use App\Http\Requests\Partner\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Support\PartnerOwnership;
use Illuminate\Http\Request;

class VehicleController extends CatalogController
{
    public function __construct(private readonly StaffVehicleController $catalog) {}

    protected function productClass(): string
    {
        return Vehicle::class;
    }

    protected function resourceClass(): string
    {
        return VehicleResource::class;
    }

    protected function relations(): array
    {
        return [
            'brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain', 'color', 'features',
            'importDetail.originCountry', 'importDetail.purchaseCountry', 'registrationDetail.registrationCountry',
        ];
    }

    public function store(StoreVehicleRequest $request)
    {
        return $this->catalog->store($request);
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        return $this->showProduct($request, $vehicle);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        PartnerOwnership::authorize($vehicle, $request->user());
        $this->catalog->update($request, $vehicle);

        return $this->showProduct($request, $vehicle->refresh());
    }

    public function destroy(Request $request, Vehicle $vehicle)
    {
        return $this->deleteProduct($request, $vehicle);
    }

    public function publish(Request $request, Vehicle $vehicle)
    {
        return $this->publishProduct($request, $vehicle, true);
    }

    public function unpublish(Request $request, Vehicle $vehicle)
    {
        return $this->publishProduct($request, $vehicle, false);
    }
}
