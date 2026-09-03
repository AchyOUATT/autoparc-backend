<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TrimsSeeder extends Seeder
{
    public function run(): void
    {
        $modelMap    = DB::table('vehicle_models')->get()->keyBy(fn($m) => $m->brand_id . '_' . $m->slug);
        $brandMap    = DB::table('brands')->pluck('id', 'slug');
        $engineMap   = DB::table('engine_types')->pluck('id', 'code');
        $driveMap    = DB::table('drivetrains')->pluck('id', 'code');

        // Résolution: brand_slug + model_slug → model_id
        $resolve = function (string $brandSlug, string $modelSlug) use ($brandMap, $modelMap): ?int {
            $brandId = $brandMap[$brandSlug] ?? null;
            if (! $brandId) return null;
            $key = $brandId . '_' . $modelSlug;
            return $modelMap[$key]->id ?? null;
        };

        $petrol   = $engineMap['petrol']   ?? null;
        $diesel   = $engineMap['diesel']   ?? null;
        $hybrid   = $engineMap['hybrid']   ?? null;
        $electric = $engineMap['electric'] ?? null;
        $fwd = $driveMap['FWD'] ?? null;
        $rwd = $driveMap['RWD'] ?? null;
        $awd = $driveMap['AWD'] ?? null;
        $dwa = $driveMap['4WD'] ?? null;

        // Structure: [brand_slug, model_slug, name, code, rank, engine_type, drivetrain, power_hp, cc, cylinders, tx]
        // tx: 'manual' | 'automatic' | 'cvt'
        $trims = [
            // ── TOYOTA COROLLA E210 ──────────────────────────────────────
            ['toyota', 'corolla-e210', 'LE',       'LE',   1, $petrol, $fwd, 139, 1987, 4, 'cvt'],
            ['toyota', 'corolla-e210', 'SE',       'SE',   2, $petrol, $fwd, 169, 2487, 4, 'cvt'],
            ['toyota', 'corolla-e210', 'XLE',      'XLE',  3, $petrol, $fwd, 169, 2487, 4, 'cvt'],
            ['toyota', 'corolla-e210', 'XSE',      'XSE',  4, $petrol, $fwd, 169, 2487, 4, 'automatic'],

            // ── TOYOTA COROLLA E180 ──────────────────────────────────────
            ['toyota', 'corolla-e180', 'J',        'J',    1, $petrol, $fwd, 97,  1329, 4, 'manual'],
            ['toyota', 'corolla-e180', 'L',        'L',    2, $petrol, $fwd, 132, 1798, 4, 'manual'],
            ['toyota', 'corolla-e180', 'GL',       'GL',   3, $petrol, $fwd, 132, 1798, 4, 'automatic'],
            ['toyota', 'corolla-e180', 'GLi',      'GLi',  4, $petrol, $fwd, 132, 1798, 4, 'automatic'],

            // ── TOYOTA HILUX AN120 ───────────────────────────────────────
            ['toyota', 'hilux-an120', 'J',         'J',    1, $diesel, $dwa, 110, 2393, 4, 'manual'],
            ['toyota', 'hilux-an120', 'E',         'E',    2, $diesel, $dwa, 150, 2393, 4, 'manual'],
            ['toyota', 'hilux-an120', 'G',         'G',    3, $diesel, $dwa, 150, 2393, 4, 'automatic'],
            ['toyota', 'hilux-an120', 'GR-S',      'GRS',  4, $petrol, $dwa, 278, 3456, 6, 'automatic'],

            // ── TOYOTA LAND CRUISER J200 ─────────────────────────────────
            ['toyota', 'land-cruiser-j200', 'GX',  'GX',   1, $diesel, $dwa, 204, 4461, 8, 'automatic'],
            ['toyota', 'land-cruiser-j200', 'VX',  'VX',   2, $diesel, $dwa, 204, 4461, 8, 'automatic'],
            ['toyota', 'land-cruiser-j200', 'VX-R','VXR',  3, $petrol, $dwa, 318, 4608, 8, 'automatic'],

            // ── TOYOTA LAND CRUISER PRADO J150 ───────────────────────────
            ['toyota', 'land-cruiser-prado-j150', 'GX',   'GX',  1, $diesel, $dwa, 177, 2755, 4, 'manual'],
            ['toyota', 'land-cruiser-prado-j150', 'GXL',  'GXL', 2, $diesel, $dwa, 177, 2755, 4, 'automatic'],
            ['toyota', 'land-cruiser-prado-j150', 'TXL',  'TXL', 3, $petrol, $dwa, 270, 3956, 6, 'automatic'],
            ['toyota', 'land-cruiser-prado-j150', 'VXL',  'VXL', 4, $petrol, $dwa, 270, 3956, 6, 'automatic'],

            // ── TOYOTA FORTUNER AN160 ────────────────────────────────────
            ['toyota', 'fortuner-an160', 'GX',     'GX',   1, $diesel, $rwd, 150, 2393, 4, 'manual'],
            ['toyota', 'fortuner-an160', 'GXR',    'GXR',  2, $diesel, $rwd, 204, 2755, 4, 'automatic'],
            ['toyota', 'fortuner-an160', 'VXR',    'VXR',  3, $diesel, $dwa, 204, 2755, 4, 'automatic'],
            ['toyota', 'fortuner-an160', 'Legender','LEG',  4, $petrol, $awd, 231, 2694, 4, 'automatic'],

            // ── TOYOTA RAV4 XA50 ─────────────────────────────────────────
            ['toyota', 'rav4-xa50', 'LE',          'LE',   1, $petrol, $fwd, 203, 2487, 4, 'automatic'],
            ['toyota', 'rav4-xa50', 'XLE',         'XLE',  2, $petrol, $fwd, 203, 2487, 4, 'automatic'],
            ['toyota', 'rav4-xa50', 'TRD Off-Road','TRD',  3, $petrol, $awd, 203, 2487, 4, 'automatic'],
            ['toyota', 'rav4-xa50', 'Hybrid XSE',  'HYB',  4, $hybrid, $awd, 219, 2487, 4, 'cvt'],

            // ── TOYOTA YARIS XP210 ───────────────────────────────────────
            ['toyota', 'yaris-xp210', 'E',         'E',    1, $petrol, $fwd, 72,  998,  3, 'manual'],
            ['toyota', 'yaris-xp210', 'J',         'J',    2, $petrol, $fwd, 72,  998,  3, 'cvt'],
            ['toyota', 'yaris-xp210', 'G',         'G',    3, $petrol, $fwd, 116, 1490, 3, 'cvt'],
            ['toyota', 'yaris-xp210', 'GR Sport',  'GR',   4, $hybrid, $fwd, 115, 1490, 3, 'cvt'],

            // ── NISSAN PATROL Y61 ────────────────────────────────────────
            ['nissan', 'patrol-y61', 'DX',         'DX',   1, $diesel, $dwa, 130, 2953, 6, 'manual'],
            ['nissan', 'patrol-y61', 'SE',         'SE',   2, $petrol, $dwa, 170, 2997, 6, 'automatic'],
            ['nissan', 'patrol-y61', 'GRX',        'GRX',  3, $petrol, $dwa, 202, 3956, 6, 'automatic'],

            // ── NISSAN NAVARA D23 ────────────────────────────────────────
            ['nissan', 'navara-d23', 'S',          'S',    1, $diesel, $rwd, 160, 2298, 4, 'manual'],
            ['nissan', 'navara-d23', 'SL',         'SL',   2, $diesel, $dwa, 190, 2298, 4, 'manual'],
            ['nissan', 'navara-d23', 'SV',         'SV',   3, $diesel, $dwa, 190, 2298, 4, 'automatic'],
            ['nissan', 'navara-d23', 'PRO-4X',     'PRO',  4, $diesel, $dwa, 190, 2298, 4, 'automatic'],

            // ── NISSAN X-TRAIL T32 ───────────────────────────────────────
            ['nissan', 'x-trail-t32', 'Visia',     'VIS',  1, $petrol, $fwd, 132, 1597, 4, 'manual'],
            ['nissan', 'x-trail-t32', 'Acenta',    'ACE',  2, $petrol, $fwd, 163, 1997, 4, 'cvt'],
            ['nissan', 'x-trail-t32', 'Tekna',     'TEK',  3, $diesel, $awd, 177, 1597, 4, 'cvt'],

            // ── HYUNDAI TUCSON TL ────────────────────────────────────────
            ['hyundai', 'tucson-tl', 'Active',     'ACT',  1, $petrol, $fwd, 132, 1591, 4, 'manual'],
            ['hyundai', 'tucson-tl', 'Active X',   'AXP',  2, $diesel, $fwd, 115, 1685, 4, 'manual'],
            ['hyundai', 'tucson-tl', 'Elite',      'ELI',  3, $petrol, $awd, 177, 1999, 4, 'automatic'],
            ['hyundai', 'tucson-tl', 'Highlander', 'HLD',  4, $diesel, $awd, 177, 1685, 4, 'automatic'],

            // ── HYUNDAI SANTA FE TM ──────────────────────────────────────
            ['hyundai', 'santa-fe-tm', 'Essential', 'ESS', 1, $petrol, $fwd, 188, 2359, 4, 'automatic'],
            ['hyundai', 'santa-fe-tm', 'Comfort',   'COM', 2, $diesel, $awd, 202, 2199, 4, 'automatic'],
            ['hyundai', 'santa-fe-tm', 'Premium',   'PRE', 3, $diesel, $awd, 202, 2199, 4, 'automatic'],

            // ── KIA SPORTAGE QL ──────────────────────────────────────────
            ['kia', 'sportage-ql', 'LX',           'LX',   1, $petrol, $fwd, 132, 1591, 4, 'manual'],
            ['kia', 'sportage-ql', 'EX',           'EX',   2, $petrol, $fwd, 177, 1999, 4, 'automatic'],
            ['kia', 'sportage-ql', 'SX',           'SX',   3, $diesel, $awd, 182, 1685, 4, 'automatic'],
            ['kia', 'sportage-ql', 'GT Line',      'GTL',  4, $diesel, $awd, 182, 1685, 4, 'automatic'],

            // ── MITSUBISHI PAJERO V80 ────────────────────────────────────
            ['mitsubishi', 'pajero-v80', 'GLX',    'GLX',  1, $diesel, $dwa, 170, 3200, 4, 'manual'],
            ['mitsubishi', 'pajero-v80', 'GLS',    'GLS',  2, $diesel, $dwa, 200, 3200, 4, 'automatic'],
            ['mitsubishi', 'pajero-v80', 'Exceed', 'EXC',  3, $petrol, $dwa, 250, 3828, 6, 'automatic'],

            // ── MITSUBISHI L200 KL ───────────────────────────────────────
            ['mitsubishi', 'l200-kl', 'Invite',    'INV',  1, $diesel, $rwd, 150, 2268, 4, 'manual'],
            ['mitsubishi', 'l200-kl', 'GL',        'GL',   2, $diesel, $dwa, 150, 2268, 4, 'manual'],
            ['mitsubishi', 'l200-kl', 'GLS',       'GLS',  3, $diesel, $dwa, 150, 2268, 4, 'automatic'],
            ['mitsubishi', 'l200-kl', 'Athlete',   'ATH',  4, $diesel, $dwa, 181, 2268, 4, 'automatic'],

            // ── PEUGEOT 3008 ─────────────────────────────────────────────
            ['peugeot', '3008', 'Active',          'ACT',  1, $petrol, $fwd, 130, 1199, 3, 'manual'],
            ['peugeot', '3008', 'Allure',          'ALL',  2, $petrol, $fwd, 130, 1199, 3, 'automatic'],
            ['peugeot', '3008', 'GT Line',         'GTL',  3, $diesel, $fwd, 130, 1499, 4, 'automatic'],
            ['peugeot', '3008', 'GT',              'GT',   4, $petrol, $awd, 225, 1598, 4, 'automatic'],

            // ── RENAULT DUSTER ───────────────────────────────────────────
            ['renault', 'duster', 'Life',          'LIF',  1, $petrol, $fwd, 115, 1598, 4, 'manual'],
            ['renault', 'duster', 'Zen',           'ZEN',  2, $petrol, $fwd, 115, 1598, 4, 'manual'],
            ['renault', 'duster', 'Intens',        'INT',  3, $diesel, $fwd, 115, 1461, 4, 'manual'],
            ['renault', 'duster', 'Prestige 4x4',  'PRE',  4, $diesel, $dwa, 115, 1461, 4, 'manual'],

            // ── VOLKSWAGEN TIGUAN ────────────────────────────────────────
            ['volkswagen', 'tiguan-ad', 'Trendline', 'TRE', 1, $petrol, $fwd, 125, 1395, 4, 'manual'],
            ['volkswagen', 'tiguan-ad', 'Comfortline','COM', 2, $petrol, $fwd, 150, 1395, 4, 'automatic'],
            ['volkswagen', 'tiguan-ad', 'Highline',  'HIG', 3, $diesel, $awd, 150, 1968, 4, 'automatic'],
            ['volkswagen', 'tiguan-ad', 'R-Line',    'RLI', 4, $petrol, $awd, 220, 1984, 4, 'automatic'],

            // ── FORD RANGER T6 ───────────────────────────────────────────
            ['ford', 'ranger-t6', 'XL',            'XL',   1, $diesel, $rwd, 150, 2198, 4, 'manual'],
            ['ford', 'ranger-t6', 'XLT',           'XLT',  2, $diesel, $dwa, 170, 3198, 4, 'manual'],
            ['ford', 'ranger-t6', 'Wildtrak',      'WDT',  3, $diesel, $dwa, 213, 3198, 4, 'automatic'],
            ['ford', 'ranger-t6', 'Raptor',        'RAP',  4, $petrol, $dwa, 213, 2689, 6, 'automatic'],

            // ── ISUZU D-MAX ──────────────────────────────────────────────
            ['isuzu', 'd-max-tfr', 'LS',           'LS',   1, $diesel, $rwd, 130, 2499, 4, 'manual'],
            ['isuzu', 'd-max-tfr', 'LT',           'LT',   2, $diesel, $dwa, 163, 2999, 4, 'manual'],
            ['isuzu', 'd-max-tfr', 'LS Premium',   'LSP',  3, $diesel, $dwa, 190, 2999, 4, 'automatic'],
            ['isuzu', 'd-max-tfr', 'V-Cross 4x4',  'VCR',  4, $diesel, $dwa, 163, 2999, 4, 'automatic'],

            // ── JEEP WRANGLER JL ─────────────────────────────────────────
            ['jeep', 'wrangler-jl', 'Sport',       'SPT',  1, $petrol, $dwa, 285, 3604, 6, 'manual'],
            ['jeep', 'wrangler-jl', 'Sahara',      'SAH',  2, $petrol, $dwa, 285, 3604, 6, 'automatic'],
            ['jeep', 'wrangler-jl', 'Rubicon',     'RUB',  3, $petrol, $dwa, 285, 3604, 6, 'automatic'],

            // ── LAND ROVER DISCOVERY L462 ────────────────────────────────
            ['land-rover', 'discovery-l462', 'S',  'S',    1, $diesel, $awd, 249, 2993, 6, 'automatic'],
            ['land-rover', 'discovery-l462', 'SE', 'SE',   2, $diesel, $awd, 302, 2993, 6, 'automatic'],
            ['land-rover', 'discovery-l462', 'HSE','HSE',  3, $petrol, $awd, 340, 2995, 6, 'automatic'],

            // ── MERCEDES CLASSE C W205 ───────────────────────────────────
            ['mercedes-benz', 'classe-c-w205', 'C 180',    'C180', 1, $petrol, $rwd, 156, 1595, 4, 'automatic'],
            ['mercedes-benz', 'classe-c-w205', 'C 200',    'C200', 2, $petrol, $rwd, 184, 1991, 4, 'automatic'],
            ['mercedes-benz', 'classe-c-w205', 'C 220d',   'C220D',3, $diesel, $rwd, 194, 1950, 4, 'automatic'],
            ['mercedes-benz', 'classe-c-w205', 'C 300 4M', 'C3004',4, $petrol, $awd, 258, 1991, 4, 'automatic'],

            // ── BMW SÉRIE 3 G20 ──────────────────────────────────────────
            ['bmw', 'serie-3-g20', '318i',         '318i', 1, $petrol, $rwd, 156, 1499, 4, 'automatic'],
            ['bmw', 'serie-3-g20', '320i',         '320i', 2, $petrol, $rwd, 184, 1998, 4, 'automatic'],
            ['bmw', 'serie-3-g20', '320d',         '320d', 3, $diesel, $rwd, 190, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-g20', '330i xDrive',  '330X', 4, $petrol, $awd, 258, 1998, 4, 'automatic'],
        ];

        $txMap = [
            'manual'    => 'manual',
            'automatic' => 'automatic',
            'cvt'       => 'cvt',
        ];

        foreach ($trims as [$brandSlug, $modelSlug, $name, $code, $rank, $engineId, $driveId, $hp, $cc, $cyl, $tx]) {
            $modelId = $resolve($brandSlug, $modelSlug);
            if (! $modelId) {
                continue;
            }

            // Évite les doublons sur (vehicle_model_id, name)
            $exists = DB::table('trims')
                ->where('vehicle_model_id', $modelId)
                ->where('name', $name)
                ->exists();

            if (! $exists) {
                DB::table('trims')->insert([
                    'vehicle_model_id'     => $modelId,
                    'name'                 => $name,
                    'code'                 => $code,
                    'rank'                 => $rank,
                    'default_engine_type_id' => $engineId,
                    'default_drivetrain_id'  => $driveId,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }
        }
    }
}
