<?php

namespace Database\Seeders;

use App\Enums\FitmentSource;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Le catalogue de filtres a huile de la boutique.
 *
 * Premieres vraies pieces du catalogue, apres le vidage des donnees de
 * demonstration. Huit references Toyota, vendues en equivalent adaptable.
 *
 * Sur les compatibilites, ce seeder est beaucoup plus avare que le document
 * d'ou il vient, et c'est voulu.
 *
 * Le premier document fournisseur donnait 98 lignes. La moitie ne trouve aucun
 * modele au referentiel, qui commence vers 2002 quand le document couvre des
 * vehicules des annees 1980. Sur le reste, le commercant a audite son propre
 * document page par page et conclu que deux pages etaient a refaire, deux
 * pleines d'erreurs et trois a remplacer : intervalles de trente ans,
 * motorisations melangees, et surtout des generations a filtre visse confondues
 * avec des generations a cartouche — ce qu'aucun carter n'accepte.
 *
 * N'ont ete retenues que les dix lignes qu'il a pu confirmer sur des sources
 * constructeur. Les autres attendent. Un document corrige, qui cite ses
 * sources, pose d'ailleurs la regle que ces lignes violaient :
 *
 *     Ne jamais confirmer un filtre sur la seule base du modele et de la
 *     cylindree. Verifier au minimum annee + code moteur.
 *
 * D'ou le quatrieme element de chaque ligne : le code moteur. Une
 * compatibilite qui ne le porte pas n'a pas sa place ici.
 *
 * Les modeles sont designes par leur SLUG, jamais par leur identifiant. Les
 * identifiants different d'une base a l'autre : un seeder qui les figerait
 * serait juste en production et faux partout ailleurs, tests compris. Un modele
 * introuvable est signale, pas devine.
 *
 * Les lignes ecrites portent l'origine `declared` : elles viennent du
 * commercant, pas du remplissage automatique du catalogue, et
 * `catalog:backfill-fitments` ne doit jamais les toucher.
 *
 * Le seeder est rejouable : il retrouve ses pieces par SKU et remplace leurs
 * compatibilites plutot que d'en empiler.
 */
class FiltresHuileBoutiqueSeeder extends Seeder
{
    /** Ce que le commercant facture, en francs CFA. */
    private const PRIX = 3000;

    /**
     * L'origine declaree par le commercant.
     *
     * « Chine » designe une provenance plutot qu'un equipementier, et se
     * retrouve donc a cote de Bosch et de Denso dans une liste qui ne contient
     * sinon que des fabricants. C'est la seule place structuree disponible, et
     * c'est l'information qui compte pour l'acheteur : elle explique le prix.
     */
    private const ORIGINE = 'Chine';

    private const CATEGORIE = 'Filtre à huile';

    /**
     * Les huit references en stock, et leurs compatibilites confirmees.
     *
     * Chaque compatibilite est `[slug du modele, annee de debut, annee de fin,
     * code moteur]`. Les annees nulles signifient « toute la generation ».
     *
     * Cinq references n'en portent aucune : leurs lignes attendent une
     * verification moteur par moteur. Elles restent trouvables par reference et
     * par numero constructeur, ce qui est honnete — le catalogue ne promet rien
     * qu'il ne puisse tenir.
     *
     * @var list<array{sku: string, nom: string, description: string, remplace?: list<string>, compatibilites: list<array{0: string, 1: ?int, 2: ?int, 3: string}>}>
     */
    private const FILTRES = [
        [
            "sku"           => "90915-YZZF2",
            "nom"           => "Filtre a huile visse 90915-YZZF2",
            "description"   => "Visse, quatre cylindres. Reference ancienne, remplacee par le 90915-YZZN1 sur certains catalogues de service.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZE1",
            "nom"           => "Filtre a huile visse 90915-YZZE1",
            "description"   => "Visse, compact. Remplace par le 90915-YZZN2 sur certains catalogues de service.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZD1",
            "nom"           => "Filtre a huile visse 90915-YZZD1",
            "description"   => "Visse, anciennes applications V6 et certains utilitaires essence. Ne convient pas a tous les gros moteurs Toyota.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZD2",
            "nom"           => "Filtre a huile visse 90915-YZZD2",
            "description"   => "Visse, moteurs diesel 2KD-FTV 2.5, 1KD-FTV 3.0, 2GD-FTV 2.4, 1GD-FTV 2.8 et certains 2TR-FE 2.7.",
            "compatibilites" => [
                ["hilux-an10", 2005, 2015, "1KD-FTV"],
                ["hilux-an120", 2015, 2020, "2GD-FTV"],
                ["land-cruiser-prado-j120", 2003, 2009, "1KD-FTV"],
                ["land-cruiser-prado-j150", 2009, 2015, "1KD-FTV"],
                ["fortuner-an50", null, null, "2KD-FTV"],
                ["fortuner-an160", 2015, 2020, "2GD-FTV"],
            ],
        ],
        [
            "sku"           => "90915-YZZN1",
            "nom"           => "Filtre a huile visse 90915-YZZN1",
            "description"   => "Visse, reference de service. Remplace le 90915-YZZF2 et le 90915-10009 selon le marche.",
            // Ancien numero de service, encore imprime sur des boites : un
            // client qui le cherche doit trouver ce filtre.
            "remplace"      => ["90915-10009"],
            "compatibilites" => [
                ["camry-xv70", 2018, 2020, "A25A-FKS"],
                ["rav4-xa50", 2019, null, "A25A-FKS"],
            ],
        ],
        [
            "sku"           => "90915-YZZN2",
            "nom"           => "Filtre a huile visse 90915-YZZN2",
            "description"   => "Visse compact, moteurs 1NZ-FE, 2NR-FE/FBE, 1ZZ-FE et 3ZZ-FE.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "04152-YZZA1",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA1",
            "description"   => "Element filtrant, moteurs 2AR-FE, 2AR-FXE, 6AR-FSE/FBS et nombreux 2GR. Ne convient pas aux A25A recents.",
            "compatibilites" => [
                ["camry-xv50", null, null, "2AR-FE"],
                ["rav4-xa40", null, null, "2AR-FE"],
            ],
        ],
        [
            "sku"           => "04152-YZZA6",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA6",
            "description"   => "Element filtrant court, moteurs 1.8L de la famille 2ZR.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZD4",
            "nom"           => "Filtre a huile visse 90915-YZZD4",
            "description"   => "Visse, gros moteurs essence 1GR-FE 4.0, 2UZ-FE 4.7 et 1FZ-FE 4.5. Ne convient pas aux diesel KD, qui relevent du 90915-YZZD2.",
            "remplace"      => ["90915-20004"],
            "compatibilites" => [
                ["hilux-an10", 2005, 2013, "1GR-FE"],
                ["fortuner-an50", 2005, 2013, "1GR-FE"],
                ["land-cruiser-j100", 1998, 2007, "2UZ-FE"],
                ["land-cruiser-j200", 2007, 2012, "1GR-FE"],
                ["land-cruiser-prado-j120", 2003, 2009, "1GR-FE"],
                ["land-cruiser-prado-j120", 2002, 2009, "5L-E"],
            ],
        ],
        [
            "sku"           => "90915-10001",
            "nom"           => "Filtre a huile visse 90915-10001",
            "description"   => "Visse, ancienne reference remplacee par le 90915-YZZN1 sur les catalogues nord-americains. Applications anterieures a 2008, anterieures au referentiel.",
            "remplace"      => [],
            "compatibilites" => [
            ],
        ],
    ];

    public function run(): void
    {
        $categorie = PartCategory::where('name', self::CATEGORIE)->first();

        if ($categorie === null) {
            $this->command?->error(
                sprintf('Categorie « %s » introuvable : aucun filtre ecrit.', self::CATEGORIE)
            );

            return;
        }

        $origine = Manufacturer::firstOrCreate(['name' => self::ORIGINE]);

        $ecrits = 0;
        $lignes = 0;
        $absents = [];

        foreach (self::FILTRES as $filtre) {
            DB::transaction(function () use ($filtre, $categorie, $origine, &$ecrits, &$lignes, &$absents) {
                $piece = Part::updateOrCreate(
                    ['sku' => $filtre['sku']],
                    [
                        'name'                   => $filtre['nom'],
                        'description'            => $filtre['description'],
                        'part_category_id'       => $categorie->id,
                        'manufacturer_id'        => $origine->id,
                        'manufacturer_reference' => $filtre['sku'],
                        'type'                   => 'aftermarket',
                        'condition'              => 'new',
                        'selling_price'          => self::PRIX,
                        'is_active'              => true,
                    ],
                );

                $this->rattacherNumeros($piece, $filtre);

                // Remplacement plutot qu'empilement : le seeder se rejoue.
                $piece->fitments()->delete();

                foreach ($filtre['compatibilites'] as [$slug, $de, $a, $moteur]) {
                    $modele = VehicleModel::where('slug', $slug)->first();

                    if ($modele === null) {
                        $absents[] = $filtre['sku'].' → '.$slug;

                        continue;
                    }

                    $piece->fitments()->create([
                        'vehicle_model_id' => $modele->id,
                        'year_from'        => $de,
                        'year_to'          => $a,
                        'engine_code'      => $moteur,
                        'source'           => FitmentSource::Declared,
                    ]);

                    $lignes++;
                }

                $ecrits++;
            });
        }

        $this->command?->info(sprintf(
            '%d filtre(s), %d compatibilite(s) confirmee(s), %d F CFA piece.',
            $ecrits,
            $lignes,
            self::PRIX,
        ));

        // Un modele absent est une compatibilite perdue en silence : on le dit.
        foreach (array_unique($absents) as $manquant) {
            $this->command?->warn("  modele introuvable, compatibilite ignoree : {$manquant}");
        }
    }

    /**
     * Attache a la piece son numero constructeur, et les anciens qu'il remplace.
     *
     * Un client arrive souvent avec le numero imprime sur sa vieille boite, qui
     * n'est plus celui du catalogue. Sans la chaine de remplacement, sa
     * recherche ne rend rien alors que la piece est en rayon.
     *
     * @param  array{sku: string, nom: string, remplace?: list<string>}  $filtre
     */
    private function rattacherNumeros(Part $piece, array $filtre): void
    {
        $actuel = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize($filtre['sku']), 'brand_id' => null],
            ['number' => $filtre['sku'], 'label' => $filtre['nom'], 'is_superseded' => false],
        );

        $piece->oemNumbers()->syncWithoutDetaching([$actuel->id => ['is_primary' => true]]);

        foreach ($filtre['remplace'] ?? [] as $ancien) {
            $perime = OemNumber::firstOrCreate(
                ['normalized_number' => OemNumber::normalize($ancien), 'brand_id' => null],
                ['number' => $ancien, 'label' => $filtre['nom']],
            );

            $perime->update(['is_superseded' => true, 'superseded_by_id' => $actuel->id]);

            $piece->oemNumbers()->syncWithoutDetaching([$perime->id => ['is_primary' => false]]);
        }
    }
}
