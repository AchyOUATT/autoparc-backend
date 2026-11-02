<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogVehicleListingResource;
use App\Services\PublicVehicleCatalog;
use Illuminate\Http\Request;

class CatalogVehicleListingController extends Controller
{
    public function index(Request $request, PublicVehicleCatalog $catalog)
    {
        $boolean = static function ($attribute, $value, $fail): void {
            if (! in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
                $fail(__('validation.boolean', ['attribute' => $attribute]));
            }
        };
        $rules = [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort_by' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort_dir' => ['sometimes', 'nullable', 'string', 'max:20'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'year_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'year_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'mileage_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'consumption_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
        foreach (['brand_id', 'vehicle_model_id', 'trim_id', 'engine_type_id', 'drivetrain_id', 'color_id', 'location_id', 'origin_country_id'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:1'];
        }
        foreach (['registration_status', 'status', 'availability', 'condition', 'city', 'fault_category', 'vehicle_type', 'body_style', 'deal_type'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:100'];
        }
        foreach (['include_private', 'customs_cleared', 'without_faults', 'available_for_rent', 'deal'] as $field) {
            $rules[$field] = ['sometimes', $boolean];
        }
        $request->validate($rules);

        return CatalogVehicleListingResource::collection($catalog->paginate($request));
    }
}
