<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ColorsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // Blancs / Gris / Noirs
            ['name' => 'Blanc',            'hex_code' => '#FFFFFF', 'finish' => 'opaque'],
            ['name' => 'Blanc nacré',      'hex_code' => '#F5F4EE', 'finish' => 'nacre'],
            ['name' => 'Gris clair',       'hex_code' => '#C8C8C8', 'finish' => 'metallise'],
            ['name' => 'Gris anthracite',  'hex_code' => '#4A4A4A', 'finish' => 'metallise'],
            ['name' => 'Gris titanium',    'hex_code' => '#6E7070', 'finish' => 'metallise'],
            ['name' => 'Argent',           'hex_code' => '#A8A9AD', 'finish' => 'metallise'],
            ['name' => 'Noir',             'hex_code' => '#1A1A1A', 'finish' => 'opaque'],
            ['name' => 'Noir métallisé',   'hex_code' => '#1C1C1E', 'finish' => 'metallise'],

            // Rouges
            ['name' => 'Rouge',            'hex_code' => '#CC0000', 'finish' => 'opaque'],
            ['name' => 'Rouge métallisé',  'hex_code' => '#8B0000', 'finish' => 'metallise'],
            ['name' => 'Bordeaux',         'hex_code' => '#5C1A1A', 'finish' => 'metallise'],

            // Bleus
            ['name' => 'Bleu marine',      'hex_code' => '#003366', 'finish' => 'opaque'],
            ['name' => 'Bleu métallisé',   'hex_code' => '#4169A0', 'finish' => 'metallise'],
            ['name' => 'Bleu ciel',        'hex_code' => '#87CEEB', 'finish' => 'opaque'],

            // Verts
            ['name' => 'Vert foncé',       'hex_code' => '#2D5A27', 'finish' => 'metallise'],
            ['name' => 'Vert olive',       'hex_code' => '#6B7A3D', 'finish' => 'opaque'],

            // Autres
            ['name' => 'Beige',            'hex_code' => '#D4C5A9', 'finish' => 'opaque'],
            ['name' => 'Marron',           'hex_code' => '#6B3A2A', 'finish' => 'metallise'],
            ['name' => 'Or / Champagne',   'hex_code' => '#C5A55A', 'finish' => 'metallise'],
            ['name' => 'Orange',           'hex_code' => '#E8600A', 'finish' => 'opaque'],
            ['name' => 'Jaune',            'hex_code' => '#FFD600', 'finish' => 'opaque'],
            ['name' => 'Violet',           'hex_code' => '#5B2A8C', 'finish' => 'metallise'],
        ];

        foreach ($rows as $row) {
            DB::table('colors')->upsert($row, ['name'], ['hex_code', 'finish']);
        }
    }
}
