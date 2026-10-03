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
            ['toyota', 'Rush',         'F800',    'suv',       'B', 2017, null],
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
            ['mitsubishi', 'Eclipse Cross', 'GK/GL', 'suv',     'C', 2017, null],
            ['mitsubishi', 'L200',        'KL',   'pick-up', 'D', 2015, null],
            ['mitsubishi', 'L200',        'KB',   'pick-up', 'D', 2005, 2015],

            // ─── SUZUKI ────────────────────────────────────────────────
            ['suzuki', 'Alto',          'HA36',  'citadine',  'A', 2015, null],
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
            ['kia', 'Stonic',        'YB CUV',  'suv',       'B', 2017, null],
            ['kia', 'Seltos',        'SP2',  'suv',       'B', 2019, null],

            // ─── PEUGEOT ───────────────────────────────────────────────
            ['peugeot', '207',       null,  'citadine',  'B', 2006, 2012],
            ['peugeot', '208',       null,  'citadine',  'B', 2012, null],
            ['peugeot', '301',       'M33',  'berline',   'C', 2012, null],
            ['peugeot', '307',       'T5',  'compacte',  'C', 2001, 2008],
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
            ['ford', 'EcoSport',    'B515 / II',   'suv',       'B', 2012, null],
            ['ford', 'Explorer',    'U625', 'suv',       'E', 2019, null],

            // ─── CHEVROLET ─────────────────────────────────────────────
            ['chevrolet', 'Spark',   'M300','citadine',  'A', 2009, 2015],
            ['chevrolet', 'Aveo',    'T300',  'berline',   'B', 2011, 2016],
            ['chevrolet', 'Cruze',   'J300','berline',   'C', 2009, 2016],
            ['chevrolet', 'Captiva', null,  'suv',       'D', 2006, 2018],
            ['chevrolet', 'Trailblazer', 'RG','suv',     'D', 2012, null],

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
            // Seule la BP existait : un Mazda3 de 2014 n'avait donc aucune fiche
            // correcte a choisir, et le decodage VIN le rattachait a la generation
            // suivante faute de mieux — alors que le VIN porte « BM » en clair.
            ['mazda', 'Mazda3',     'BM',   'compacte',  'C', 2013, 2018],
            ['mazda', 'Mazda3',     'BL',   'compacte',  'C', 2008, 2013],
            ['mazda', 'CX-5',       'KF',   'suv',       'C', 2017, null],
            ['mazda', 'CX-3',       'DK',   'suv',       'B', 2015, null],
            ['mazda', 'BT-50',      null,   'pick-up',   'D', 2011, null],

            // Ajoutes apres un blocage : un CX-9 ne pouvait pas etre
            // enregistre, faute de fiche. Le modele n'a jamais ete vendu en
            // Europe ni au Japon — il n'arrive que des Etats-Unis et du Golfe,
            // d'ou son absence d'un referentiel bati sur les flux europeens.
            ['mazda', 'CX-9',       'TB',   'suv',       'E', 2006, 2015],
            ['mazda', 'CX-9',       'TC',   'suv',       'E', 2016, 2024],
            ['mazda', 'CX-7',       'ER',   'suv',       'D', 2006, 2012],
            ['mazda', 'CX-30',      'DM',   'suv',       'C', 2019, null],

            // Une ligne par epoque, et non par carrosserie.
            //
            // Les breaks ont leur propre code chez Mazda — GY face au GG, GZ
            // face au GH — mais ils couvrent exactement les memes annees. Deux
            // lignes par epoque violeraient la regle de non-chevauchement que
            // CatalogueModelesTest fait respecter : le rattachement des cotes
            // de consommation choisit la generation produite l'annee du
            // vehicule, et deux candidates rendraient ce choix arbitraire.
            //
            // La carrosserie n'est donc pas un element d'identite ici. Un
            // proprietaire de break choisit « Mazda6 » de son epoque, et la
            // compatibilite des pieces ne s'en trouve pas changee : berline et
            // break partagent les memes moteurs.
            //
            // Les bornes se touchent sans se recouvrir, comme pour la Mazda3.
            ['mazda', 'Mazda6',     'GG',   'berline',   'D', 2002, 2008],
            ['mazda', 'Mazda6',     'GH',   'berline',   'D', 2008, 2012],
            ['mazda', 'Mazda6',     'GJ',   'berline',   'D', 2012, 2024],

            // Deux noms pour un meme vehicule : Premacy au Japon, Mazda5 a
            // l'export. Les deux figurent sur les cartes grises des vehicules
            // importes, d'ou le libelle double — c'est exactement le detail qui
            // fait dire « je ne trouve pas mon modele ».
            ['mazda', 'Mazda5 / Premacy', 'CP', 'monospace', 'C', 1999, 2004],
            ['mazda', 'Mazda5 / Premacy', 'CR', 'monospace', 'C', 2004, 2010],
            ['mazda', 'Mazda5 / Premacy', 'CW', 'monospace', 'C', 2010, 2018],

            // La premiere generation s'arrete en 2006 et non en 2010 : la
            // borne large chevauchait la seconde, et un Tribute de 2008 aurait
            // remonte sur les deux fiches.
            ['mazda', 'Tribute',    'EP',   'suv',       'C', 2000, 2006],
            ['mazda', 'Tribute',    'II (marché NA)', 'suv', 'C', 2007, 2011],

            // ─── SUBARU ────────────────────────────────────────────────
            ['subaru', 'Forester',  'SK',   'suv',       'C', 2018, null],
            ['subaru', 'Outback',   'BT',   'break',     'D', 2020, null],
            ['subaru', 'XV',        'GT',   'suv',       'C', 2017, null],
            // Le Crosstrek succede au XV et en reprend le nom de marche nord-americain.
            // Ligne separee : le XV GT reste la ligne des vehicules deja en circulation.
            ['subaru', 'Crosstrek', 'GU / III', 'suv', 'C', 2023, null],
            ['subaru', 'Impreza',   'GK',   'berline',   'C', 2016, null],

            // ─── DAIHATSU ──────────────────────────────────────────────
            ['daihatsu', 'Terios',  'J210', 'suv',       'B', 2006, null],
            ['daihatsu', 'Sirion',  null,   'citadine',  'A', 2005, null],

            // ─── LEXUS ─────────────────────────────────────────────────
            //
            // Cinq marques figuraient dans la liste sans le moindre modele :
            // Lexus, SsangYong, Citroen, Opel et Fiat. Un proprietaire de
            // Lexus arrivait donc sur une liste vide, sans pouvoir ajouter son
            // vehicule — alors que 584 cotes de consommation Lexus attendaient
            // en base, rattachees a rien.
            //
            // Les millesimes delimitent les generations, et c'est sur eux que
            // repose le rattachement des cotes : une cote de 2013 ne doit pas
            // rejoindre une generation arretee en 2011.
            ['lexus', 'RX',  'AL30',  'suv',      'E', 2022, null],
            ['lexus', 'RX',  'AL20',  'suv',      'E', 2015, 2022],
            ['lexus', 'RX',  'AL10',  'suv',      'E', 2009, 2015],
            ['lexus', 'RX',  'XU30',  'suv',      'E', 2003, 2009],
            ['lexus', 'ES',  'XV70',  'berline',  'E', 2018, null],
            ['lexus', 'ES',  'XV60',  'berline',  'E', 2012, 2018],
            ['lexus', 'ES',  'XV40',  'berline',  'E', 2006, 2012],
            ['lexus', 'IS',  'XE30',  'berline',  'D', 2013, null],
            ['lexus', 'IS',  'XE20',  'berline',  'D', 2005, 2013],
            ['lexus', 'GS',  'L10',   'berline',  'E', 2012, 2020],
            ['lexus', 'GS',  'S190',  'berline',  'E', 2005, 2012],
            ['lexus', 'LS',  'XF50',  'berline',  'F', 2017, null],
            ['lexus', 'LS',  'XF40',  'berline',  'F', 2006, 2017],
            ['lexus', 'LX',  'J310',  'suv',      'F', 2021, null],
            ['lexus', 'LX',  'J200',  'suv',      'F', 2007, 2021],
            ['lexus', 'GX',  'J250',  'suv',      'E', 2023, null],
            ['lexus', 'GX',  'J150',  'suv',      'E', 2009, 2023],
            ['lexus', 'GX',  'J120',  'suv',      'E', 2002, 2009],
            ['lexus', 'NX',  'AZ20',  'suv',      'C', 2021, null],
            ['lexus', 'NX',  'AZ10',  'suv',      'C', 2014, 2021],
            ['lexus', 'UX',  'ZA10',    'suv',      'B', 2018, null],
            ['lexus', 'CT',  'ZWA10',    'compacte', 'C', 2010, 2022],
            ['lexus', 'RC',  'XC10',    'coupe',    'D', 2014, null],

            // ─── CITROËN ───────────────────────────────────────────────
            ['citroen', 'C3',          'III', 'citadine',  'B', 2016, 2024],
            ['citroen', 'C3',          'II',  'citadine',  'B', 2009, 2016],
            ['citroen', 'C4',          'III', 'compacte',  'C', 2020, null],
            ['citroen', 'C4',          'II',  'compacte',  'C', 2010, 2018],
            ['citroen', 'C4',          'I',   'compacte',  'C', 2004, 2010],
            ['citroen', 'C-Elysée',    null,  'berline',   'C', 2012, 2023],
            ['citroen', 'Berlingo',    'K9',  'monospace', 'C', 2018, null],
            ['citroen', 'Berlingo',    'B9',  'monospace', 'C', 2008, 2018],
            ['citroen', 'C5 Aircross', null,  'suv',       'D', 2018, null],
            ['citroen', 'Jumper',      null,  'utilitaire','—', 2006, null],

            // ─── OPEL ──────────────────────────────────────────────────
            ['opel', 'Corsa',  'F',   'citadine', 'B', 2019, null],
            ['opel', 'Corsa',  'E',   'citadine', 'B', 2014, 2019],
            ['opel', 'Corsa',  'D',   'citadine', 'B', 2006, 2014],
            ['opel', 'Astra',  'K',   'compacte', 'C', 2015, 2021],
            ['opel', 'Astra',  'J',   'compacte', 'C', 2009, 2015],
            ['opel', 'Mokka',  'B',   'suv',      'B', 2020, null],
            ['opel', 'Mokka',  'A',   'suv',      'B', 2012, 2019],
            ['opel', 'Zafira', 'C',   'monospace','C', 2011, 2019],
            ['opel', 'Zafira', 'B',   'monospace','C', 2005, 2011],
            // Le Zafira Life est un utilitaire derive du Vivaro, sans rapport de
            // pieces avec le monospace Zafira : deux lignes distinctes.
            ['opel', 'Zafira Life', 'K0', 'monospace','C', 2019, null],

            // ─── FIAT ──────────────────────────────────────────────────
            ['fiat', '500',    'Type 312',  'citadine',  'A', 2007, null],
            ['fiat', '500X',   'Type 334',  'suv',       'B', 2014, null],
            ['fiat', 'Panda',  'III', 'citadine',  'A', 2011, null],
            ['fiat', 'Punto',  null,  'citadine',  'B', 2005, 2018],
            ['fiat', 'Tipo',   '356',  'compacte',  'C', 2015, null],
            // La 500e est un modele a part, pas une version de la 500 : plateforme
            // differente, pieces differentes. La ranger sous la 500 ferait proposer
            // des references thermiques a une electrique.
            ['fiat', '500e',   '332',  'citadine',  'A', 2020, null],
            ['fiat', 'Doblo',  'II',  'monospace', 'C', 2010, 2022],
            ['fiat', 'Ducato', null,  'utilitaire','—', 2006, null],

            // ─── SSANGYONG ─────────────────────────────────────────────
            ['ssangyong', 'Korando', 'C300', 'suv',     'C', 2019, null],
            ['ssangyong', 'Korando', 'C200', 'suv',     'C', 2010, 2019],
            ['ssangyong', 'Rexton',  'Y400', 'suv',     'E', 2017, null],
            ['ssangyong', 'Rexton',  'Y200', 'suv',     'E', 2001, 2017],
            ['ssangyong', 'Musso',   'Q200/Q250',   'pick-up', 'D', 2018, null],
            ['ssangyong', 'Actyon',  'C100', 'suv',  'D', 2006, 2012],
            // L'Actyon Sports est le pick-up ; l'Actyon tout court est le SUV.
            // Les deux etaient confondus sur une seule ligne classee pick-up.
            ['ssangyong', 'Actyon Sports', 'Q100', 'pick-up', 'D', 2006, 2012],
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
