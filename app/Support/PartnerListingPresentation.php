<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class PartnerListingPresentation
{
    public static function isPartnerListing(Model $listing): bool
    {
        return $listing->getAttribute('owner_partner_account_id') !== null;
    }

    public static function canSeePrivateData(Model $listing, Request $request): bool
    {
        if (! self::isPartnerListing($listing)) {
            return true;
        }

        $user = $request->user();

        return $user !== null && ($user->isStaff()
            || (int) $listing->sellerAccount?->user_id === (int) $user->id);
    }

    public static function seller(Model $listing): ?array
    {
        if (! self::isPartnerListing($listing)) {
            return null;
        }

        $seller = $listing->sellerAccount;

        return $seller === null ? null : [
            'id' => $seller->id,
            'company_name' => $seller->company_name,
            'phone' => $seller->phone,
            'whatsapp' => $seller->whatsapp,
        ];
    }

    public static function present(array $data, Model $listing, Request $request, string $type): array
    {
        // Historical listings retain their existing response contract.
        if (! self::isPartnerListing($listing)) {
            return $data;
        }

        $data['seller'] = self::seller($listing);

        if (self::canSeePrivateData($listing, $request)) {
            $data['owner_partner_account_id'] = $listing->owner_partner_account_id;
            $data['partner_published_at'] = $listing->partner_published_at?->toIso8601String();

            return $data;
        }

        if ($type === 'vehicle') {
            $data = Arr::only($data, [
                'id', 'designation', 'vehicle_type', 'body_style', 'registration_status',
                'identity', 'technical', 'consumption', 'commercial', 'features',
                'optional_feature_count', 'is_full_option', 'media', 'description',
                'published_at', 'created_at', 'share_version', 'seller',
            ]);
            $data['commercial'] = Arr::only($data['commercial'], [
                'condition', 'status', 'status_label', 'availability', 'sale_price',
                'rental_daily_rate', 'rental_weekly_rate', 'rental_monthly_rate',
                'rental_deposit', 'currency', 'price_negotiable', 'deal_type', 'deal_label',
            ]);
        } else {
            $data = Arr::only($data, [
                'id', 'name', 'description', 'compatibility_assessment', 'type', 'condition',
                'category', 'category_id', 'manufacturer', 'manufacturer_id',
                'manufacturer_reference', 'oem_numbers', 'pricing', 'stock', 'media',
                'warranty_months', 'weight_kg', 'dimensions', 'is_active',
                'compatibility', 'fitments', 'share_version', 'seller',
            ]);
            $data['stock'] = Arr::only($data['stock'], ['is_available']);

            if (isset($data['fitments']) && is_iterable($data['fitments'])) {
                $data['fitments'] = collect($data['fitments'])
                    ->map(fn (array $fitment) => Arr::except($fitment, ['notes']))
                    ->values();
            }
        }

        return $data;
    }
}
