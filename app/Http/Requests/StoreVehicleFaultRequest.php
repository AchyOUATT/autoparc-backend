<?php

namespace App\Http\Requests;

use App\Enums\FaultCategory;
use App\Enums\FaultSeverity;
use App\Enums\FaultStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleFaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'                    => ['nullable', 'string', 'max:20'],
            'category'                => ['required', Rule::in(FaultCategory::values())],
            'title'                   => ['required', 'string', 'max:180'],
            'description'             => ['nullable', 'string'],
            'severity'                => ['required', Rule::in(FaultSeverity::values())],
            'status'                  => ['nullable', Rule::in(FaultStatus::values())],
            'affects_drivability'     => ['boolean'],
            'is_safety_critical'      => ['boolean'],
            'disclosed_to_buyer'      => ['boolean'],
            'detected_at'             => ['nullable', 'date', 'before_or_equal:today'],
            'mileage_at_detection_km' => ['nullable', 'integer', 'min:0'],
            'reported_by'             => ['nullable', 'string', 'max:120'],
            'estimated_repair_cost'   => ['nullable', 'numeric', 'min:0'],
            'actual_repair_cost'      => ['nullable', 'numeric', 'min:0'],
            'repaired_at'             => ['nullable', 'date'],
            'assigned_to'             => ['nullable', 'exists:users,id'],
            'parts'                   => ['array'],
            'parts.*.part_id'         => ['required_with:parts', 'exists:parts,id'],
            'parts.*.quantity'        => ['nullable', 'integer', 'min:1'],
        ];
    }
}
