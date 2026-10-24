<?php

namespace App\Http\Requests\Partner;

use App\Models\OemNumber;
use App\Support\PartnerOwnership;
use Illuminate\Validation\Rule;

class StorePartRequest extends \App\Http\Requests\StorePartRequest
{
    use AuthorizesPartnerCatalog;

    protected function partnerRouteParameter(): string
    {
        return 'part';
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), $this->partnerOwnershipRules(), [
            'oem_numbers.*.brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'donor_vehicle_id' => [
                'nullable', 'integer', 'required_if:condition,used',
                Rule::exists('vehicles', 'id')->where('owner_partner_account_id', PartnerOwnership::accountId($this->user()))->whereNull('deleted_at'),
            ],
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('oem_numbers', []) as $index => $entry) {
                if (! OemNumber::where('normalized_number', OemNumber::normalize($entry['number']))
                    ->where('brand_id', $entry['brand_id'] ?? null)->exists()) {
                    $validator->errors()->add('oem_numbers.'.$index.'.number',
                        'Cette référence OEM ne figure pas dans le référentiel pour cette marque. Saisissez une référence non répertoriée dans manufacturer_reference.');
                }
            }
        });
    }
}
