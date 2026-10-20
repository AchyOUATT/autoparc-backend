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
            // La S manquait, signalee par le proprietaire sur un cas reel.
            //
            // Les quatre au-dessus — J, GL, GLi — sont des noms de marche
            // GOLFE / ASIE / AFRIQUE, alors que la E210 juste au-dessus porte
            // LE, SE, XLE, XSE, qui sont nord-americains. Ce fichier melange
            // donc deux nomenclatures selon la generation, et c'est ce melange
            // qui laisse des trous : un vehicule importe d'Amerique du Nord ne
            // trouve pas sa finition sur la E180, alors qu'il la trouverait sur
            // la E210.
            //
            // La S est la finition sportive nord-americaine de cette
            // generation : meme 1.8 2ZR-FE que la L et la GL, 132 ch, boite a
            // variation continue. Le reste de la gamme nord-americaine — LE,
            // LE Eco, XLE, XSE, SE — manque toujours et n'est pas pose ici :
            // le proprietaire n'a demande que la S, et une finition inventee se
            // retrouve sur une annonce.
            ['toyota', 'corolla-e180', 'S',        'S',    5, $petrol, $fwd, 132, 1798, 4, 'cvt'],

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

            // ══ BMW : LE RESTE DE LA GAMME ═══════════════════════════════
            //
            // La G20 au-dessus etait le SEUL modele BMW a porter des
            // finitions ; les huit autres n'en avaient aucune. D'ou la
            // remarque du proprietaire selon laquelle « la BMW 528i n'existe
            // pas » : la Serie 5 F10 etait bien au referentiel, aucune de ses
            // versions ne l'etait.
            //
            // CE QUE CE SEEDER ECRIT REELLEMENT. L'`insert` plus bas ne porte
            // que name, code, rank, default_engine_type_id et
            // default_drivetrain_id : la table `trims` n'a pas de colonne de
            // puissance, de cylindree, de cylindres ni de boite. Les quatre
            // dernieres valeurs de chaque ligne ne sont donc PAS stockees :
            // elles documentent la version decrite. Corollaire a connaitre,
            // `$txMap` plus bas est du code mort — declare, jamais lu.
            //
            // Le nom est donc le porteur retenu pour toute distinction qu'un
            // vendeur doit voir, et cela gouverne les deux dedoublements
            // ci-dessous. MAIS IL N'EST PAS LE SEUL POSSIBLE, et le pretendre
            // serait faux : `trims.description` existe en base, `TrimResource`
            // l'expose, et elle est laissee NULL sur les 160 finitions de ce
            // fichier. La distinction six contre quatre cylindres pourrait donc
            // atteindre le vendeur SANS migration ni dedoublement de nom.
            // Ce n'est pas fait ici parce que l'`insert` ne porte pas cette
            // colonne et que l'ajouter demanderait une valeur pour chacune des
            // 160 lignes — un lot a lui seul, et qui vaut d'etre fait : le nom
            // « 528i (4 cyl. 2011-2016) » est explicite mais laid dans une
            // liste deroulante, alors qu'une description le dirait proprement.
            //
            // LE PIEGE DU NOM QUI NE SUIT PLUS LA CYLINDREE. Depuis 2010 une
            // 528i n'a pas de 2,8 l. Trois voitures differentes portent ce
            // nom dans ce fichier, et chacune a sa ligne :
            //   E60 2007-2010 : six cylindres 2996 N52B30, marche americain ;
            //   F10 2010-2011 : six cylindres 2996 N52B30 ;
            //   F10 2011-2016 : QUATRE cylindres 1997 N20B20 turbo, des 09/2011.
            // Les deux F10 rendent la meme puissance — 180 kW —, donc la
            // puissance ne les distingue pas ; seuls la cylindree et le
            // nombre de cylindres le font, et ni l'une ni l'autre ne va en
            // base. Le nom les porte.
            //
            // REGLE APPLIQUEE. Un nom dont le nombre de cylindres change en
            // cours de generation est dedouble, le nom portant la distinction
            // (F10 528i, et F10 525d qui subit la meme rupture six vers
            // quatre). Quand seuls la cylindree ou la mise au point changent
            // a nombre de cylindres constant, une seule ligne, portant la
            // version la plus probable a l'import ici, et le commentaire
            // nomme l'autre.
            //
            // SOURCES. Chaque code d'usine et chaque borne est recoupe sur au
            // moins deux sources — Wikipedia anglais et Wikipedia allemand
            // (redactions independantes), completes par les fiches moteur
            // N52/N20/B48 et, pour le basculement de la 528i, le communique
            // BMW de juillet 2011 relaye par BMWBlog. Les divergences
            // retenues sont nommees ligne par ligne.
            //
            // BOITE. 'automatic' partout, comme les quatre lignes G20
            // existantes : les boites manuelles existaient en Europe sur
            // presque toutes ces versions, mais les voitures qui arrivent
            // d'Amerique du Nord et du Golfe sont automatiques, et c'est de
            // la que vient le parc. Valeur non stockee de toute facon.

            // ── BMW SÉRIE 1 F20 ─────────────────────────────────────────
            // Millesimes d'avant le LCI de 2015 : N13B16 essence 1598 et
            // N47D20 diesel 1995, soit la tranche 2011-2015 qui s'importe
            // aujourd'hui. Apres 2015 BMW passe les 116i et 118i au trois
            // cylindres B38B15 1499 (109 et 136 ch) et les diesels au B47D20
            // (150 et 190 ch) : meme nom, cylindree et cylindres differents.
            // Propulsion de serie sur toute la F20, xDrive seulement en
            // option sur 120d et M135i.
            ['bmw', 'serie-1-f20', '116i',        '116i', 1, $petrol, $rwd, 136, 1598, 4, 'automatic'],
            ['bmw', 'serie-1-f20', '118d',        '118d', 2, $diesel, $rwd, 143, 1995, 4, 'automatic'],
            ['bmw', 'serie-1-f20', '118i',        '118i', 3, $petrol, $rwd, 170, 1598, 4, 'automatic'],
            ['bmw', 'serie-1-f20', '120d',        '120d', 4, $diesel, $rwd, 184, 1995, 4, 'automatic'],

            // ── BMW SÉRIE 3 F30 ─────────────────────────────────────────
            // 318i B38B15 trois cylindres 1499, posterieure au LCI : ce nom
            // n'a jamais designe autre chose sur la F30, la base d'avant 2015
            // etant la 316i. 320i N20B20 1997 puis B48B20 1998, 184 ch dans
            // les deux cas. 320d N47D20 puis B47D20, 1995 : 184 ch jusqu'en
            // 2015, 190 ensuite — la valeur posee est celle du B47, les
            // millesimes tardifs etant ceux qui s'importent. La 328i est la
            // version de volume nord-americaine, remplacee par la 330i au
            // LCI : deux noms, deux lignes, aucune ambiguite.
            ['bmw', 'serie-3-f30', '318i',        '318i', 1, $petrol, $rwd, 136, 1499, 3, 'automatic'],
            ['bmw', 'serie-3-f30', '320i',        '320i', 2, $petrol, $rwd, 184, 1997, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '320d',        '320d', 3, $diesel, $rwd, 190, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '328i',        '328i', 4, $petrol, $rwd, 245, 1997, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '330i',        '330i', 5, $petrol, $rwd, 252, 1998, 4, 'automatic'],
            ['bmw', 'serie-3-f30', '330d',        '330d', 6, $diesel, $rwd, 258, 2993, 6, 'automatic'],

            // ── BMW SÉRIE 3 E90 ─────────────────────────────────────────
            // Versions posterieures au LCI de 09/2008, celles qui dominent le
            // parc importe. 318i et 320i : N43B20 1995, 143 et 170 ch (avant
            // 09/2007, N46B20 de meme cylindree, 129 et 150 ch). 320d N47D20
            // 1995 a 177 ch (M47D20TU2 163 ch avant 09/2007, 184 ch a partir
            // de 03/2010). 325i : N53B30 2996 a partir de 09/2007 contre
            // N52B25 2497 avant, a puissance IDENTIQUE de 218 ch — le nom ne
            // dit rien de la cylindree, la ligne porte celle du N53.
            // 328i N52B30 2996 : version nord-americaine, absente d'Europe,
            // et c'est la raison de sa presence. 330d N57D30OL 2993.
            // Les sources divergent sur la puissance de la 328i, 172 kW
            // partout mais 233 ch cote allemand et 230 hp cote americain :
            // meme moteur, deux normes de mesure, 233 retenu.
            ['bmw', 'serie-3-e90', '318i',        '318i', 1, $petrol, $rwd, 143, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-e90', '320i',        '320i', 2, $petrol, $rwd, 170, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-e90', '320d',        '320d', 3, $diesel, $rwd, 177, 1995, 4, 'automatic'],
            ['bmw', 'serie-3-e90', '325i',        '325i', 4, $petrol, $rwd, 218, 2996, 6, 'automatic'],
            ['bmw', 'serie-3-e90', '328i',        '328i', 5, $petrol, $rwd, 233, 2996, 6, 'automatic'],
            ['bmw', 'serie-3-e90', '330d',        '330d', 6, $diesel, $rwd, 245, 2993, 6, 'automatic'],

            // ── BMW SÉRIE 5 G30 ─────────────────────────────────────────
            // 520i B48B20 1998 a 184 ch. Un autre 520i a existe, B48B16 de
            // 1597 et 170 ch, mais pour la Turquie et la Tunisie : hors des
            // circuits d'importation d'ici, donc non pose. 530d B57D30 2993 a
            // 265 ch jusqu'en 2020, 286 ensuite ; 265 retenu, les G30 de
            // 2017-2020 etant celles qui arrivent. 540i xDrive B58B30 2998 :
            // seule integrale de la serie, les autres sont a propulsion.
            ['bmw', 'serie-5-g30', '520i',        '520i', 1, $petrol, $rwd, 184, 1998, 4, 'automatic'],
            ['bmw', 'serie-5-g30', '520d',        '520d', 2, $diesel, $rwd, 190, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-g30', '530i',        '530i', 3, $petrol, $rwd, 252, 1998, 4, 'automatic'],
            ['bmw', 'serie-5-g30', '530d',        '530d', 4, $diesel, $rwd, 265, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-g30', '540i xDrive', '540X', 5, $petrol, $awd, 340, 2998, 6, 'automatic'],

            // ── BMW SÉRIE 5 F10 ─────────────────────────────────────────
            // La generation du probleme signale. DEUX noms y sont dedoubles,
            // parce que le nombre de cylindres change sous un nom inchange et
            // que le millesime seul permet de trancher :
            //
            //   528i — six 2996 N52B30 jusqu'en 09/2011, puis QUATRE 1997
            //   N20B20 turbo. Les deux rendent 180 kW, soit 245 ch : la
            //   puissance ne distingue rien. Le six retenu est le N52B30 des
            //   marches nord-americain et du Golfe, d'ou vient le parc ; en
            //   Europe la 528i six cylindres etait un N53B30 de meme
            //   cylindree a 258 ch, injection directe exigeant un carburant
            //   desoufre, raison meme pour laquelle l'Amerique et le Golfe
            //   ont recu le N52.
            //
            //   525d — six 2993 N57D30 a 204 ch jusqu'en 09/2011, puis
            //   QUATRE 1995 N47D20 a 218 ch. Meme rupture, et le diesel
            //   525d est explicitement attendu sur le parc importe d'Europe.
            //   Les 218 ch du quatre cylindres ne sont attestes que par la
            //   source anglaise ; la source allemande confirme le code et la
            //   cylindree sans donner la puissance.
            //
            // 520d N47D20 1995 a 184 ch jusqu'en 2014, B47D20 190 ch ensuite ;
            // 184 retenu, la tranche 2010-2014 etant la plus longue et la
            // plus ancienne, donc la plus importee. 530d N57D30O1 2993 a
            // 258 ch a partir de 09/2011 (245 ch avant). 535i N55B30 2979,
            // seul six cylindres essence turbo de la gamme posee.
            ['bmw', 'serie-5-f10', '520i',        '520i',  1, $petrol, $rwd, 184, 1997, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '520d',        '520d',  2, $diesel, $rwd, 184, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '525d (6 cyl. 2010-2011)', '525d6', 3, $diesel, $rwd, 204, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-f10', '525d (4 cyl. 2011-2016)', '525d4', 4, $diesel, $rwd, 218, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '528i (6 cyl. 2010-2011)', '528i6', 5, $petrol, $rwd, 245, 2996, 6, 'automatic'],
            ['bmw', 'serie-5-f10', '528i (4 cyl. 2011-2016)', '528i4', 6, $petrol, $rwd, 245, 1997, 4, 'automatic'],
            ['bmw', 'serie-5-f10', '530d',        '530d',  7, $diesel, $rwd, 258, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-f10', '535i',        '535i',  8, $petrol, $rwd, 306, 2979, 6, 'automatic'],

            // ── BMW SÉRIE 5 E60 ─────────────────────────────────────────
            // Versions posterieures au LCI de 03/2007. La 528i de cette
            // generation est le troisieme porteur du nom dans ce fichier :
            // N52B30 2996, six cylindres, 234 ch, vendue de 2007 a 2010 sur
            // le SEUL marche nord-americain — elle est absente des sources
            // allemandes pour cette raison, pas par contradiction. Les
            // sources divergent sur sa puissance, 234 ch en norme europeenne
            // contre 230 hp en norme americaine : meme moteur, 234 retenu.
            // 523i N53B25 2497 (N52B25 177 ch avant 2007). 525i N53B30 2996 a
            // partir de 2007 contre N52B25 2497 avant, a 218 ch dans les deux
            // cas : encore un nom muet sur la cylindree. 520d N47D20 1995
            // (M47 163 ch avant 2007). 530d et 535d sont des M57D30, famille
            // anterieure au N57 de la F10.
            ['bmw', 'serie-5-e60', '520d',        '520d', 1, $diesel, $rwd, 177, 1995, 4, 'automatic'],
            ['bmw', 'serie-5-e60', '523i',        '523i', 2, $petrol, $rwd, 190, 2497, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '525i',        '525i', 3, $petrol, $rwd, 218, 2996, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '528i',        '528i', 4, $petrol, $rwd, 234, 2996, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '530d',        '530d', 5, $diesel, $rwd, 235, 2993, 6, 'automatic'],
            ['bmw', 'serie-5-e60', '530i',        '530i', 6, $petrol, $rwd, 272, 2996, 6, 'automatic'],

            // ── BMW X1 F48 ──────────────────────────────────────────────
            // ATTENTION A LA MOTRICITE, qui elle va en base. La F48 est batie
            // sur la plateforme transversale UKL2 : « sDrive » y designe la
            // TRACTION AVANT, alors que sur tout le reste de la gamme BMW —
            // et sur l'X1 E84 qui la precede — « sDrive » designe la
            // propulsion. Deux voitures nommees « sDrive18i » n'ont donc pas
            // la meme motricite selon la generation.
            // sDrive18i B38A15 1499 trois cylindres, 136 ch au lancement ; la
            // source anglaise donne 141 hp, soit 105 kW contre 100 au
            // catalogue allemand, 136 retenu. xDrive25i B48B20 1998, vendue
            // xDrive28i aux Etats-Unis : meme voiture, deux appellations.
            ['bmw', 'x1-f48', 'sDrive18i',        '18i',  1, $petrol, $fwd, 136, 1499, 3, 'automatic'],
            ['bmw', 'x1-f48', 'sDrive18d',        '18d',  2, $diesel, $fwd, 150, 1995, 4, 'automatic'],
            ['bmw', 'x1-f48', 'xDrive20d',        '20dX', 3, $diesel, $awd, 190, 1995, 4, 'automatic'],
            ['bmw', 'x1-f48', 'xDrive25i',        '25iX', 4, $petrol, $awd, 231, 1998, 4, 'automatic'],

            // ── BMW X1 E84 ──────────────────────────────────────────────
            // Generation a PROPULSION : ici « sDrive » veut bien dire
            // propulsion, contrairement a la F48 ci-dessus. Les quatre noms
            // sont confirmes par la fiche de presse BMW du modele, qui liste
            // aussi le xDrive18d absent de la source anglaise.
            // sDrive18i N46B20 1995 atmospherique, 150 ch — la source
            // anglaise donne 148 hp pour les memes 110 kW.
            // xDrive28i N20B20 1997 turbo a partir de 2011 ; avant, le haut
            // de gamme etait un xDrive25i N52B30 2996 six cylindres a 215 ch,
            // non pose faute d'un volume d'importation credible.
            ['bmw', 'x1-e84', 'sDrive18d',        '18d',  1, $diesel, $rwd, 143, 1995, 4, 'automatic'],
            ['bmw', 'x1-e84', 'sDrive18i',        '18i',  2, $petrol, $rwd, 150, 1995, 4, 'automatic'],
            ['bmw', 'x1-e84', 'xDrive20d',        '20dX', 3, $diesel, $awd, 184, 1995, 4, 'automatic'],
            ['bmw', 'x1-e84', 'xDrive28i',        '28iX', 4, $petrol, $awd, 245, 1997, 4, 'automatic'],

            // ── BMW X3 G01 ──────────────────────────────────────────────
            // La xDrive30i est un QUATRE cylindres B48B20 de 1998, pas un six
            // : la source allemande la donne a six cylindres et 2998, ce qui
            // contredit la source anglaise, la fiche du moteur B48 et la
            // ligne 330i xDrive de la G20 ci-dessus, qui porte ce meme B48 a
            // 1998. La version allemande est ecartee comme erreur de colonne.
            // xDrive30d B57D30 2993, seul six cylindres pose ici.
            ['bmw', 'x3-g01', 'xDrive20i',        '20iX', 1, $petrol, $awd, 184, 1998, 4, 'automatic'],
            ['bmw', 'x3-g01', 'xDrive20d',        '20dX', 2, $diesel, $awd, 190, 1995, 4, 'automatic'],
            ['bmw', 'x3-g01', 'xDrive30i',        '30iX', 3, $petrol, $awd, 252, 1998, 4, 'automatic'],
            ['bmw', 'x3-g01', 'xDrive30d',        '30dX', 4, $diesel, $awd, 265, 2993, 6, 'automatic'],

            // ── BMW X3 F25 ──────────────────────────────────────────────
            // xDrive20i N20B20 1997. xDrive20d N47D20 1995 a 184 ch jusqu'en
            // 04/2014, B47D20 190 ch ensuite ; 184 retenu, tranche la plus
            // longue. xDrive30d N57D30 2993. xDrive35i N55B30 2979.
            // La xDrive28i est volontairement ABSENTE : la source anglaise la
            // donne en N20 quatre cylindres 1997 a partir de 2012, la source
            // allemande en N52B30 six cylindres 2996 sur la meme periode, a
            // puissance identique de 180 kW. C'est la rupture six-quatre de
            // la 528i, mais sans qu'aucune des deux sources ne permette de
            // dater la bascule. Poser la ligne obligerait a inventer la date.
            ['bmw', 'x3-f25', 'xDrive20d',        '20dX', 1, $diesel, $awd, 184, 1995, 4, 'automatic'],
            ['bmw', 'x3-f25', 'xDrive20i',        '20iX', 2, $petrol, $awd, 184, 1997, 4, 'automatic'],
            ['bmw', 'x3-f25', 'xDrive30d',        '30dX', 3, $diesel, $awd, 258, 2993, 6, 'automatic'],
            ['bmw', 'x3-f25', 'xDrive35i',        '35iX', 4, $petrol, $awd, 306, 2979, 6, 'automatic'],

            // ── BMW X5 G05 ──────────────────────────────────────────────
            // Trois lignes seulement : la G05 est encore recente pour un parc
            // d'occasion, et son haut de gamme est hors de portee ici.
            // xDrive25d B47B20 1995 quatre cylindres. xDrive30d B57D30 2993 a
            // 265 ch jusqu'en 2020, 286 ensuite ; 265 retenu. xDrive40i
            // B58B30 2998 a 333 ch en norme europeenne, 335 hp en norme
            // americaine sur la sDrive40i a propulsion vendue la seule
            // Amerique du Nord : meme moteur, 333 retenu.
            ['bmw', 'x5-g05', 'xDrive25d',        '25dX', 1, $diesel, $awd, 231, 1995, 4, 'automatic'],
            ['bmw', 'x5-g05', 'xDrive30d',        '30dX', 2, $diesel, $awd, 265, 2993, 6, 'automatic'],
            ['bmw', 'x5-g05', 'xDrive40i',        '40iX', 3, $petrol, $awd, 333, 2998, 6, 'automatic'],

            // ── BMW X5 F15 ──────────────────────────────────────────────
            // xDrive25d B47D20 1995 a 231 ch a partir de 08/2015 (N47D20
            // 218 ch avant) ; la valeur tardive est retenue, les F15 de
            // 2016-2018 etant celles qui s'importent. xDrive30d N57D30 2993.
            // xDrive35i N55B30 2979. xDrive50i N63B44 4395, V8 : la seule
            // huit cylindres de la F15 posee, pour le parc venu du Golfe.
            ['bmw', 'x5-f15', 'xDrive25d',        '25dX', 1, $diesel, $awd, 231, 1995, 4, 'automatic'],
            ['bmw', 'x5-f15', 'xDrive30d',        '30dX', 2, $diesel, $awd, 258, 2993, 6, 'automatic'],
            ['bmw', 'x5-f15', 'xDrive35i',        '35iX', 3, $petrol, $awd, 306, 2979, 6, 'automatic'],
            ['bmw', 'x5-f15', 'xDrive50i',        '50iX', 4, $petrol, $awd, 450, 4395, 8, 'automatic'],

            // ── BMW X5 E70 ──────────────────────────────────────────────
            // Nomenclature d'APRES mars 2008 : jusque-la les memes voitures
            // se nommaient 3.0d, 3.0si, 3.0sd et 4.8i. Un vendeur tenant une
            // E70 de 2007 cherchera ces noms-la et ne les trouvera pas ; le
            // choix est delibere, les millesimes qui s'importent aujourd'hui
            // etant ceux de 2009 et au-dela.
            // xDrive30d N57D30OL 2993 a 245 ch a partir de 06/2010 (M57D30TU2
            // 235 ch avant). xDrive30i N52B30 2996 atmospherique.
            // xDrive35d M57D30TU2 biturbo a 286 ch en Europe contre 265 ch en
            // Amerique du Nord ; 286 retenu, le diesel de cette generation
            // venant d'Europe, ou il s'est vendu sans commune mesure.
            // xDrive35i N55B30 2979. xDrive48i N62B48TU 4799, V8.
            ['bmw', 'x5-e70', 'xDrive30d',        '30dX', 1, $diesel, $awd, 245, 2993, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive30i',        '30iX', 2, $petrol, $awd, 272, 2996, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive35d',        '35dX', 3, $diesel, $awd, 286, 2993, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive35i',        '35iX', 4, $petrol, $awd, 306, 2979, 6, 'automatic'],
            ['bmw', 'x5-e70', 'xDrive48i',        '48iX', 5, $petrol, $awd, 355, 4799, 8, 'automatic'],
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
