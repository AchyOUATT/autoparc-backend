<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Api\PartController as StaffPartController;
use App\Http\Requests\Partner\StorePartRequest;
use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Support\PartnerOwnership;
use Illuminate\Http\Request;

class PartController extends CatalogController
{
    public function __construct(private readonly StaffPartController $catalog) {}

    protected function productClass(): string
    {
        return Part::class;
    }

    protected function resourceClass(): string
    {
        return PartResource::class;
    }

    protected function relations(): array
    {
        return ['category', 'manufacturer', 'oemNumbers', 'fitments.vehicleModel.brand', 'fitments.trim', 'fitments.engineType', 'donorVehicle'];
    }

    public function store(StorePartRequest $request)
    {
        return $this->catalog->store($request);
    }

    public function show(Request $request, Part $part)
    {
        return $this->showProduct($request, $part);
    }

    public function update(StorePartRequest $request, Part $part)
    {
        PartnerOwnership::authorize($part, $request->user());
        $this->catalog->update($request, $part);

        return $this->showProduct($request, $part->refresh());
    }

    public function destroy(Request $request, Part $part)
    {
        return $this->deleteProduct($request, $part);
    }

    public function publish(Request $request, Part $part)
    {
        return $this->publishProduct($request, $part, true);
    }

    public function unpublish(Request $request, Part $part)
    {
        return $this->publishProduct($request, $part, false);
    }
}
