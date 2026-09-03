<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeaturesSeeder extends Seeder
{
    public function run(): void
    {
        // Supprimer les équipements chauffants et les anciens noms remplacés
        DB::table('features')->whereIn('name', [
            'Sièges chauffants avant',
            'Volant chauffant',
            'ISOFIX',
        ])->delete();

        $features = [
            // Confort
            ['name' => 'Climatisation manuelle',            'category' => 'confort'],
            ['name' => 'Climatisation automatique',         'category' => 'confort'],
            ['name' => 'Sièges ventilés',                   'category' => 'confort'],
            ['name' => 'Toit ouvrant',                      'category' => 'confort'],
            ['name' => 'Toit panoramique',                  'category' => 'confort'],
            ['name' => 'Vitres électriques avant',          'category' => 'confort'],
            ['name' => 'Vitres électriques arrière',        'category' => 'confort'],
            ['name' => 'Rétroviseurs électriques',          'category' => 'confort'],
            ['name' => 'Rétroviseurs rabattables électr.', 'category' => 'confort'],
            ['name' => 'Volant réglable en hauteur',        'category' => 'confort'],
            ['name' => 'Siège conducteur électrique',       'category' => 'confort'],
            ['name' => 'Accès et démarrage sans clé',       'category' => 'confort'],
            ['name' => 'Direction assistée électrique',     'category' => 'confort'],

            // Sécurité
            ['name' => 'ABS',                               'category' => 'securite'],
            ['name' => 'ESP / Contrôle de stabilité',       'category' => 'securite'],
            ['name' => 'Aide au freinage d\'urgence (BA)',  'category' => 'securite'],
            ['name' => 'Airbags frontaux',                  'category' => 'securite'],
            ['name' => 'Airbags latéraux',                  'category' => 'securite'],
            ['name' => 'Airbags rideaux',                   'category' => 'securite'],
            ['name' => 'Contrôle de traction (ASR)',        'category' => 'securite'],
            ['name' => 'Aide au démarrage en côte',         'category' => 'securite'],
            ['name' => 'Freins à disques avant',            'category' => 'securite'],
            ['name' => 'Freins à disques 4 roues',          'category' => 'securite'],
            ['name' => 'Ancrage siège enfant',               'category' => 'securite'],

            // Multimédia
            ['name' => 'Autoradio',                         'category' => 'multimedia'],
            ['name' => 'Écran tactile',                     'category' => 'multimedia'],
            ['name' => 'Bluetooth',                         'category' => 'multimedia'],
            ['name' => 'Android Auto',                      'category' => 'multimedia'],
            ['name' => 'Apple CarPlay',                     'category' => 'multimedia'],
            ['name' => 'GPS intégré',                       'category' => 'multimedia'],
            ['name' => 'Prise USB',                         'category' => 'multimedia'],
            ['name' => 'Haut-parleurs premium',             'category' => 'multimedia'],
            ['name' => 'Chargeur à induction',              'category' => 'multimedia'],

            // Dotation standard (triangle, cric, roue de secours…)
            ['name' => 'Triangle de signalisation',         'category' => 'dotation'],
            ['name' => 'Roue de secours',                   'category' => 'dotation'],
            ['name' => 'Cric',                              'category' => 'dotation'],
            ['name' => 'Clé de roue',                       'category' => 'dotation'],
            ['name' => 'Boîte à pharmacie',                 'category' => 'dotation'],
            ['name' => 'Gilet réfléchissant',               'category' => 'dotation'],
            ['name' => 'Câbles de démarrage',               'category' => 'dotation'],
            ['name' => 'Manuel du propriétaire',            'category' => 'dotation'],
            ['name' => 'Carnet d\'entretien',               'category' => 'dotation'],

            // Aide à la conduite
            ['name' => 'Caméra de recul',                   'category' => 'aide_conduite'],
            ['name' => 'Caméra 360°',                       'category' => 'aide_conduite'],
            ['name' => 'Radar de recul',                    'category' => 'aide_conduite'],
            ['name' => 'Radar avant',                       'category' => 'aide_conduite'],
            ['name' => 'Régulateur de vitesse',             'category' => 'aide_conduite'],
            ['name' => 'Régulateur adaptatif (ACC)',        'category' => 'aide_conduite'],
            ['name' => 'Alerte sortie de voie',             'category' => 'aide_conduite'],
            ['name' => 'Détecteur d\'angle mort',           'category' => 'aide_conduite'],
            ['name' => 'Freinage automatique d\'urgence',   'category' => 'aide_conduite'],
            ['name' => 'Affichage tête haute (HUD)',        'category' => 'aide_conduite'],
            ['name' => 'Reconnaissance des panneaux',       'category' => 'aide_conduite'],
        ];

        foreach ($features as $feature) {
            DB::table('features')->upsert($feature, ['name'], ['category']);
        }
    }
}
