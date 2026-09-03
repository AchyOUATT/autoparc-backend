<?php

namespace App\Http\Requests;

use App\Enums\PartCondition;
use App\Enums\PartType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('part')?->id ?? $this->route('part');

        return [
            'sku'                    => ['nullable', 'string', 'max:60', Rule::unique('parts', 'sku')->ignore($id)->whereNotNull('sku')],
            'name'                   => ['required', 'string', 'max:180'],
            'description'            => ['nullable', 'string'],
            'part_category_id'       => ['required', 'exists:part_categories,id'],
            'manufacturer_id'        => ['nullable', 'exists:manufacturers,id'],
            'manufacturer_reference' => ['nullable', 'string', 'max:80'],
            'type'                   => ['required', Rule::in(PartType::values())],
            'condition'              => ['required', Rule::in(PartCondition::values())],
            'donor_vehicle_id'       => ['nullable', 'exists:vehicles,id', 'required_if:condition,used'],
            'weight_kg'              => ['nullable', 'numeric', 'min:0'],
            'dimensions'             => ['nullable', 'string', 'max:80'],
            'warranty_months'        => ['nullable', 'integer', 'min:0', 'max:120'],
            'cost_price'             => ['nullable', 'numeric', 'min:0'],
            'selling_price'          => ['required', 'numeric', 'min:0'],
            'currency'               => ['nullable', 'string', 'size:3'],
            'vat_rate'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity'         => ['nullable', 'integer', 'min:0'],
            'stock_alert_threshold'  => ['nullable', 'integer', 'min:0'],
            'storage_location'       => ['nullable', 'string', 'max:60'],
            'location_id'            => ['nullable', 'exists:locations,id'],
            'is_active'              => ['boolean'],

            // Numeros OEM couverts par la piece
            'oem_numbers'              => ['array'],
            'oem_numbers.*.number'     => ['required_with:oem_numbers', 'string', 'max:60'],
            'oem_numbers.*.brand_id'   => ['nullable', 'exists:brands,id'],
            'oem_numbers.*.is_primary' => ['boolean'],

            // Compatibilites directes
            'fitments'                     => ['array'],
            'fitments.*.vehicle_model_id'  => ['required_with:fitments', 'exists:vehicle_models,id'],
            'fitments.*.trim_id'           => ['nullable', 'exists:trims,id'],
            'fitments.*.engine_type_id'    => ['nullable', 'exists:engine_types,id'],
            'fitments.*.drivetrain_id'     => ['nullable', 'exists:drivetrains,id'],
            'fitments.*.engine_code'       => ['nullable', 'string', 'max:50'],
            'fitments.*.year_from'         => ['nullable', 'integer', 'min:1950'],
            'fitments.*.year_to'           => ['nullable', 'integer', 'min:1950', 'gte:fitments.*.year_from'],
            'fitments.*.position'          => ['nullable', 'string', 'max:60'],
        ];
    }
}
