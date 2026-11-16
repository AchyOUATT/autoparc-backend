<?php

namespace Database\Seeders;

use App\Enums\PartType;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Database\Seeder;

/**
 * Les onze filtres poids lourds et engins de la boutique.
 *
 * Premieres references HORS automobile du catalogue : Perkins, John Deere,
 * Caterpillar, Fleetguard, JCB. Toutes en stock chez le commercant, toutes
 * photographiees, toutes D'ORIGINE — ce n'est pas l'equivalent adaptable que
 * vend `FiltresHuileBoutiqueSeeder`, et c'est pourquoi elles vivent dans un
 * seeder a part plutot que d'allonger celui-la.
 *
 * ── CE LOT N'EST PAS PUBLIE, ET C'EST VOULU ────────────────────────────────
 *
 * Aucun prix n'est connu. Les filtres industriels valent plusieurs fois les
 * 3 000 F du catalogue Toyota, et reprendre ce chiffre « quitte a reajuster »
 * mettrait en ligne onze prix faux, visibles par un client, pendant le temps
 * qu'il faut pour s'en apercevoir.
 *
 * Les articles entrent donc avec `is_active = false` ET `selling_price = 0`.
 * La double garde n'est pas une ceinture-bretelles : le drapeau les retire du
 * catalogue public, et le zero fait qu'un article publie par megarde
 * n'annonce pas un prix credible et faux, mais un prix manifestement absent.
 * De toutes les valeurs erronees possibles, zero est la seule qui ne trompe
 * personne.
 *
 * `stock_quantity` reste a zero pour la meme raison : le commercant a ces
 * filtres, mais ne les a pas comptes. Un nombre invente serait une promesse.
 *
 * POUR LES PUBLIER : poser le prix et la quantite depuis l'application, puis
 * activer. Ce seeder ne touche plus a ces trois colonnes une fois l'article
 * cree — meme discipline que le catalogue Toyota, et pour la meme raison : une
 * republication qui remettrait le prix du fichier effacerait un reajustement
 * fait le soir meme.
 *
 * ── OEM OU OES, ET POURQUOI LA DISTINCTION COMPTE ──────────────────────────
 *
 * Perkins, John Deere, Caterpillar et JCB fabriquent la machine ou son moteur :
 * leurs filtres sont des pieces constructeur, `PartType::Oem`.
 *
 * Fleetguard non. C'est la marque de filtration de Cummins, donc un
 * equipementier d'origine : `PartType::Oes`. La nuance se vend — un acheteur
 * qui demande « du Cummins » accepte un Fleetguard et refuse un adaptable —
 * et l'enum la porte deja.
 *
 * ── AUCUNE COMPATIBILITE DANS CE LOT ───────────────────────────────────────
 *
 * Le document d'ou viennent ces references propose des engins : Dongfeng a
 * moteur Cummins, chargeuses Caterpillar, tractopelles JCB, tracteurs John
 * Deere et McCormick. AUCUNE de ces marques n'existe au referentiel — ni
 * Dongfeng, ni Caterpillar, ni JCB, ni John Deere, ni McCormick.
 *
 * Il n'y a donc rien a quoi rattacher une compatibilite, et c'est aussi bien :
 * la lecon du catalogue Toyota est qu'une compatibilite posee sans source
 * verifiee coute plus cher qu'une compatibilite absente. Le referentiel des
 * engins fait l'objet d'une proposition separee ; les compatibilites viendront
 * apres lui, source par source.
 *
 * ── DEUX RESERVES QUE LE DOCUMENT POSE LUI-MEME ────────────────────────────
 *
 * FF5018. Le marquage photographie porte « Cummins 3931063 ». Or un manuel
 * Cummins C8.3 associe 3931063 a FF5052, pas a FF5018. Le filtre existe et la
 * reference est lisible, mais 3931063 N'EST PAS enregistre comme numero
 * equivalent : ce serait vendre sur une correspondance douteuse. A trancher
 * avant toute compatibilite.
 *
 * JCB 320/07155. Le distributeur documente un remplacement en chaine :
 * 320/07155 -> 320/07394 -> 320/A7170. `oem_numbers` porte deja
 * `is_superseded` et `superseded_by_id` ; la chaine est donc enregistree comme
 * telle, et l'article se retrouve par n'importe laquelle des trois.
 */
class FiltresIndustrielsBoutiqueSeeder extends Seeder
{
    /**
     * Les onze references, telles qu'elles sont marquees sur le filtre.
     *
     * `categorie` reprend les noms deja poses par `PartCategoriesSeeder` : un
     * separateur d'eau est un filtre a carburant qui fait davantage, et creer
     * une categorie pour trois references diviserait le rayon sans l'eclairer.
     */
    private const FILTRES = [
        // ── Perkins — moteurs industriels et agricoles ──────────────────
        [
            'sku'        => '26560145',
            'fabricant'  => 'Perkins',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à gazole Perkins 26560145 (séparateur d\'eau)',
            'description' => 'Filtre à gazole / séparateur d\'eau Perkins. Le commerce officiel '
                .'Perkins le donne pour des moteurs des séries 700, 900, 1000 et 1100 ; le '
                .'montage dépend de la liste de construction, à vérifier moteur par moteur. '
                .'MANN rapproche 26560145 de son WK 8141 sur les McCormick MTX 110 / 125 / 140 '
                .'(2000-2003) à moteur Perkins 1006-60T uniquement — pas la Série II à moteur '
                .'Iveco. Sources : shop.perkins.com (26560145) ; catalogue MANN.',
        ],
        [
            'sku'        => '26560143',
            'fabricant'  => 'Perkins',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à gazole Perkins 26560143 (séparateur d\'eau)',
            'description' => 'Filtre à gazole avec séparation d\'eau, moteurs Perkins. Référence '
                .'distincte de 26560145 : ne pas fusionner sans substitution constructeur établie.',
        ],
        [
            'sku'        => '4415122',
            'fabricant'  => 'Perkins',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant Perkins 4415122',
            'description' => 'Filtre à carburant / séparateur d\'eau Perkins, destiné aux moteurs '
                .'de la série 1100 (1103, 1104 et 1106 selon liste de construction). Aucune '
                .'machine précise n\'est enregistrée : une famille moteur ne suffit pas à poser '
                .'une compatibilité engin par engin. Source : shop.perkins.com (4415122).',
        ],

        // ── John Deere — tracteurs et engins forestiers ─────────────────
        [
            'sku'        => 'RE504836',
            'fabricant'  => 'John Deere',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur John Deere RE504836',
            'description' => 'Filtre à huile moteur John Deere, montage à visser. Le guide '
                .'officiel de filtres 5M (mars 2021) le nomme pour les tracteurs 5075M, 5090M, '
                .'5100M et 5115M. Source : deere.com, « 5M series - 5075M, 5090M, 5100M and '
                .'5115M Tractors ».',
        ],
        [
            'sku'        => 'RE59754',
            'fabricant'  => 'John Deere',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur John Deere RE59754',
            'description' => 'Filtre à huile moteur John Deere, montage à visser, type full-flow '
                .'avec soupape de by-pass (diamètre 95,7 mm, longueur 146,3 mm). Référence '
                .'DISTINCTE de RE504836 : les deux ne se remplacent pas. Le guide officiel 5010 '
                .'Series le nomme pour les tracteurs 5410 et 5510 — pas les 5210 / 5310, qui '
                .'prennent T19044. Le catalogue Shop.Deere indique aussi un « general fit » '
                .'sur des débusqueurs 640G / 648G, des niveleuses 670C et des moteurs 3029, '
                .'4045 et 6068 : ces indications générales ne sont pas enregistrées comme '
                .'compatibilités de machines, faute de confirmation par série. Sources : '
                .'deere.com QRG 5010 Series (juin 2021) ; shop.deere.com/RE59754.',
        ],

        // ── Caterpillar — chargeuses, niveleuses, groupes ───────────────
        [
            'sku'        => '462-1171',
            'fabricant'  => 'Caterpillar',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur Caterpillar 462-1171',
            'description' => 'Filtre à huile moteur Caterpillar d\'efficacité STANDARD (et non '
                .'« Advanced High Efficiency ») : c\'est le libellé du catalogue officiel '
                .'parts.cat.com. Il figure dans PLUSIEURS kits d\'entretien C7.1, dont le contenu '
                .'DIFFÈRE selon la configuration : le kit 610-3587, qui nomme le groupe DE150GC '
                .'C7.1, contient 462-1171 SANS 435-6493, alors que le kit 610-3589 associe les '
                .'deux. La fiche officielle liste aussi de nombreuses machines (groupes C6.6 / '
                .'C7.1, chargeuses 953 / 963, tractopelles 432F / 444F, tracteurs D5 / D6K / D6N, '
                .'compacteurs CS/CP, etc.), avec la réserve que la compatibilité dépend du numéro '
                .'de série. Seules les machines pour lesquelles une source nomme aussi un kit ou '
                .'un modèle précis sont enregistrées comme compatibilités. Source : '
                .'parts.cat.com/en/catcorp/product/462-1171.',
        ],
        [
            'sku'        => '435-6493',
            'fabricant'  => 'Caterpillar',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant secondaire Caterpillar 435-6493',
            'description' => 'Filtre à carburant secondaire Caterpillar, efficacité « Ultra High '
                .'Efficiency » selon le catalogue officiel (parfois présenté comme Advanced High '
                .'Efficiency sur l\'emballage). Fonction DIFFÉRENTE de 462-1171. La fiche '
                .'officielle le donne pour des groupes C7.1 (DE150E, DE165GC, DE200E / DE200GC, '
                .'DE220GC, XQP150, C7.1 GEN) et la niveleuse 140 GC. RÉSERVE : le kit 610-3587 du '
                .'DE150GC ne contient PAS cette référence, malgré la présence du DE150GC sur la '
                .'même fiche — le DE150GC n\'est donc pas relié ici. Vérifier le numéro de série. '
                .'Source : parts.cat.com/en/catcorp/product/435-6493.',
        ],

        // ── Fleetguard — filtration Cummins (equipementier d'origine) ───
        [
            'sku'        => 'LF4054',
            'fabricant'  => 'Fleetguard',
            'type'       => PartType::Oes,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur Fleetguard LF4054',
            'description' => 'Filtre à huile moteur Fleetguard, montage à visser. La fiche '
                .'technique Cummins Filtration donne une hauteur de 210,1 mm, un diamètre de '
                .'93,5 mm et un filetage 1-12 UNF-2B, sans liste d\'applications. Les listes de '
                .'moteurs Cummins (QSC, QSL, ISX, QSK…) trouvées chez des revendeurs ne sont pas '
                .'retenues : leurs cotes ne correspondent pas toutes à cette fiche. Aucune '
                .'compatibilité machine n\'est donc enregistrée. Source : fiche LF4054 Cummins '
                .'Filtration (2023).',
        ],
        [
            'sku'        => 'FF5018',
            'fabricant'  => 'Fleetguard',
            'type'       => PartType::Oes,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant Fleetguard FF5018',
            'description' => 'Filtre à carburant Fleetguard, montage à visser. ATTENTION : le '
                .'filtre photographié porte le marquage Cummins 3931063, mais le catalogue pièces '
                .'Cummins et la fiche Fleetguard de FF5052 associent 3931063 à FF5052, pas à '
                .'FF5018. Le numéro 3931063 n\'est donc PAS enregistré comme équivalent, et '
                .'aucune vente ne doit s\'appuyer dessus. Des catalogues après-vente le donnent '
                .'aussi pour des moteurs Cummins B3.9, B5.9 et C8.3 : ces rapprochements ne sont '
                .'pas enregistrés comme compatibilités. Sources : catalogue Cummins ; '
                .'fleetguard.com/product/FF5052.',
        ],
        [
            'sku'        => 'FS1280',
            'fabricant'  => 'Fleetguard',
            'type'       => PartType::Oes,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant Fleetguard FS1280 (séparateur d\'eau)',
            'description' => 'Filtre à carburant / séparateur d\'eau Fleetguard, montage à '
                .'visser (hauteur 165 mm, diamètre 94 mm, filetage 13/16-18). Équivalents '
                .'Cummins confirmés par des catalogues Fleetguard : 3930942, 3890706 et 3903410. '
                .'Des revendeurs le donnent pour des moteurs Cummins 4BTA, 6BTA et 6CTA à double '
                .'filtre : ces familles sont notées ici, mais aucune machine (Dongfeng ou autre) '
                .'n\'est enregistrée, faute d\'une source qui nomme à la fois l\'engin et la '
                .'pièce. Sources : catalogues Fleetguard / Peterbilt Parts (FS1280) ; Inneova.',
            'equivalents' => ['3930942', '3890706', '3903410'],
        ],

        // ── JCB — tractopelles ──────────────────────────────────────────
        [
            'sku'        => '320/07155',
            'fabricant'  => 'JCB',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant JCB 320/07155',
            'description' => 'Filtre à carburant JCB. Le marquage R6354020 figure aussi sur le '
                .'filtre photographié et est enregistré comme équivalent. Les catalogues de '
                .'pièces spécialisés le donnent pour les tractopelles 3CX / 3DX / 4CX selon '
                .'version et numéro de série — seules 3CX et 3DX sont reliées, avec une réserve '
                .'forte de n° de série. REMPLACE EN CHAÎNE : 320/07155 → 320/07394 → 320/A7170.',
            'equivalents' => ['R6354020'],
            'remplace_par' => ['320/07394', '320/A7170'],
        ],
    ];

    public function run(): void
    {
        $poses = 0;
        $retirees = [];

        foreach (self::FILTRES as $filtre) {
            $categorie = PartCategory::where('name', $filtre['categorie'])->first();

            if ($categorie === null) {
                $this->command?->error(
                    "  Categorie « {$filtre['categorie']} » absente : {$filtre['sku']} ignore. "
                    .'Lancer PartCategoriesSeeder d\'abord.'
                );

                continue;
            }

            $fabricant = Manufacturer::firstOrCreate(['name' => $filtre['fabricant']]);

            // `withTrashed()` : `Part` est en suppression douce et `sku` est
            // unique EN BASE, corbeille comprise. Sans cela, une reference
            // mise a la corbeille depuis l'application ferait echouer la
            // creation sur la contrainte d'unicite, et l'exception priverait
            // de leur mise a jour toutes les references suivantes.
            $piece = Part::withTrashed()->where('sku', $filtre['sku'])->first();

            // Une reference a la corbeille y reste : la ressusciter remettrait
            // au catalogue ce que le commercant en a retire.
            if ($piece !== null && $piece->trashed()) {
                $retirees[] = $filtre['sku'];

                continue;
            }

            $description = [
                'name'                   => $filtre['nom'],
                'description'            => $filtre['description'],
                'part_category_id'       => $categorie->id,
                'manufacturer_id'        => $fabricant->id,
                'manufacturer_reference' => $filtre['sku'],
                'type'                   => $filtre['type']->value,
                'condition'              => 'new',
            ];

            if ($piece === null) {
                // Prix, stock et publication ne sont poses QU'A LA CREATION,
                // et a des valeurs qui ne peuvent rien promettre de faux.
                $piece = Part::create(array_merge(
                    ['sku' => $filtre['sku']],
                    $description,
                    ['selling_price' => 0, 'stock_quantity' => 0, 'is_active' => false],
                ));
                $poses++;
            } else {
                // Le descriptif se corrige ; le commerce ne se reecrit pas.
                $piece->update($description);
            }

            $this->rattacherNumeros($piece, $filtre);
        }

        $this->command?->info(sprintf(
            '  Filtres industriels : %d reference(s) creee(s) sur %d, NON PUBLIEES (prix a fixer).',
            $poses,
            count(self::FILTRES),
        ));

        if ($retirees !== []) {
            $this->command?->warn(
                '  Laissees a la corbeille, non ressuscitees : '.implode(', ', $retirees)
            );
        }
    }

    /**
     * Le numero de la piece, ses equivalents, et sa chaine de remplacement.
     *
     * `brand_id` reste nul : ces numeros sont emis par un motoriste ou un
     * equipementier, pas par une marque du referentiel vehicules — lequel ne
     * connait d'ailleurs encore aucune de ces marques.
     */
    private function rattacherNumeros(Part $piece, array $filtre): void
    {
        $numero = fn (string $n, bool $perime = false) => OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize($n), 'brand_id' => null],
            ['number' => $n, 'label' => $filtre['nom'], 'is_superseded' => $perime],
        );

        $actuel = $numero($filtre['sku']);
        $piece->oemNumbers()->syncWithoutDetaching([$actuel->id => ['is_primary' => true]]);

        foreach ($filtre['equivalents'] ?? [] as $equivalent) {
            $piece->oemNumbers()->syncWithoutDetaching([$numero($equivalent)->id => ['is_primary' => false]]);
        }

        // La chaine de remplacement, maillon par maillon. Chaque reference
        // reste rattachee a l'article : un client qui arrive avec l'ancienne
        // comme avec la nouvelle trouve le meme filtre, et la base sait
        // laquelle a remplace laquelle.
        $precedent = $actuel;

        foreach ($filtre['remplace_par'] ?? [] as $suivant) {
            $maillon = $numero($suivant);
            $piece->oemNumbers()->syncWithoutDetaching([$maillon->id => ['is_primary' => false]]);

            $precedent->update(['is_superseded' => true, 'superseded_by_id' => $maillon->id]);
            $precedent = $maillon;
        }
    }
}
