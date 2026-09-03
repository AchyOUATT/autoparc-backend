<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePartOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'        => ['nullable', 'exists:customers,id'],
            'vehicle_id'         => ['nullable', 'exists:vehicles,id'],
            'ordered_at'         => ['required', 'date'],
            'discount'           => ['nullable', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.part_id'    => ['required', 'exists:parts,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount'   => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
