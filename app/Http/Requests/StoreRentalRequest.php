<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'        => ['required', 'exists:customers,id'],
            'vehicle_id'         => ['required', 'exists:vehicles,id'],
            'start_at'           => ['required', 'date'],
            'expected_return_at' => ['required', 'date', 'after:start_at'],
            'with_driver'        => ['boolean'],
            'driver_name'        => ['nullable', 'string', 'max:120', 'required_if:with_driver,true'],
            'daily_rate'         => ['nullable', 'numeric', 'min:0'],
            'deposit_amount'     => ['nullable', 'numeric', 'min:0'],
            'mileage_limit_km'   => ['nullable', 'integer', 'min:0'],
            'extra_km_rate'      => ['nullable', 'numeric', 'min:0'],
            'pickup_location'    => ['nullable', 'string', 'max:120'],
            'return_location'    => ['nullable', 'string', 'max:120'],
            'checkout_notes'     => ['nullable', 'string'],
        ];
    }
}
