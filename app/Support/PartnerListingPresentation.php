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

            // ── LE NOM PUBLIC NE PEUT ETRE NI NUL NI VIDE ──────────────────
            //
            // `company_name` est facultatif a l'inscription : un revendeur
            // particulier n'a pas de raison sociale. Mais l'application
            // PUBLIEE lit ce champ par un transtypage dur
            // (`json['company_name'] as String`, dans `ProductSeller` comme
            // dans `PartnerSeller`) et compose l'initiale du vendeur par
            // `companyName[0]`. Un null y leve un `TypeError`, une chaine vide
            // un `RangeError` — et les deux font tomber l'ecran de detail de
            // l'annonce, celui-la meme qui porte le numero a appeler.
            //
            // Le repli est donc tenu ICI, par le serveur, et non par une
            // future version de l'application : les telephones deja installes
            // ne se mettent pas a jour parce qu'on l'aurait souhaite.
            //
            // `contact_name` reste obligatoire, donc le repli aboutit
            // toujours. Et c'est le nom sous lequel ce vendeur se presente :
            // rien n'est invente.
            'company_name' => $seller->company_name ?: $seller->contact_name,
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
