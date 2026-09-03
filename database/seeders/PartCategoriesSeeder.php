<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Structure: [nom, slug, parent_slug|null, enfants[]]
        $tree = [
            ['Moteur', 'moteur', null, [
                ['Filtres', 'filtres', 'moteur', [
                    ['Filtre à huile',      'filtre-huile',      'filtres',     []],
                    ['Filtre à air',        'filtre-air',        'filtres',     []],
                    ['Filtre habitacle',    'filtre-habitacle',  'filtres',     []],
                    ['Filtre à carburant',  'filtre-carburant',  'filtres',     []],
                ]],
                ['Distribution', 'distribution', 'moteur', [
                    ['Courroie de distribution', 'courroie-distribution', 'distribution', []],
                    ['Kit distribution',         'kit-distribution',      'distribution', []],
                    ['Chaîne de distribution',   'chaine-distribution',   'distribution', []],
                ]],
                ['Lubrification', 'lubrification', 'moteur', [
                    ['Huile moteur',        'huile-moteur',        'lubrification', []],
                    ['Joint de culasse',    'joint-culasse',       'lubrification', []],
                    ['Carter d\'huile',     'carter-huile',        'lubrification', []],
                ]],
                ['Refroidissement', 'refroidissement', 'moteur', [
                    ['Radiateur',           'radiateur',           'refroidissement', []],
                    ['Thermostat',          'thermostat',          'refroidissement', []],
                    ['Pompe à eau',         'pompe-eau',           'refroidissement', []],
                    ['Liquide de refroidissement', 'liquide-refroidissement', 'refroidissement', []],
                ]],
                ['Injection & Allumage', 'injection-allumage', 'moteur', [
                    ['Bougies d\'allumage', 'bougies-allumage',   'injection-allumage', []],
                    ['Injecteurs',          'injecteurs',          'injection-allumage', []],
                    ['Bobines d\'allumage', 'bobines-allumage',    'injection-allumage', []],
                ]],
            ]],

            ['Freinage', 'freinage', null, [
                ['Disques & Plaquettes', 'disques-plaquettes', 'freinage', [
                    ['Disques avant',       'disques-avant',     'disques-plaquettes', []],
                    ['Disques arrière',     'disques-arriere',   'disques-plaquettes', []],
                    ['Plaquettes avant',    'plaquettes-avant',  'disques-plaquettes', []],
                    ['Plaquettes arrière',  'plaquettes-arriere','disques-plaquettes', []],
                ]],
                ['Étriers',             'etriers',           'freinage', []],
                ['Maître-cylindre',     'maitre-cylindre',   'freinage', []],
                ['Flexibles de frein',  'flexibles-frein',   'freinage', []],
                ['Liquide de frein',    'liquide-frein',     'freinage', []],
            ]],

            ['Suspension & Direction', 'suspension-direction', null, [
                ['Amortisseurs', 'amortisseurs', 'suspension-direction', [
                    ['Amortisseurs avant',  'amortisseurs-avant',  'amortisseurs', []],
                    ['Amortisseurs arrière','amortisseurs-arriere', 'amortisseurs', []],
                    ['Ressorts',            'ressorts',            'amortisseurs', []],
                ]],
                ['Rotules & Biellettes', 'rotules-biellettes', 'suspension-direction', [
                    ['Rotule de direction', 'rotule-direction',    'rotules-biellettes', []],
                    ['Biellette de barre stabilisatrice', 'biellette-barre-stab', 'rotules-biellettes', []],
                ]],
                ['Roulements & Moyeux', 'roulements-moyeux', 'suspension-direction', []],
                ['Soufflets & Joints homocinétiques', 'soufflets-homocinets', 'suspension-direction', []],
                ['Direction', 'direction', 'suspension-direction', [
                    ['Crémaillère de direction', 'cremaillere', 'direction', []],
                    ['Colonne de direction',     'colonne-direction', 'direction', []],
                ]],
            ]],

            ['Transmission', 'transmission', null, [
                ['Boîte de vitesses', 'boite-vitesses', 'transmission', []],
                ['Embrayage', 'embrayage', 'transmission', [
                    ['Kit embrayage',   'kit-embrayage',   'embrayage', []],
                    ['Volant moteur',   'volant-moteur',   'embrayage', []],
                ]],
                ['Cardan & Demi-arbre', 'cardan-demi-arbre', 'transmission', []],
                ['Différentiel',        'differentiel',       'transmission', []],
            ]],

            ['Électricité', 'electricite', null, [
                ['Batterie & Alternateur', 'batterie-alternateur', 'electricite', [
                    ['Batterie 12V',    'batterie-12v',    'batterie-alternateur', []],
                    ['Alternateur',     'alternateur',     'batterie-alternateur', []],
                    ['Démarreur',       'demarreur',       'batterie-alternateur', []],
                ]],
                ['Éclairage', 'eclairage', 'electricite', [
                    ['Phares',          'phares',          'eclairage', []],
                    ['Feux arrière',    'feux-arriere',    'eclairage', []],
                    ['Ampoules',        'ampoules',        'eclairage', []],
                ]],
                ['Capteurs & Sondes', 'capteurs-sondes', 'electricite', [
                    ['Sonde lambda',    'sonde-lambda',    'capteurs-sondes', []],
                    ['Capteur ABS',     'capteur-abs',     'capteurs-sondes', []],
                    ['Capteur de pression', 'capteur-pression', 'capteurs-sondes', []],
                ]],
            ]],

            ['Carrosserie', 'carrosserie', null, [
                ['Vitrage', 'vitrage', 'carrosserie', [
                    ['Pare-brise',      'pare-brise',      'vitrage', []],
                    ['Vitre latérale',  'vitre-laterale',  'vitrage', []],
                    ['Lunette arrière', 'lunette-arriere', 'vitrage', []],
                ]],
                ['Rétroviseurs',        'retroviseurs',    'carrosserie', []],
                ['Pare-chocs',          'pare-chocs',      'carrosserie', []],
                ['Capot & Portes',      'capot-portes',    'carrosserie', []],
                ['Joints d\'étanchéité','joints-etancheite','carrosserie', []],
            ]],

            ['Climatisation', 'climatisation', null, [
                ['Compresseur de clim', 'compresseur-clim', 'climatisation', []],
                ['Condenseur',          'condenseur',        'climatisation', []],
                ['Détendeur',           'detendeur',         'climatisation', []],
                ['Gaz réfrigérant',     'gaz-refrigerant',   'climatisation', []],
            ]],

            ['Pneus & Roues', 'pneus-roues', null, [
                ['Pneus',               'pneus',             'pneus-roues', []],
                ['Jantes',              'jantes',            'pneus-roues', []],
                ['Valves & Accessoires','valves-accessoires','pneus-roues', []],
            ]],

            ['Échappement', 'echappement', null, [
                ['Silencieux',          'silencieux',        'echappement', []],
                ['Collecteur',          'collecteur',        'echappement', []],
                ['Catalyseur',          'catalyseur',        'echappement', []],
                ['FAP / DPF',           'fap-dpf',           'echappement', []],
            ]],
        ];

        // Insérer en 3 passes pour respecter la FK parent_id
        $slugToId = [];
        $this->insertLevel($tree, null, $slugToId);
    }

    private function insertLevel(array $nodes, ?int $parentId, array &$slugToId): void
    {
        foreach ($nodes as [$name, $slug, $parentSlug, $children]) {
            // Idempotent : on récupère l'id existant plutôt que d'insérer en doublon.
            $existing = DB::table('part_categories')->where('slug', $slug)->first();

            if ($existing) {
                $id = $existing->id;
            } else {
                $id = DB::table('part_categories')->insertGetId([
                    'parent_id'  => $parentId,
                    'name'       => $name,
                    'slug'       => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $slugToId[$slug] = $id;

            if (!empty($children)) {
                $this->insertLevel($children, $id, $slugToId);
            }
        }
    }
}
