<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Models\Country;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $burkina = Country::where('iso2', 'BF')->first();
        $clientUsers = User::where('role', 'client')->get();

        // Fiches liées à un compte self-service (app mobile)
        foreach ($clientUsers as $i => $user) {
            Customer::create([
                'user_id' => $user->id,
                'code' => 'CUST-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'type' => CustomerType::Individual->value,
                'first_name' => 'Client',
                'last_name' => 'Test '.($i + 1),
                'phone' => $user->phone,
                'email' => $user->email,
                'city' => 'Ouagadougou',
                'country_id' => $burkina->id,
                'is_blacklisted' => false,
            ]);
        }

        // Fiches créées par le staff, sans compte self-service
        $walkIn = [
            ['first_name' => 'Aminata', 'last_name' => 'Ouédraogo', 'phone' => '+226 70 12 34 56', 'type' => CustomerType::Individual->value],
            ['first_name' => 'Boureima', 'last_name' => 'Sawadogo', 'phone' => '+226 70 22 33 44', 'type' => CustomerType::Individual->value],
        ];
        foreach ($walkIn as $i => $w) {
            Customer::create(array_merge($w, [
                'code' => 'CUST-'.str_pad((string) ($clientUsers->count() + $i + 1), 4, '0', STR_PAD_LEFT),
                'city' => 'Ouagadougou',
                'country_id' => $burkina->id,
                'is_blacklisted' => false,
            ]));
        }

        // Une entreprise (on cherche dynamiquement un cas "company"/"entreprise" dans l'enum)
        $companyType = collect(CustomerType::cases())
            ->first(fn ($case) => str_contains(strtolower($case->name), 'compan')
                || str_contains(strtolower($case->name), 'entrepr'))
            ?? collect(CustomerType::cases())->last();

        Customer::create([
            'code' => 'CUST-'.str_pad((string) ($clientUsers->count() + 3), 4, '0', STR_PAD_LEFT),
            'type' => $companyType->value,
            'company_name' => 'Sahel Logistique SARL',
            'tax_id' => 'IFU00123456A',
            'phone' => '+226 25 40 50 60',
            'city' => 'Ouagadougou',
            'country_id' => $burkina->id,
            'is_blacklisted' => false,
        ]);
    }
}
