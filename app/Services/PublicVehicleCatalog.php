<?php

namespace App\Services;

use App\Models\PrivateVehicleListing;
use App\Models\Vehicle;
use App\Support\PrixEffectif;
use App\Support\VehicleCatalogQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Select one globally ordered page of identities before loading its public models. */
final class PublicVehicleCatalog
{
    private const SORTS = [
        'created_at' => 'sort_created_at',
        'sale_price' => 'sort_price',
        'rental_daily_rate' => 'sort_rental',
        'manufacturing_year' => 'sort_year',
        'consumption_combined' => 'sort_consumption',
    ];

    public function paginate(Request $request): LengthAwarePaginator
    {
        $stock = VehicleCatalogQuery::filtered($request, publicOnly: true)
            ->selectRaw("'vehicle' AS source, vehicles.id AS source_id, vehicles.created_at AS sort_created_at,
                vehicles.manufacturing_year AS sort_year, vehicles.rental_daily_rate AS sort_rental,
                vehicles.consumption_combined AS sort_consumption")
            ->selectRaw(PrixEffectif::sql('vehicles.sale_price').' AS sort_price', PrixEffectif::liaisons());

        $selection = $stock->toBase();
        if ($private = $this->privateQuery($request)) {
            $selection->unionAll($private->selectRaw("'private_listing' AS source,
                private_vehicle_listings.id AS source_id, private_vehicle_listings.published_at AS sort_created_at,
                private_vehicle_listings.year AS sort_year, CAST(NULL AS DECIMAL(14,2)) AS sort_rental,
                CAST(NULL AS DECIMAL(5,2)) AS sort_consumption,
                CAST(private_vehicle_listings.price AS DECIMAL(14,2)) AS sort_price")->toBase());
        }

        $sort = self::SORTS[(string) $request->input('sort_by', 'created_at')] ?? 'sort_created_at';
        $direction = strtolower((string) $request->input('sort_dir', 'desc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $page = DB::query()->fromSub($selection, 'catalog_items')
            // Unknown values follow known values in either direction, on SQLite and PostgreSQL.
            ->orderByRaw($sort.' IS NULL ASC')
            ->orderBy($sort, $direction)
            ->orderBy('source')
            ->orderBy('source_id')
            ->paginate($request->integer('per_page', 20), ['*'], 'page', $request->integer('page', 1))
            ->withQueryString();

        $rows = $page->getCollection();
        $vehicles = $rows->where('source', 'vehicle')->isEmpty() ? collect() : Vehicle::visibleToPublic()
            ->whereKey($rows->where('source', 'vehicle')->pluck('source_id')->all())
            ->with([
                'brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain', 'color',
                'location', 'sellerAccount',
                'importDetail.originCountry', 'registrationDetail.registrationCountry', 'media',
            ])
            ->withCount('faults')->get()->keyBy('id');
        $listings = $rows->where('source', 'private_listing')->isEmpty() ? collect() : PrivateVehicleListing::published()
            ->whereKey($rows->where('source', 'private_listing')->pluck('source_id')->all())
            ->with('photos')->get()->keyBy('id');

        $page->setCollection($rows->map(function ($row) use ($vehicles, $listings) {
            $model = ($row->source === 'vehicle' ? $vehicles : $listings)->get($row->source_id);

            // A listing withdrawn between selection and hydration must not be exposed.
            return $model === null ? null : ['source' => $row->source, 'model' => $model];
        })->filter()->values());

        return $page;
    }

    private function privateQuery(Request $request): ?Builder
    {
        if (! config('private_sales.enabled') || ! $request->boolean('include_private', true)) {
            return null;
        }

        // Private car ads do not describe construction or agricultural assets.
        if (($request->filled('asset_kind') && $request->input('asset_kind') !== 'road_vehicle')
            || $request->filled('equipment_type')) {
            return null;
        }

        // These facts are absent from a private snapshot. Unknown never satisfies a strict filter.
        foreach ([
            'registration_status', 'status', 'condition', 'consumption_max', 'origin_country_id',
            'vehicle_type', 'body_style', 'location_id', 'deal_type',
        ] as $field) {
            if ($request->filled($field)) {
                return null;
            }
        }
        foreach (['customs_cleared', 'available_for_rent', 'deal'] as $field) {
            if ($request->boolean($field)) {
                return null;
            }
        }
        if ($request->filled('availability') && $request->input('availability') !== 'sale') {
            return null;
        }

        $query = PrivateVehicleListing::published();
        foreach (['brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id', 'city'] as $field) {
            $query->when($request->filled($field), fn ($q) => $q->where($field, $request->input($field)));
        }

        return $query
            ->when($request->boolean('validated_by_autoparc'), fn ($q) => $q->whereHas(
                'activeStaffInspection',
                fn ($i) => $i->whereNull('withdrawn_at')
                    ->where(fn ($v) => $v->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ))
            ->when($request->boolean('without_faults'), fn ($q) => $q->whereIn('fault_declaration_state', ['none', 'has_faults'])
                ->whereDoesntHave('faults', fn ($fault) => $fault->where('status', '!=', 'repaired')))
            ->when($request->filled('fault_category'), fn ($q) => $q->whereHas('faults', fn ($fault) => $fault
                ->where('category', $request->input('fault_category'))->where('disclosed_to_buyer', true)))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search
                ->whereLike('title', '%'.$request->string('q')->toString().'%')
                ->orWhereLike('city', '%'.$request->string('q')->toString().'%')))
            ->when($request->filled('year_min'), fn ($q) => $q->where('year', '>=', $request->year_min))
            ->when($request->filled('year_max'), fn ($q) => $q->where('year', '<=', $request->year_max))
            ->when($request->filled('price_min'), fn ($q) => $q->where('price', '>=', $request->price_min))
            ->when($request->filled('price_max'), fn ($q) => $q->where('price', '<=', $request->price_max))
            ->when($request->filled('mileage_max'), fn ($q) => $q->where('mileage_km', '<=', $request->mileage_max));
    }
}
