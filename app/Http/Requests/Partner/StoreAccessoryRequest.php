<?php

namespace App\Http\Requests\Partner;

use App\Support\MargePartenaire;

class StoreAccessoryRequest extends \App\Http\Requests\StoreAccessoryRequest
{
    use AuthorizesPartnerCatalog;

    protected function partnerRouteParameter(): string
    {
        return 'accessory';
    }

    protected function champsDePrix(): array
    {
        return MargePartenaire::CHAMPS_ARTICLE;
    }

    protected function champDePrixNormal(): string
    {
        return 'selling_price';
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
        $this->verifierLaPromotion($validator);
    }
}
