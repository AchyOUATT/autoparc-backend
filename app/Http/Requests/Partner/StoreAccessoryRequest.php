<?php

namespace App\Http\Requests\Partner;

class StoreAccessoryRequest extends \App\Http\Requests\StoreAccessoryRequest
{
    use AuthorizesPartnerCatalog;

    protected function partnerRouteParameter(): string
    {
        return 'accessory';
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), $this->partnerOwnershipRules());
    }
}
