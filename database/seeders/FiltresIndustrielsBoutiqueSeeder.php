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
            'description' => 'Filtre à gazole avec fonction de séparation d\'eau, moteurs Perkins. '
                .'Équipementier MANN le donne pour les séries 1004 et 1006. Compatibilités à '
                .'confirmer engin par engin : aucune n\'est enregistrée à ce jour.',
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
            'description' => 'Filtre à carburant Perkins à fonction de séparation d\'eau, séries '
                .'1103 / 1104 / 1106 selon montage.',
        ],

        // ── John Deere — tracteurs et engins forestiers ─────────────────
        [
            'sku'        => 'RE504836',
            'fabricant'  => 'John Deere',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur John Deere RE504836',
            'description' => 'Filtre à huile moteur John Deere. Le guide constructeur le donne '
                .'pour les 5090M, 5100M et 5115M dans les configurations documentées.',
        ],
        [
            'sku'        => 'RE59754',
            'fabricant'  => 'John Deere',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur John Deere RE59754',
            'description' => 'Filtre à huile moteur John Deere. Référence DISTINCTE de RE504836, '
                .'confirmée comme telle au catalogue officiel : les deux ne se remplacent pas.',
        ],

        // ── Caterpillar — chargeuses, niveleuses, groupes ───────────────
        [
            'sku'        => '462-1171',
            'fabricant'  => 'Caterpillar',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur Caterpillar 462-1171',
            'description' => 'Filtre à huile moteur Caterpillar. Entre dans le kit d\'entretien '
                .'CAT 610-3589 (« C7.1 PM Kit Filters »), vérifié chez le constructeur, aux côtés '
                .'de 435-6493 en deux exemplaires et du filtre à air 130-4678. '
                .'LA LISTE DE MOTEURS A ÉTÉ RETIRÉE : cette description portait « moteurs C4.4 / '
                .'C6.6 / C7.1 selon configuration », repris du document source et jamais vérifié '
                .'chez Caterpillar — une contre-expertise du 24/10/2026 attribue cette liste à une '
                .'AUTRE référence. Un filtre C7.1 posé sur une machine à C9.3 est exactement '
                .'l\'erreur qui a fait refuser quinze lignes du catalogue Toyota.',
        ],
        [
            'sku'        => '435-6493',
            'fabricant'  => 'Caterpillar',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant secondaire Caterpillar 435-6493',
            'description' => 'Filtre à carburant secondaire Caterpillar, moteurs C7.1. Fonction '
                .'DIFFERENTE de 462-1171 : les deux équipent la même installation, le kit '
                .'d\'entretien 610-3589 en contient deux de celui-ci pour un de l\'autre.',
        ],

        // ── Fleetguard — filtration Cummins (equipementier d'origine) ───
        [
            'sku'        => 'LF4054',
            'fabricant'  => 'Fleetguard',
            'type'       => PartType::Oes,
            'categorie'  => 'Filtre à huile',
            'nom'        => 'Filtre à huile moteur Fleetguard LF4054',
            'description' => 'Filtre à huile moteur Fleetguard. Les catalogues équipementiers le '
                .'donnent pour des DAF 65 / 65 CF et des Ford Cargo anciens ; équivalence à '
                .'confirmer avant toute vente sur cette base.',
        ],
        [
            'sku'        => 'FF5018',
            'fabricant'  => 'Fleetguard',
            'type'       => PartType::Oes,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant Fleetguard FF5018',
            'description' => 'ATTENTION, INCOHERENCE DE REFERENCE A RESOUDRE. Le filtre porte le '
                .'marquage Cummins 3931063, mais un manuel Cummins C8.3 associe 3931063 à '
                .'Fleetguard FF5052, pas à FF5018. Le numéro Cummins n\'est donc PAS enregistré '
                .'comme équivalent, et aucune vente ne doit se fonder dessus avant vérification.',
        ],
        [
            'sku'        => 'FS1280',
            'fabricant'  => 'Fleetguard',
            'type'       => PartType::Oes,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant Fleetguard FS1280 (séparateur d\'eau)',
            'description' => 'Filtre à carburant avec séparation d\'eau. Le catalogue des '
                .'applications poids lourds le donne pour Cummins 3930942, et les Dongfeng à '
                .'moteurs 6BTA5.9 et 6CTA8.3 y sont nommés. Ces engins n\'existent pas encore au '
                .'référentiel : aucune compatibilité n\'est posée.',
            'equivalents' => ['3930942'],
        ],

        // ── JCB — tractopelles ──────────────────────────────────────────
        [
            'sku'        => '320/07155',
            'fabricant'  => 'JCB',
            'type'       => PartType::Oem,
            'categorie'  => 'Filtre à carburant',
            'nom'        => 'Filtre à carburant JCB 320/07155',
            'description' => 'Filtre à carburant JCB, tractopelles 3CX / 4CX et séries 3C / 3D / '
                .'3DX selon version et numéro de série. Fiche technique à confirmer. REMPLACE '
                .'EN CHAINE : 320/07155 → 320/07394 → 320/A7170, chaîne enregistrée telle quelle.',
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
