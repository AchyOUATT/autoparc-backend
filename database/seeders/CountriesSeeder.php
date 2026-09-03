<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        // is_common_origin = pays de provenance fréquents à l'import auto en Afrique de l'Ouest
        // [iso2, iso3, name, nationality, currency_code, is_common_origin]
        $countries = [
            // ── Afrique de l'Ouest (CEDEAO) ──────────────────────────────
            ['BF', 'BFA', 'Burkina Faso',         'Burkinabé',      'XOF', false],
            ['CI', 'CIV', "Côte d'Ivoire",        'Ivoirien',       'XOF', false],
            ['SN', 'SEN', 'Sénégal',              'Sénégalais',     'XOF', false],
            ['ML', 'MLI', 'Mali',                 'Malien',         'XOF', false],
            ['NE', 'NER', 'Niger',                'Nigérien',       'XOF', false],
            ['TG', 'TGO', 'Togo',                 'Togolais',       'XOF', false],
            ['BJ', 'BEN', 'Bénin',                'Béninois',       'XOF', false],
            ['GW', 'GNB', 'Guinée-Bissau',        'Bissau-Guinéen', 'XOF', false],
            ['GN', 'GIN', 'Guinée',               'Guinéen',        'GNF', false],
            ['GH', 'GHA', 'Ghana',                'Ghanéen',        'GHS', false],
            ['NG', 'NGA', 'Nigéria',              'Nigérian',       'NGN', false],
            ['LR', 'LBR', 'Libéria',              'Libérien',       'LRD', false],
            ['SL', 'SLE', 'Sierra Leone',         'Sierra-Léonais', 'SLL', false],
            ['GM', 'GMB', 'Gambie',               'Gambien',        'GMD', false],
            ['CV', 'CPV', 'Cap-Vert',             'Cap-Verdien',    'CVE', false],
            ['MR', 'MRT', 'Mauritanie',           'Mauritanien',    'MRO', false],

            // ── Afrique centrale ──────────────────────────────────────────
            ['CM', 'CMR', 'Cameroun',             'Camerounais',    'XAF', false],
            ['CF', 'CAF', 'République centrafricaine', 'Centrafricain', 'XAF', false],
            ['TD', 'TCD', 'Tchad',                'Tchadien',       'XAF', false],
            ['CG', 'COG', 'République du Congo',  'Congolais',      'XAF', false],
            ['GA', 'GAB', 'Gabon',                'Gabonais',       'XAF', false],
            ['GQ', 'GNQ', 'Guinée équatoriale',   'Équato-guinéen', 'XAF', false],
            ['CD', 'COD', 'République démocratique du Congo', 'Congolais', 'CDF', false],

            // ── Afrique de l'Est ──────────────────────────────────────────
            ['ET', 'ETH', 'Éthiopie',             'Éthiopien',      'ETB', false],
            ['KE', 'KEN', 'Kenya',                'Kényan',         'KES', false],
            ['TZ', 'TZA', 'Tanzanie',             'Tanzanien',      'TZS', false],
            ['UG', 'UGA', 'Ouganda',              'Ougandais',      'UGX', false],
            ['RW', 'RWA', 'Rwanda',               'Rwandais',       'RWF', false],

            // ── Afrique australe ──────────────────────────────────────────
            ['ZA', 'ZAF', 'Afrique du Sud',       'Sud-Africain',   'ZAR', false],
            ['AO', 'AGO', 'Angola',               'Angolais',       'AOA', false],
            ['MZ', 'MOZ', 'Mozambique',           'Mozambicain',    'MZN', false],

            // ── Afrique du Nord ───────────────────────────────────────────
            ['MA', 'MAR', 'Maroc',                'Marocain',       'MAD', false],
            ['DZ', 'DZA', 'Algérie',              'Algérien',       'DZD', false],
            ['TN', 'TUN', 'Tunisie',              'Tunisien',       'TND', false],
            ['EG', 'EGY', 'Égypte',               'Égyptien',       'EGP', false],
            ['LY', 'LBY', 'Libye',                'Libyen',         'LYD', false],

            // ── Origines fréquentes d'import automobile ──────────────────
            ['JP', 'JPN', 'Japon',                'Japonais',       'JPY', true],
            ['KR', 'KOR', 'Corée du Sud',         'Sud-Coréen',     'KRW', true],
            ['DE', 'DEU', 'Allemagne',             'Allemand',       'EUR', true],
            ['FR', 'FRA', 'France',               'Français',       'EUR', true],
            ['GB', 'GBR', 'Royaume-Uni',          'Britannique',    'GBP', true],
            ['NL', 'NLD', 'Pays-Bas',             'Néerlandais',    'EUR', true],
            ['BE', 'BEL', 'Belgique',             'Belge',          'EUR', true],
            ['IT', 'ITA', 'Italie',               'Italien',        'EUR', true],
            ['ES', 'ESP', 'Espagne',              'Espagnol',       'EUR', true],
            ['SE', 'SWE', 'Suède',                'Suédois',        'SEK', true],
            ['CN', 'CHN', 'Chine',                'Chinois',        'CNY', true],
            ['IN', 'IND', 'Inde',                 'Indien',         'INR', true],
            ['US', 'USA', 'États-Unis',           'Américain',      'USD', true],
            ['CA', 'CAN', 'Canada',               'Canadien',       'CAD', false],
            ['AU', 'AUS', 'Australie',            'Australien',     'AUD', false],

            // ── Autres pays courants ──────────────────────────────────────
            ['BR', 'BRA', 'Brésil',               'Brésilien',      'BRL', false],
            ['MX', 'MEX', 'Mexique',              'Mexicain',       'MXN', false],
            ['TR', 'TUR', 'Turquie',              'Turc',           'TRY', false],
            ['AE', 'ARE', 'Émirats arabes unis',  'Émirati',        'AED', false],
            ['SA', 'SAU', 'Arabie saoudite',      'Saoudien',       'SAR', false],
            ['PK', 'PAK', 'Pakistan',             'Pakistanais',    'PKR', false],
            ['ID', 'IDN', 'Indonésie',            'Indonésien',     'IDR', false],
            ['TH', 'THA', 'Thaïlande',            'Thaïlandais',    'THB', false],
            ['MY', 'MYS', 'Malaisie',             'Malaisien',      'MYR', false],
            ['SG', 'SGP', 'Singapour',            'Singapourien',   'SGD', false],
            ['ZW', 'ZWE', 'Zimbabwe',             'Zimbabwéen',     'ZWL', false],
            ['LB', 'LBN', 'Liban',                'Libanais',       'LBP', false],
            ['PT', 'PRT', 'Portugal',             'Portugais',      'EUR', false],
            ['CH', 'CHE', 'Suisse',               'Suisse',         'CHF', false],
            ['AT', 'AUT', 'Autriche',             'Autrichien',     'EUR', false],
            ['PL', 'POL', 'Pologne',              'Polonais',       'PLN', false],
        ];

        foreach ($countries as [$iso2, $iso3, $name, $nationality, $currency, $isOrigin]) {
            DB::table('countries')->upsert([
                'iso2'             => $iso2,
                'iso3'             => $iso3,
                'name'             => $name,
                'nationality'      => $nationality,
                'currency_code'    => $currency,
                'is_common_origin' => $isOrigin,
            ], ['iso2'], ['iso3', 'name', 'nationality', 'currency_code', 'is_common_origin']);
        }
    }
}
