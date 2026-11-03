<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffVehicleInspectionRequest extends FormRequest
{
    public function authorize(): bool { return true; } // staff + dedicated capability on route

    public function rules(): array
    {
        $rules = [
            'inspected_at' => ['required', 'date', 'before_or_equal:now'],
            'mileage_km' => ['required', 'integer', 'min:0', 'max:10000000'],
            'valid_until' => ['nullable', 'date', 'after:inspected_at'],
            'summary' => ['required', 'string', 'max:10000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:label,status,note'],
            'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.status' => ['required', Rule::in(['ok', 'attention', 'blocking', 'not_checked'])],
            'items.*.note' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['required', 'accepted'],
        ];
        foreach (['actor_id', 'withdrawn_by', 'status', 'result', 'checked_count', 'attention_count',
            'blocking_count', 'technical_fingerprint', 'vehicle_id', 'private_vehicle_listing_id', 'active_key', 'inspection', 'source', 'source_id', 'state'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! collect(is_array($this->input('items')) ? $this->input('items') : [])
                ->contains(fn ($item) => is_array($item) && in_array($item['status'] ?? null, ['ok', 'attention', 'blocking'], true))) {
                $validator->errors()->add('items', __('Au moins un point doit avoir été réellement contrôlé.'));
            }
        }];
    }
}
