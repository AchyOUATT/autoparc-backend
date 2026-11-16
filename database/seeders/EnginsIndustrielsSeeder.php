<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Part;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Les engins qui portent les onze filtres industriels, et EUX SEULS.
 *
 * ── CE QUE CE LOT EST, ET SURTOUT CE QU'IL N'EST PAS ───────────────────────
 *
 * Il n'est PAS le referentiel complet des engins du Burkina Faso. Celui-la demande une
 * enquete de marche — Foton Lovol, Massey Ferguson pakistanaises, Sinotruk,
 * Shacman, Renault Trucks — et il est decrit dans
 * `docs/proposition-referentiel-engins.md`, en attente d'arbitrage.
 *
 * Ce lot ne pose QUE les machines pour lesquelles une source NOMME a la fois
 * la machine et l'une des onze references en stock. Rien d'autre. Les guides
 * officiels John Deere et les fiches Caterpillar consultes le 16/11/2026 ont
 * permis d'ajouter des lignes de premier rang, toujours machine par machine.
 *
 * ── DEUX RANGS DE SOURCE, ET LA DIFFERENCE EST ECRITE SUR CHAQUE LIGNE ─────
 *
 * PREMIER RANG — le constructeur de la machine nomme la piece. C'est la seule
 * preuve qui autorise a vendre sans reserve.
 *
 * SECOND RANG — un equipementier (MANN) ou un catalogue de pieces specialise
 * fait le rapprochement. C'est serieux, c'est exploitable, et ce n'est pas la
 * meme chose : l'equipementier decrit SON produit, pas le montage reel d'une
 * machine donnee. La compatibilite est posee, et sa reserve voyage avec elle
 * dans `notes`.
 *
 * Le rang n'est pas une nuance de style. Le catalogue Toyota a refuse quinze
 * lignes faute de source, et une erreur de ce dossier — un filtre C7.1 pretee
 * a une niveleuse 140M2 qui porte un C9.3 — venait precisement d'une
 * deduction par famille de moteur. Une famille moteur commune NE VAUT PAS
 * compatibilite.
 *
 * ── POURQUOI UN SEEDER DEDIE ───────────────────────────────────────────────
 *
 * `BrandsSeeder` et `VehicleModelsSeeder` sont les deux fichiers en conflit
 * avec la branche corrective externe. Y ajouter quatre marques multiplierait
 * la surface de fusion pour rien : ces engins n'ont aucun rapport avec le
 * referentiel automobile, et un seeder a part se rejoue, se lit et se retire
 * sans toucher au reste.
 *
 * ── CE QUI EST VOLONTAIREMENT LAISSE VIDE ──────────────────────────────────
 *
 * `default_vehicle_type` reste NULL. L'enum ne connait que
 * `passenger | utility | heavy` : aucune case pour un groupe electrogene, une
 * tractopelle ou un tracteur. Detourner `generation`, `segment` ou
 * `body_type` pour y loger cette information serait pire que le vide — on
 * retrouve six mois plus tard un champ requisitionne dont plus personne ne
 * sait ce qu'il veut dire. `equipment_category` porte le type d'engin
 * (tracteur, tractopelle, groupe, ou `other` pour une niveleuse).
 *
 * Les annees de production ne sont posees que lorsqu'une source les donne.
 * Un NULL honnete vaut mieux qu'une date inventee : c'est une date fausse qui
 * a fait rattacher une Corolla de 2016 a une generation apparue en 2019.
 */
class EnginsIndustrielsSeeder extends Seeder
{
    /** Les marques de MACHINES. Perkins, Fleetguard et Cummins n'y sont pas : ce sont des motoristes. */
    private const MARQUES = [
        ['nom' => 'Caterpillar', 'slug' => 'caterpillar'],
        ['nom' => 'John Deere',  'slug' => 'john-deere'],
        ['nom' => 'McCormick',   'slug' => 'mccormick'],
        ['nom' => 'JCB',         'slug' => 'jcb'],
    ];

    /**
     * Les machines, avec le rang de leur source et la piece qu'elles portent.
     *
     * `piece` est le SKU d'une des onze references en stock. `moteur` alimente
     * `part_fitments.engine_code` : une compatibilite qui ne le porte pas est
     * plus faible, et la regle de ce depot est de ne jamais confirmer un filtre
     * sur le seul nom du modele.
     */
    private const MACHINES = [
        // ── PREMIER RANG — Caterpillar nomme la machine ET la piece ────────
        [
            'marque' => 'Caterpillar',
            'nom' => 'DE150 GC', 'slug' => 'cat-de150-gc',
            'generation' => null, 'type' => 'Groupe électrogène',
            'debut' => 2021, 'fin' => null,
            'piece' => '462-1171', 'moteur' => 'C7.1',
            'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — Caterpillar. Le kit d\'entretien 610-3587 '
                .'(parts.cat.com) nomme le groupe « DE150GC C7.1 » dans ses modèles compatibles '
                .'ET contient la référence 462-1171. Machine nommée et pièce nommée par le '
                .'constructeur. '
                .'RÉSERVE : ce kit ne contient PAS 435-6493, contrairement au kit 610-3589, qui '
                .'est aussi un kit C7.1. Les contenus diffèrent selon la configuration, et '
                .'Caterpillar renvoie au concessionnaire. La fiche officielle de 435-6493 cite '
                .'aussi le DE150GC, mais ce kit ne le contient pas : 435-6493 n\'est donc pas '
                .'relié à cette machine.',
        ],
        [
            'marque' => 'Caterpillar',
            'nom' => '140 GC', 'slug' => 'cat-140-gc',
            'generation' => null, 'type' => 'Niveleuse',
            'debut' => null, 'fin' => null,
            'piece' => '435-6493', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — Caterpillar. La fiche officielle de 435-6493 '
                .'(parts.cat.com) le liste sous « Motor Grader — 140 GC ». Ce n\'est pas la '
                .'140M2, qui a déjà été écartée parce qu\'elle porte un C9.3. Vérifier le numéro '
                .'de série : Caterpillar prévient que la liste est indicative. '
                .'https://parts.cat.com/en/catcorp/product/435-6493',
        ],
        [
            'marque' => 'Caterpillar',
            'nom' => 'DE200 GC', 'slug' => 'cat-de200-gc',
            'generation' => null, 'type' => 'Groupe électrogène',
            'debut' => null, 'fin' => null,
            'piece' => '435-6493', 'moteur' => 'C7.1', 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — Caterpillar. La fiche officielle de 435-6493 '
                .'(parts.cat.com) le liste parmi les groupes « DE200GC » / « DE200E0 » à moteur '
                .'C7.1. Vérifier le numéro de série. Pas le DE150GC, dont le kit 610-3587 ne '
                .'contient pas cette référence.',
        ],
        [
            'marque' => 'Caterpillar',
            'nom' => 'DE220 GC', 'slug' => 'cat-de220-gc',
            'generation' => null, 'type' => 'Groupe électrogène',
            'debut' => null, 'fin' => null,
            'piece' => '435-6493', 'moteur' => 'C7.1', 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — Caterpillar. La fiche officielle de 435-6493 '
                .'(parts.cat.com) le liste parmi les groupes « DE220GC » à moteur C7.1. '
                .'Vérifier le numéro de série.',
        ],
        [
            'marque' => 'Caterpillar',
            'nom' => 'XQP150', 'slug' => 'cat-xqp150',
            'generation' => null, 'type' => 'Groupe électrogène',
            'debut' => null, 'fin' => null,
            'piece' => '435-6493', 'moteur' => 'C7.1', 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — Caterpillar. La fiche officielle de 435-6493 '
                .'(parts.cat.com) le liste parmi les groupes « XQP150 » à moteur C7.1. '
                .'Vérifier le numéro de série.',
        ],

        // ── PREMIER RANG — John Deere, guides officiels de filtres ─────────
        [
            'marque' => 'John Deere', 'nom' => '5090M', 'slug' => 'jd-5090m',
            'generation' => '5M', 'type' => 'Tracteur agricole',
            'debut' => null, 'fin' => null,
            'piece' => 'RE504836', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — John Deere. Le guide officiel de filtres '
                .'« 5M series - 5075M, 5090M, 5100M and 5115M Tractors » (mars 2021, deere.com) '
                .'nomme RE504836 comme filtre à huile moteur de ces tracteurs. Code moteur non '
                .'établi sur ce guide ; vérifier la configuration avant de vendre sans réserve. '
                .'https://www.deere.com/assets/pdfs/common/qrg/5m-series-5075m-5090m-5100m-5115m-tractors.pdf',
        ],
        [
            'marque' => 'John Deere', 'nom' => '5100M', 'slug' => 'jd-5100m',
            'generation' => '5M', 'type' => 'Tracteur agricole',
            'debut' => 2012, 'fin' => 2022,
            'piece' => 'RE504836', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — même guide officiel 5M que le 5090M (mars 2021, '
                .'deere.com), qui nomme RE504836 pour le 5100M. Les millésimes 2012-2022 déjà '
                .'portés par cette fiche restent indicatifs. Code moteur non établi.',
        ],
        [
            'marque' => 'John Deere', 'nom' => '5115M', 'slug' => 'jd-5115m',
            'generation' => '5M', 'type' => 'Tracteur agricole',
            'debut' => 2012, 'fin' => 2022,
            'piece' => 'RE504836', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — même guide officiel 5M que le 5090M (mars 2021, '
                .'deere.com), qui nomme RE504836 pour le 5115M. Usines d\'Augusta et de Pune '
                .'parfois citées pour le même nom : à vérifier avant de confirmer un montage. '
                .'Code moteur non établi.',
        ],
        [
            'marque' => 'John Deere', 'nom' => '5075M', 'slug' => 'jd-5075m',
            'generation' => '5M', 'type' => 'Tracteur agricole',
            'debut' => null, 'fin' => null,
            'piece' => 'RE504836', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — John Deere. Le même guide officiel 5M (mars 2021, '
                .'deere.com) nomme RE504836 comme filtre à huile moteur du 5075M, aux côtés des '
                .'5090M, 5100M et 5115M. Code moteur non établi.',
        ],
        [
            'marque' => 'John Deere', 'nom' => '5410', 'slug' => 'jd-5410',
            'generation' => '5010', 'type' => 'Tracteur agricole',
            'debut' => null, 'fin' => null,
            'piece' => 'RE59754', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — John Deere. Le guide officiel « FILTER OVERVIEW '
                .'5010 Series - 5210, 5310, 5410 and 5510 Tractors » (juin 2021, deere.com) nomme '
                .'RE59754 comme filtre à huile moteur des 5410 et 5510. Les 5210 et 5310 prennent '
                .'T19044 : ne pas les confondre. '
                .'https://www.deere.com/assets/pdfs/common/parts-and-service/quick-reference-guides/5010-series-5210-5310-5410-5510.pdf',
        ],
        [
            'marque' => 'John Deere', 'nom' => '5510', 'slug' => 'jd-5510',
            'generation' => '5010', 'type' => 'Tracteur agricole',
            'debut' => null, 'fin' => null,
            'piece' => 'RE59754', 'moteur' => null, 'rang' => 'premier',
            'note' => 'SOURCE DE PREMIER RANG — même guide officiel 5010 Series (juin 2021, '
                .'deere.com) que le 5410, qui nomme RE59754 pour le 5510. Pas le 5210 ni le 5310.',
        ],

        // ── SECOND RANG — McCormick, via l'équipementier MANN ──────────────
        [
            'marque' => 'McCormick', 'nom' => 'MTX 110', 'slug' => 'mccormick-mtx-110',
            'generation' => 'MTX 1re génération', 'type' => 'Tracteur agricole',
            'debut' => 2000, 'fin' => 2003,
            'piece' => '26560145', 'moteur' => '1006-60T', 'rang' => 'second',
            'note' => 'SOURCE DE SECOND RANG — MANN, équipementier, qui donne son WK 8141 pour les '
                .'McCormick MTX 110, 125 et 140 à moteur Perkins 1006-60T, et pose l\'équivalence '
                .'avec la référence Perkins 26560145. '
                .'PIÈGE À NE PAS DÉFAIRE : la MTX Série II (MTX 120/135/150, 2004-2007) est à '
                .'moteur Iveco/FPT 6,8 L. Lui poser un filtre Perkins serait exactement l\'erreur '
                .'qui a fait refuser quinze lignes du catalogue Toyota. Le code moteur porté par '
                .'cette ligne est donc ce qui la rend sûre : il sépare les deux générations.',
        ],
        [
            'marque' => 'McCormick', 'nom' => 'MTX 125', 'slug' => 'mccormick-mtx-125',
            'generation' => 'MTX 1re génération', 'type' => 'Tracteur agricole',
            'debut' => 2000, 'fin' => 2003,
            'piece' => '26560145', 'moteur' => '1006-60T', 'rang' => 'second',
            'note' => 'SOURCE DE SECOND RANG — MANN, même entrée que la MTX 110. Usine de '
                .'Doncaster. Ne concerne PAS la Série II à moteur Iveco.',
        ],
        [
            'marque' => 'McCormick', 'nom' => 'MTX 140', 'slug' => 'mccormick-mtx-140',
            'generation' => 'MTX 1re génération', 'type' => 'Tracteur agricole',
            'debut' => 2000, 'fin' => 2003,
            'piece' => '26560145', 'moteur' => '1006-60T', 'rang' => 'second',
            'note' => 'SOURCE DE SECOND RANG — MANN, même entrée que la MTX 110. Usine de '
                .'Doncaster. Ne concerne PAS la Série II à moteur Iveco.',
        ],

        // ── SECOND RANG — JCB, catalogues de pièces spécialisés ────────────
        [
            'marque' => 'JCB', 'nom' => '3CX', 'slug' => 'jcb-3cx',
            'generation' => null, 'type' => 'Tractopelle',
            'debut' => 1980, 'fin' => null,
            'piece' => '320/07155', 'moteur' => null, 'rang' => 'second',
            'note' => 'SOURCE DE SECOND RANG — catalogues de pièces spécialisés, pas JCB. '
                .'La tractopelle la plus répandue au monde, produite depuis 1980. '
                .'RÉSERVE FORTE : le montage dépend de la version ET du numéro de série, et le '
                .'code moteur n\'est pas établi. La référence porte en outre une chaîne de '
                .'remplacement (320/07155 → 320/07394 → 320/A7170), enregistrée sur la pièce. '
                .'Vérifier le numéro de série avant de vendre.',
        ],
        [
            'marque' => 'JCB', 'nom' => '3DX', 'slug' => 'jcb-3dx',
            'generation' => 'ligne indienne (Ballabgarh)', 'type' => 'Tractopelle',
            'debut' => null, 'fin' => 2024,
            'piece' => '320/07155', 'moteur' => null, 'rang' => 'second',
            'note' => 'SOURCE DE SECOND RANG — catalogues spécialisés. La 3DX est la ligne '
                .'INDIENNE de JCB (usine de Ballabgarh), celle qui alimente l\'Afrique de l\'Ouest, '
                .'remplacée en 2024 par des 3CX importées d\'Inde. Ce n\'est pas une variante de '
                .'marché de la 3CX britannique : deux lignes distinctes, à ne pas fusionner. '
                .'Même réserve de numéro de série, même code moteur non établi.',
        ],
    ];

    public function run(): void
    {
        // ── SANS LES FILTRES, PAS DE MACHINES ────────────────────────────
        //
        // Ces engins n'existent que pour porter les onze references en
        // stock. Si aucune n'est en base, ils n'ont rien a servir, et le lot
        // s'abstient entierement.
        //
        // Ce n'est pas qu'une coherence de sens, c'est aussi ce qui rend la
        // migration INERTE EN TEST. `migrate` tourne dans chaque test : la
        // premiere version creait ses neuf modeles inconditionnellement, et
        // ils entraient aussitot dans `modelesSansCote()` de l'import
        // consommation. Mesure : 4 echecs sur la suite, dont « Output does
        // not contain "1 modeles a chercher en Europe." ».
        //
        // C'est la meme faute que sur `2026_10_24_000002`, sous une autre
        // forme : une migration qui ECRIT DES DONNEES ecrit dans la base de
        // chaque test. Le garde-fou doit donc venir des donnees elles-memes,
        // pas d'une condition d'environnement.
        $filtres = array_values(array_unique(array_column(self::MACHINES, 'piece')));

        if (! Part::withTrashed()->whereIn('sku', $filtres)->exists()) {
            $this->command?->info(
                '  Engins industriels : aucun filtre industriel en base, rien a rattacher.'
            );

            return;
        }

        $marques = [];

        foreach (self::MARQUES as $m) {
            $marques[$m['nom']] = Brand::firstOrCreate(
                ['slug' => $m['slug']],
                ['name' => $m['nom'], 'is_active' => true],
            );
        }

        $modeles = 0;
        $compats = 0;
        $sansPiece = [];

        foreach (self::MACHINES as $machine) {
            $marque = $marques[$machine['marque']];

            $modele = VehicleModel::firstOrNew([
                'brand_id' => $marque->id,
                'slug'     => $machine['slug'],
            ]);

            // Le descriptif se corrige a chaque passage ; `is_active` n'est
            // pose qu'a la creation, pour qu'une desactivation faite a la main
            // survive a un redeploiement.
            if (! $modele->exists) {
                $modele->is_active = true;
                $modeles++;
            }

            $modele->name             = $machine['nom'];
            $modele->generation       = $machine['generation'];
            $modele->body_type        = $machine['type'];
            $modele->equipment_category = match ($machine['type']) {
                'Tracteur agricole' => 'agricultural_tractor',
                'Tractopelle' => 'backhoe_loader',
                'Groupe électrogène' => 'generator',
                default => 'other',
            };
            $modele->production_start = $machine['debut'];
            $modele->production_end   = $machine['fin'];
            $modele->save();

            $piece = Part::withTrashed()->where('sku', $machine['piece'])->first();

            // Une piece absente ou a la corbeille ne recoit pas de
            // compatibilite : la machine entre quand meme, elle vaut par
            // elle-meme pour le selecteur.
            if ($piece === null || $piece->trashed()) {
                $sansPiece[] = $machine['piece'];

                continue;
            }

            // Remplacement plutot qu'empilement : le seeder se rejoue.
            DB::table('part_fitments')
                ->where('part_id', $piece->id)
                ->where('vehicle_model_id', $modele->id)
                ->delete();

            $piece->fitments()->create([
                'vehicle_model_id' => $modele->id,
                'engine_code'      => $machine['moteur'],
                'year_from'        => $machine['debut'],
                'year_to'          => $machine['fin'],
                'notes'            => $machine['note'],
            ]);
            $compats++;
        }

        $this->command?->info(sprintf(
            '  Engins industriels : %d machine(s) creee(s) sur %d, %d compatibilite(s) posee(s).',
            $modeles,
            count(self::MACHINES),
            $compats,
        ));

        if ($sansPiece !== []) {
            $this->command?->warn(
                '  Sans compatibilite, piece absente du catalogue : '
                .implode(', ', array_unique($sansPiece))
            );
        }
    }
}
