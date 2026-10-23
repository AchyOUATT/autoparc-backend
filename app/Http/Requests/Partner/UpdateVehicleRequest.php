<?php

namespace App\Http\Requests\Partner;

class UpdateVehicleRequest extends \App\Http\Requests\UpdateVehicleRequest
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
