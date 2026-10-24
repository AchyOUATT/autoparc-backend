<?php

namespace App\Http\Requests\Partner;

class StoreVehicleRequest extends \App\Http\Requests\StoreVehicleRequest
{
    use AuthorizesPartnerCatalog;

    protected function partnerRouteParameter(): string
    {
        return 'vehicle';
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), $this->partnerOwnershipRules());
    }
}
