<?php

namespace App\Support;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Shared stock filters; ordering, pagination and presentation belong to the caller. */
final class VehicleCatalogQuery
{
    public static function filtered(Request $request, bool $publicOnly = false): Builder
    {
        return Vehicle::query()
            ->when($publicOnly || ! $request->user()?->isStaff(), fn ($query) => $query->visibleToPublic())
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('asset_kind'), fn ($q) => $q->where('asset_kind', $request->asset_kind))
            ->when($request->filled('equipment_type'), fn ($q) => $q->where('asset_kind', 'equipment')->where('equipment_type', $request->equipment_type))
            ->when($request->filled('registration_status'), fn ($q) => $q->where('registration_status', $request->registration_status))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->filled('vehicle_model_id'), fn ($q) => $q->where('vehicle_model_id', $request->vehicle_model_id))
            ->when($request->filled('trim_id'), fn ($q) => $q->where('trim_id', $request->trim_id))
            ->when($request->filled('engine_type_id'), fn ($q) => $q->where('engine_type_id', $request->engine_type_id))
            ->when($request->filled('drivetrain_id'), fn ($q) => $q->where('drivetrain_id', $request->drivetrain_id))
            ->when($request->filled('color_id'), fn ($q) => $q->where('color_id', $request->color_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('availability'), fn ($q) => $q->where('availability', $request->availability))
            ->when($request->filled('condition'), fn ($q) => $q->where('condition', $request->condition))
            ->when($request->filled('year_min'), fn ($q) => $q->where('manufacturing_year', '>=', $request->year_min))
            ->when($request->filled('year_max'), fn ($q) => $q->where('manufacturing_year', '<=', $request->year_max))
            // Price filters and global ordering must use the same effective price.
            ->when($request->filled('price_min'), fn ($q) => PrixEffectif::filtrer($q, 'sale_price', '>=', $request->price_min))
            ->when($request->filled('price_max'), fn ($q) => PrixEffectif::filtrer($q, 'sale_price', '<=', $request->price_max))
            ->when($request->filled('consumption_max'), fn ($q) => $q->where('consumption_combined', '<=', $request->consumption_max))
            ->when($request->filled('origin_country_id'), fn ($q) => $q->fromCountry((int) $request->origin_country_id))
            ->when($request->boolean('customs_cleared'), fn ($q) => $q->whereHas('importDetail', fn ($d) => $d->where('customs_cleared', true)))
            ->when($request->filled('mileage_max'), fn ($q) => $q->whereHas('registrationDetail', fn ($d) => $d->where('mileage_km', '<=', $request->mileage_max)))
            ->when($request->boolean('without_faults'), fn ($q) => $q
                ->whereIn('fault_declaration_state', ['none', 'has_faults'])
                ->whereDoesntHave('faults', fn ($fault) => $fault->where('status', '!=', 'repaired')))
            ->when($request->boolean('validated_by_autoparc'), fn ($q) => $q->whereHas(
                'activeStaffInspection',
                fn ($i) => $i->whereNull('withdrawn_at')
                    ->where(fn ($v) => $v->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ))
            ->when($request->filled('fault_category'), fn ($q) => $q->whereHas('faults', fn ($fault) => $fault
                ->where('category', $request->fault_category)
                ->when($publicOnly || $request->is('api/catalog/*') || ! $request->user()?->isStaff(), fn ($f) => $f->where('disclosed_to_buyer', true))))
            ->when($request->filled('vehicle_type'), fn ($q) => $q->where('vehicle_type', $request->vehicle_type))
            ->when($request->filled('body_style'), fn ($q) => $q->where('body_style', $request->body_style))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->when($request->boolean('available_for_rent'), fn ($q) => $q->forRent()->inStock())
            ->when($request->boolean('deal'), fn ($q) => $q->where(fn ($sous) => $sous
                ->whereNotNull('deal_type')
                ->orWhere(fn ($promo) => PrixEffectif::fenetreOuverte($promo))))
            ->when($request->filled('deal_type'), fn ($q) => $q->where('deal_type', $request->deal_type));
    }
}
