<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class PartnerOwnership
{
    public static function accountId(User $user): int
    {
        $account = $user->partnerAccount;
        abort_unless($user->isPartner() && $user->is_active && $account?->isApproved(), 403, 'Compte partenaire non approuvé ou désactivé.');

        return $account->id;
    }

    public static function authorize(Model $product, User $user): void
    {
        abort_unless((int) $product->getAttribute('owner_partner_account_id') === self::accountId($user), 404);
    }
}
