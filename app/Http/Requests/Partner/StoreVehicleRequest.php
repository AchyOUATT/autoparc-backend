<?php

namespace App\Http\Requests\Partner;

use App\Support\MargePartenaire;

class StoreVehicleRequest extends \App\Http\Requests\StoreVehicleRequest
{
    use AuthorizesPartnerCatalog;

    protected function partnerRouteParameter(): string
    {
        return 'vehicle';
    }

    protected function champsDePrix(): array
    {
        return MargePartenaire::CHAMPS_VEHICULE;
    }

    protected function champDePrixNormal(): string
    {
        return 'sale_price';
    }

    public function rules(): array
    {
        return array_replace(
            parent::rules(),
            $this->partnerOwnershipRules(),
            $this->reglesDePromotion(),
        );
    }

    public function withValidator($validator): void
    {
        parent::withValidator($validator);

        $this->verifierLaPromotion($validator);
    }
}
