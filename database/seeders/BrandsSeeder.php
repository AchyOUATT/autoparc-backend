<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrandsSeeder extends Seeder
{
    public function run(): void
    {
        // [name, iso2, oem_prefix]  — marques dominantes marché Afrique de l'Ouest (UEMOA)
        $rows = [
            // Japonaises (très fortes à l'import Japon)
            ['Toyota',          'JP', '90'],
            ['Nissan',          'JP', '16'],
            ['Honda',           'JP', '91'],
            ['Mitsubishi',      'JP', 'MB'],
            ['Suzuki',          'JP', '09'],
            ['Mazda',           'JP', 'GJ'],
            ['Daihatsu',        'JP', '90600'],
            ['Subaru',          'JP', '20'],
            ['Lexus',           'JP', '89'],
            ['Isuzu',           'JP', '8972'],

            // Coréennes
            ['Hyundai',         'KR', '96'],
            ['Kia',             'KR', 'OK'],
            ['SsangYong',       'KR', '69'],

            // Françaises
            ['Peugeot',         'FR', '22'],
            ['Renault',         'FR', '77'],
            ['Citroën',         'FR', '96'],

            // Allemandes
            ['Volkswagen',      'DE', '1J'],
            ['Mercedes-Benz',   'DE', 'A0'],
            ['BMW',             'DE', '11'],
            ['Opel',            'DE', '90'],

            // Américaines
            ['Ford',            'US', '6C'],
            ['Chevrolet',       'US', '94'],
            ['Jeep',            'US', '52'],

            // Britanniques
            ['Land Rover',      'GB', 'STC'],

            // Italiennes
            ['Fiat',            'IT', '46'],
        ];

        $countryMap = DB::table('countries')
            ->pluck('id', 'iso2')
            ->toArray();

        foreach ($rows as [$name, $iso2, $prefix]) {
            DB::table('brands')->upsert([
                'name'       => $name,
                'slug'       => Str::slug($name),
                'country_id' => $countryMap[$iso2] ?? null,
                'oem_prefix' => $prefix,
                'is_active'  => true,
            ], ['slug'], ['name', 'country_id', 'oem_prefix', 'is_active']);
        }
    }
}
