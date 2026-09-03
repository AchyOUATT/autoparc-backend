<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VehicleModelsSeeder extends Seeder
{
    public function run(): void
    {
        $brandMap = DB::table('brands')->pluck('id', 'slug')->toArray();

        // Structure: [brand_slug, name, generation|null, body_type, segment, year_start, year_end|null]
        $models = [
            // ─── TOYOTA ────────────────────────────────────────────────
            ['toyota', 'Corolla',      'E210',  'berline',   'C', 2019, null],
            ['toyota', 'Corolla',      'E180',  'berline',   'C', 2013, 2019],
            ['toyota', 'Corolla',      'E140',  'berline',   'C', 2006, 2013],
            ['toyota', 'Yaris',        'XP210', 'citadine',  'B', 2020, null],
            ['toyota', 'Yaris',        'XP150', 'citadine',  'B', 2011, 2020],
            ['toyota', 'Camry',        'XV70',  'berline',   'D', 2017, null],
            ['toyota', 'Camry',        'XV50',  'berline',   'D', 2011, 2017],
            ['toyota', 'RAV4',         'XA50',  'suv',       'C', 2018, null],
            ['toyota', 'RAV4',         'XA40',  'suv',       'C', 2012, 2018],
            ['toyota', 'Land Cruiser', 'J300',  'suv',       'F', 2021, null],
            ['toyota', 'Land Cruiser', 'J200',  'suv',       'F', 2007, 2021],
            ['toyota', 'Land Cruiser', 'J100',  'suv',       'F', 1998, 2007],
            ['toyota', 'Land Cruiser Prado', 'J150', 'suv',  'E', 2009, null],
            ['toyota', 'Land Cruiser Prado', 'J120', 'suv',  'E', 2002, 2009],
            ['toyota', 'Hilux',        'AN120', 'pick-up',   'D', 2015, null],
            ['toyota', 'Hilux',        'AN10',  'pick-up',   'D', 2004, 2015],
            ['toyota', 'Fortuner',     'AN160', 'suv',       'D', 2015, null],
            ['toyota', 'Fortuner',     'AN50',  'suv',       'D', 2005, 2015],
            ['toyota', 'Prius',        'XW50',  'berline',   'C', 2015, null],
            ['toyota', 'Prius',        'XW30',  'berline',   'C', 2009, 2015],
            ['toyota', 'Vios',         null,    'berline',   'B', 2013, null],
            ['toyota', 'Rush',         null,    'suv',       'B', 2017, null],
            ['toyota', 'Avensis',      'T270',  'berline',   'D', 2008, 2018],
            ['toyota', 'Auris',        'E180',  'compacte',  'C', 2012, 2019],

            // ─── NISSAN ────────────────────────────────────────────────
            ['nissan', 'Micra',        'K14',   'citadine',  'B', 2017, null],
            ['nissan', 'Micra',        'K13',   'citadine',  'B', 2010, 2017],
            ['nissan', 'Note',         'E12',   'monospace', 'B', 2012, null],
            ['nissan', 'Almera',       'N17',   'berline',   'C', 2011, null],
            ['nissan', 'Tiida',        'C11',   'berline',   'C', 2004, 2012],
            ['nissan', 'Sentra',       'B18',   'berline',   'C', 2019, null],
            ['nissan', 'Qashqai',      'J11',   'suv',       'C', 2013, null],
            ['nissan', 'X-Trail',      'T32',   'suv',       'D', 2013, null],
            ['nissan', 'X-Trail',      'T31',   'suv',       'D', 2007, 2013],
            ['nissan', 'Patrol',       'Y62',   'suv',       'F', 2010, null],
            ['nissan', 'Patrol',       'Y61',   'suv',       'F', 1997, 2010],
            ['nissan', 'Navara',       'D23',   'pick-up',   'D', 2014, null],
            ['nissan', 'Navara',       'D40',   'pick-up',   'D', 2004, 2014],
            ['nissan', 'Pathfinder',   'R52',   'suv',       'E', 2012, null],

            // ─── HONDA ─────────────────────────────────────────────────
            ['honda', 'Jazz',          'GK',    'citadine',  'B', 2013, null],
            ['honda', 'Jazz',          'GE',    'citadine',  'B', 2008, 2013],
            ['honda', 'Civic',         'FC',    'berline',   'C', 2015, null],
            ['honda', 'Civic',         'FB',    'berline',   'C', 2011, 2015],
            ['honda', 'Accord',        'CR',    'berline',   'D', 2012, 2017],
            ['honda', 'Accord',        'CV',    'berline',   'D', 2017, null],
            ['honda', 'CR-V',          'RW',    'suv',       'D', 2016, null],
            ['honda', 'CR-V',          'RM',    'suv',       'D', 2011, 2016],
            ['honda', 'HR-V',          'RU',    'suv',       'B', 2014, null],
            ['honda', 'Pilot',         'YF',    'suv',       'E', 2015, null],
            ['honda', 'City',          'GM6',   'berline',   'B', 2014, null],

            // ─── MITSUBISHI ────────────────────────────────────────────
            ['mitsubishi', 'Lancer',      'CY',   'berline', 'C', 2007, 2017],
            ['mitsubishi', 'Outlander',   'GF',   'suv',     'D', 2012, null],
            ['mitsubishi', 'ASX',         'GA',   'suv',     'C', 2010, null],
            ['mitsubishi', 'Pajero',      'V80',  'suv',     'E', 2006, null],
            ['mitsubishi', 'Pajero Sport','QF',   'suv',     'D', 2015, null],
            ['mitsubishi', 'Eclipse Cross', null, 'suv',     'C', 2017, null],
            ['mitsubishi', 'L200',        'KL',   'pick-up', 'D', 2015, null],
            ['mitsubishi', 'L200',        'KB',   'pick-up', 'D', 2005, 2015],

            // ─── SUZUKI ────────────────────────────────────────────────
            ['suzuki', 'Alto',          null,  'citadine',  'A', 2015, null],
            ['suzuki', 'Swift',         'AZG', 'citadine',  'B', 2017, null],
            ['suzuki', 'Swift',         'ZC7', 'citadine',  'B', 2011, 2017],
            ['suzuki', 'Jimny',         'JB74','tout-terrain','B', 2018, null],
            ['suzuki', 'Jimny',         'JB43','tout-terrain','B', 1998, 2018],
            ['suzuki', 'Vitara',        'LY',  'suv',       'B', 2015, null],
            ['suzuki', 'Ertiga',        null,  'monospace', 'B', 2012, null],
            ['suzuki', 'S-Cross',       null,  'suv',       'C', 2013, null],

            // ─── HYUNDAI ───────────────────────────────────────────────
            ['hyundai', 'i10',          'BA',  'citadine',  'A', 2013, null],
            ['hyundai', 'i20',          'IB',  'citadine',  'B', 2014, null],
            ['hyundai', 'Accent',       'RB',  'berline',   'B', 2011, null],
            ['hyundai', 'Elantra',      'CN7', 'berline',   'C', 2020, null],
            ['hyundai', 'Elantra',      'AD',  'berline',   'C', 2015, 2020],
            ['hyundai', 'Tucson',       'TL',  'suv',       'C', 2015, 2020],
            ['hyundai', 'Tucson',       'NX4', 'suv',       'C', 2020, null],
            ['hyundai', 'Santa Fe',     'DM',  'suv',       'D', 2012, 2018],
            ['hyundai', 'Santa Fe',     'TM',  'suv',       'D', 2018, null],
            ['hyundai', 'Creta',        null,  'suv',       'B', 2015, null],
            ['hyundai', 'Sonata',       'LF',  'berline',   'D', 2014, 2019],

            // ─── KIA ───────────────────────────────────────────────────
            ['kia', 'Picanto',       'JA',  'citadine',  'A', 2017, null],
            ['kia', 'Picanto',       'TA',  'citadine',  'A', 2011, 2017],
            ['kia', 'Rio',           'YB',  'berline',   'B', 2017, null],
            ['kia', 'Rio',           'UB',  'berline',   'B', 2011, 2017],
            ['kia', 'Cerato',        'BD',  'berline',   'C', 2018, null],
            ['kia', 'Sportage',      'QL',  'suv',       'C', 2015, 2021],
            ['kia', 'Sportage',      'NQ5', 'suv',       'C', 2021, null],
            ['kia', 'Sorento',       'UM',  'suv',       'D', 2014, 2020],
            ['kia', 'Stonic',        null,  'suv',       'B', 2017, null],
            ['kia', 'Seltos',        null,  'suv',       'B', 2019, null],

            // ─── PEUGEOT ───────────────────────────────────────────────
            ['peugeot', '207',       null,  'citadine',  'B', 2006, 2012],
            ['peugeot', '208',       null,  'citadine',  'B', 2012, null],
            ['peugeot', '301',       null,  'berline',   'C', 2012, null],
            ['peugeot', '307',       null,  'compacte',  'C', 2001, 2008],
            ['peugeot', '308',       null,  'compacte',  'C', 2013, null],
            ['peugeot', '2008',      null,  'suv',       'B', 2013, null],
            ['peugeot', '3008',      null,  'suv',       'C', 2016, null],
            ['peugeot', '5008',      null,  'suv',       'D', 2017, null],
            ['peugeot', 'Partner',   null,  'utilitaire','C', 2008, null],

            // ─── RENAULT ───────────────────────────────────────────────
            ['renault', 'Clio',      'IV',  'citadine',  'B', 2012, 2019],
            ['renault', 'Clio',      'V',   'citadine',  'B', 2019, null],
            ['renault', 'Mégane',    'IV',  'compacte',  'C', 2015, null],
            ['renault', 'Logan',     'II',  'berline',   'B', 2012, null],
            ['renault', 'Duster',    null,  'suv',       'C', 2010, null],
            ['renault', 'Captur',    null,  'suv',       'B', 2013, null],
            ['renault', 'Koleos',    'II',  'suv',       'D', 2016, null],
            ['renault', 'Symbol',    null,  'berline',   'B', 2008, null],

            // ─── VOLKSWAGEN ────────────────────────────────────────────
            ['volkswagen', 'Polo',   'AW',  'citadine',  'B', 2017, null],
            ['volkswagen', 'Golf',   'VIII','compacte',  'C', 2019, null],
            ['volkswagen', 'Golf',   'VII', 'compacte',  'C', 2012, 2019],
            ['volkswagen', 'Passat', 'B8',  'berline',   'D', 2014, null],
            ['volkswagen', 'Tiguan', 'AD',  'suv',       'C', 2016, null],
            ['volkswagen', 'T-Roc',  null,  'suv',       'B', 2017, null],
            ['volkswagen', 'Amarok', null,  'pick-up',   'D', 2010, null],
            ['volkswagen', 'Touareg','CR7', 'suv',       'E', 2018, null],

            // ─── MERCEDES-BENZ ─────────────────────────────────────────
            ['mercedes-benz', 'Classe C', 'W205', 'berline', 'D', 2014, null],
            ['mercedes-benz', 'Classe C', 'W204', 'berline', 'D', 2007, 2014],
            ['mercedes-benz', 'Classe E', 'W213', 'berline', 'E', 2016, null],
            ['mercedes-benz', 'Classe E', 'W212', 'berline', 'E', 2009, 2016],
            ['mercedes-benz', 'GLC',      'X253', 'suv',     'D', 2015, null],
            ['mercedes-benz', 'GLE',      'W167', 'suv',     'E', 2018, null],
            ['mercedes-benz', 'ML / GLE', 'W166', 'suv',     'E', 2011, 2018],
            ['mercedes-benz', 'Sprinter', null,   'utilitaire','F', 2006, null],
            ['mercedes-benz', 'Vito',     'W447', 'utilitaire','D', 2014, null],

            // ─── BMW ───────────────────────────────────────────────────
            ['bmw', 'Série 1',      'F20',  'compacte',  'C', 2011, 2019],
            ['bmw', 'Série 3',      'G20',  'berline',   'D', 2018, null],
            ['bmw', 'Série 3',      'F30',  'berline',   'D', 2011, 2018],
            ['bmw', 'Série 5',      'G30',  'berline',   'E', 2016, null],
            ['bmw', 'Série 5',      'F10',  'berline',   'E', 2009, 2016],
            ['bmw', 'X1',           'F48',  'suv',       'C', 2015, null],
            ['bmw', 'X3',           'G01',  'suv',       'D', 2017, null],
            ['bmw', 'X5',           'G05',  'suv',       'E', 2018, null],
            ['bmw', 'X5',           'F15',  'suv',       'E', 2013, 2018],

            // ─── FORD ──────────────────────────────────────────────────
            ['ford', 'Focus',       'Mk3',  'compacte',  'C', 2011, 2018],
            ['ford', 'Ranger',      'T6',   'pick-up',   'D', 2011, null],
            ['ford', 'Everest',     null,   'suv',       'E', 2015, null],
            ['ford', 'EcoSport',    null,   'suv',       'B', 2012, null],
            ['ford', 'Explorer',    'U625', 'suv',       'E', 2019, null],

            // ─── CHEVROLET ─────────────────────────────────────────────
            ['chevrolet', 'Spark',   'M300','citadine',  'A', 2009, 2015],
            ['chevrolet', 'Aveo',    null,  'berline',   'B', 2011, 2016],
            ['chevrolet', 'Cruze',   'J300','berline',   'C', 2009, 2016],
            ['chevrolet', 'Captiva', null,  'suv',       'D', 2006, 2018],
            ['chevrolet', 'Trailblazer', null,'suv',     'D', 2012, null],

            // ─── JEEP ──────────────────────────────────────────────────
            ['jeep', 'Wrangler',    'JL',   'tout-terrain','D', 2018, null],
            ['jeep', 'Grand Cherokee','WK2','suv',       'E', 2010, 2021],
            ['jeep', 'Cherokee',    'KL',   'suv',       'D', 2013, null],
            ['jeep', 'Compass',     'MP',   'suv',       'C', 2017, null],

            // ─── LAND ROVER ────────────────────────────────────────────
            ['land-rover', 'Discovery',       'L462', 'suv',   'F', 2016, null],
            ['land-rover', 'Discovery Sport', 'L550', 'suv',   'D', 2014, null],
            ['land-rover', 'Range Rover',     'L405', 'suv',   'F', 2012, null],
            ['land-rover', 'Range Rover Sport','L494','suv',   'E', 2013, null],
            ['land-rover', 'Defender',        'L663', 'tout-terrain','E', 2019, null],

            // ─── ISUZU ─────────────────────────────────────────────────
            ['isuzu', 'D-Max',      'TFR',  'pick-up',   'D', 2012, null],
            ['isuzu', 'MU-X',       null,   'suv',       'D', 2013, null],

            // ─── MAZDA ─────────────────────────────────────────────────
            ['mazda', 'Mazda2',     'DJ',   'citadine',  'B', 2014, null],
            ['mazda', 'Mazda3',     'BP',   'compacte',  'C', 2018, null],
            ['mazda', 'CX-5',       'KF',   'suv',       'C', 2017, null],
            ['mazda', 'CX-3',       null,   'suv',       'B', 2015, null],
            ['mazda', 'BT-50',      null,   'pick-up',   'D', 2011, null],

            // ─── SUBARU ────────────────────────────────────────────────
            ['subaru', 'Forester',  'SK',   'suv',       'C', 2018, null],
            ['subaru', 'Outback',   'BT',   'break',     'D', 2020, null],
            ['subaru', 'XV',        'GT',   'suv',       'C', 2017, null],
            ['subaru', 'Impreza',   'GK',   'berline',   'C', 2016, null],

            // ─── DAIHATSU ──────────────────────────────────────────────
            ['daihatsu', 'Terios',  'J210', 'suv',       'B', 2006, null],
            ['daihatsu', 'Sirion',  null,   'citadine',  'A', 2005, null],
        ];

        foreach ($models as [$brandSlug, $name, $generation, $bodyType, $segment, $yearStart, $yearEnd]) {
            $brandId = $brandMap[$brandSlug] ?? null;
            if (! $brandId) {
                continue;
            }

            // Un même modèle peut avoir plusieurs générations — slug inclut la génération
            $slug = Str::slug($name . ($generation ? '-' . $generation : ''));

            DB::table('vehicle_models')->upsert([
                'brand_id'         => $brandId,
                'name'             => $name,
                'slug'             => $slug,
                'generation'       => $generation,
                'body_type'        => $bodyType,
                'segment'          => $segment,
                'production_start' => $yearStart,
                'production_end'   => $yearEnd,
                'is_active'        => true,
            ], ['brand_id', 'slug'], ['name', 'generation', 'body_type', 'segment', 'production_start', 'production_end', 'is_active']);
        }
    }
}
