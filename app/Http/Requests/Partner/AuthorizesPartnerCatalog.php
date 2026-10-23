<?php

namespace App\Http\Requests\Partner;

use App\Support\PartnerOwnership;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesPartnerCatalog
{
    abstract protected function partnerRouteParameter(): string;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        PartnerOwnership::accountId($user);
        $product = $this->route($this->partnerRouteParameter());
        if ($product instanceof Model) {
            PartnerOwnership::authorize($product, $user);
        }

        return true;
    }

    protected function partnerOwnershipRules(): array
    {
        // `missing` rejects even an explicit null: only the server chooses ownership/publication.
        return array_fill_keys([
            'owner_partner_account_id', 'partner_published_at', 'published_at',
            'created_by', 'user_id', 'location_id', 'partner_id',
        ], ['missing']);
    }
}
