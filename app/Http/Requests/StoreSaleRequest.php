<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'       => ['required', 'exists:customers,id'],
            'vehicle_id'        => ['required', 'exists:vehicles,id'],
            'agreed_price'      => ['required', 'numeric', 'min:0'],
            'discount'          => ['nullable', 'numeric', 'min:0'],
            'tax_amount'        => ['nullable', 'numeric', 'min:0'],
            'registration_fees' => ['nullable', 'numeric', 'min:0'],
            'faults_disclosed'  => ['boolean'],
            'sold_at'           => ['required', 'date'],
            'delivery_date'     => ['nullable', 'date', 'after_or_equal:sold_at'],
            'notes'             => ['nullable', 'string'],
        ];
    }
}
