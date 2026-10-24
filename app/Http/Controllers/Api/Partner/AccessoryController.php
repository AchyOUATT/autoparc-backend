<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Api\AccessoryController as StaffAccessoryController;
use App\Http\Requests\Partner\StoreAccessoryRequest;
use App\Http\Resources\AccessoryResource;
use App\Models\Accessory;
use App\Support\PartnerOwnership;
use Illuminate\Http\Request;

class AccessoryController extends CatalogController
{
    public function __construct(private readonly StaffAccessoryController $catalog) {}

    protected function productClass(): string
    {
        return Accessory::class;
    }

    protected function resourceClass(): string
    {
        return AccessoryResource::class;
    }

    protected function relations(): array
    {
        return ['manufacturer', 'fitments.vehicleModel.brand', 'fitments.trim', 'fitments.engineType'];
    }

    public function store(StoreAccessoryRequest $request)
    {
        return $this->catalog->store($request);
    }

    public function show(Request $request, Accessory $accessory)
    {
        return $this->showProduct($request, $accessory);
    }

    public function update(StoreAccessoryRequest $request, Accessory $accessory)
    {
        PartnerOwnership::authorize($accessory, $request->user());
        $this->catalog->update($request, $accessory);

        return $this->showProduct($request, $accessory->refresh());
    }

    public function destroy(Request $request, Accessory $accessory)
    {
        return $this->deleteProduct($request, $accessory);
    }

    public function publish(Request $request, Accessory $accessory)
    {
        return $this->publishProduct($request, $accessory, true);
    }

    public function unpublish(Request $request, Accessory $accessory)
    {
        return $this->publishProduct($request, $accessory, false);
    }
}
