<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccessoryOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'vehicle_id'  => ['nullable', 'exists:vehicles,id'],
            'discount'    => ['nullable', 'numeric', 'min:0'],
            'notes'       => ['nullable', 'string'],
            'ordered_at'  => ['nullable', 'date'],

            'items'                    => ['required', 'array', 'min:1'],
            'items.*.accessory_id'     => ['required', 'exists:accessories,id'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.unit_price'       => ['nullable', 'numeric', 'min:0'],
            'items.*.discount'         => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
