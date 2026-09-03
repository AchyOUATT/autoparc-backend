<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManufacturersSeeder extends Seeder
{
    public function run(): void
    {
        // [name, iso2, is_oem_supplier]
        $rows = [
            // Équipementiers OEM majeurs
            ['Bosch',           'DE', true],
            ['Denso',           'JP', true],
            ['Valeo',           'FR', true],
            ['Continental',     'DE', true],
            ['Delphi Technologies', 'US', true],
            ['Mahle',           'DE', true],
            ['SKF',             'SE', true],
            ['Schaeffler / INA','DE', true],
            ['ZF Friedrichshafen', 'DE', true],
            ['BorgWarner',      'US', true],

            // Filtres & lubrification
            ['Mann+Hummel',     'DE', true],
            ['NGK',             'JP', true],
            ['Champion',        'BE', false],
            ['Purflux',         'FR', false],

            // Freinage
            ['Brembo',          'IT', true],
            ['TRW',             'US', true],
            ['ATE',             'DE', true],
            ['Textar',          'DE', false],
            ['Ferodo',          'GB', false],

            // Suspension & Direction
            ['KYB',             'JP', true],
            ['Monroe / Tenneco','US', false],
            ['Sachs',           'DE', true],
            ['Febi Bilstein',   'DE', false],
            ['Moog',            'US', false],

            // Courroies & Transmission
            ['Gates',           'US', false],
            ['Dayco',           'IT', false],
            ['SNR',             'FR', true],

            // Électricité & Batterie
            ['Hella',           'DE', true],
            ['Varta',           'DE', false],
            ['Bosch Automotive','DE', false],

            // Lubrifiants (fréquents dans les stocks)
            ['Castrol',         'GB', false],
            ['Motul',           'FR', false],
            ['Total / Elf',     'FR', false],
            ['Mobil 1',         'US', false],
        ];

        $countryMap = DB::table('countries')
            ->pluck('id', 'iso2')
            ->toArray();

        foreach ($rows as [$name, $iso2, $isOem]) {
            DB::table('manufacturers')->upsert([
                'name'            => $name,
                'slug'            => Str::slug($name),
                'country_id'      => $countryMap[$iso2] ?? null,
                'is_oem_supplier' => $isOem,
            ], ['slug'], ['name', 'country_id', 'is_oem_supplier']);
        }
    }
}
