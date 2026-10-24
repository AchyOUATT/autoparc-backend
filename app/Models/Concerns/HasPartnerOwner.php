<?php

namespace App\Models\Concerns;

use App\Enums\VehicleStatus;
use App\Models\PartnerAccount;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasPartnerOwner
{
    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(PartnerAccount::class, 'owner_partner_account_id');
    }

    public function scopeOwnedByPartnerAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($this->qualifyColumn('owner_partner_account_id'), $accountId);
    }

    public function scopeVisibleToPublic(Builder $query): Builder
    {
        return $query->where(function (Builder $products) {
            $products->whereNull($this->qualifyColumn('owner_partner_account_id'))
                ->orWhere(function (Builder $owned) {
                    if ($this instanceof Vehicle) {
                        $owned->whereNotIn($this->qualifyColumn('status'), [VehicleStatus::Sold->value, VehicleStatus::Archived->value]);
                    } else {
                        $owned->where($this->qualifyColumn('is_active'), true);
                    }

                    $owned->whereNotNull($this->qualifyColumn('partner_published_at'))
                        ->whereHas('sellerAccount', function (Builder $account) {
                            $account->where('status', 'approved')->whereHas('user', function (Builder $user) {
                                $user->where('role', 'partner')->where('is_active', true);
                            });
                        });
                });
        });
    }
}
