<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nomme dix-neuf generations qui n'avaient pas de code.
 *
 * Quarante-sept modeles du referentiel n'avaient aucune generation renseignee.
 * Une revision du referentiel, menee a partir des salles de presse des
 * constructeurs, en propose quarante-quatre. Dix-neuf sont reprises ici, et
 * c'est tout ce qui est repris : ni les annees, ni les scissions.
 *
 * POURQUOI DIX-NEUF ET NON QUARANTE-QUATRE.
 *
 * Vingt-cinq des codes proposes nomment la PREMIERE d'un jeu de plusieurs
 * generations que la revision veut substituer a notre ligne unique. Les
 * reprendre sans la scission qui va avec collerait l'etiquette « A9 » sur une
 * Peugeot 208 declaree « depuis 2012 » — alors que la A9 s'arrete en 2019.
 * L'ecran annoncerait une generation precise, et fausse, au proprietaire d'une
 * 208 de 2022. Une case vide dit « on ne sait pas » ; un mauvais code dit « on
 * sait », et se trompe. Ces vingt-cinq lignes restent donc sans generation
 * jusqu'a ce que la scission soit tranchee.
 *
 * Les dix-neuf retenues sont celles dont le code couvre REELLEMENT toute la
 * ligne : la revision ne leur connait qu'une generation.
 *
 * POURQUOI LE SLUG CHANGE EN MEME TEMPS.
 *
 * `VehicleModelsSeeder` se rejoue a chaque demarrage, et son upsert porte sur
 * le couple (brand_id, slug) — un slug qui contient la generation. Poser une
 * generation dans le seeder sans renommer le slug ici ferait INSERER une
 * seconde ligne au prochain deploiement : deux « CX-3 » dans la liste, et les
 * compatibilites declarees sur l'ancienne rendues invisibles depuis la
 * nouvelle. Les migrations tournent avant les seeders : le renommage a lieu
 * ici pour que l'upsert retrouve ensuite la ligne existante.
 *
 * Rien n'est touche d'autre : aucune annee de debut, aucune annee de fin. La
 * revision proposait aussi quatre-vingt-deux fermetures de plages ; verification
 * faite aupres des constructeurs sur un echantillon de quatorze, neuf etaient
 * fausses — et toutes dans le meme sens, celui d'une date d'arret europeenne
 * prise pour une date mondiale, alors que la production continuait sur les
 * marches qui approvisionnent l'Afrique de l'Ouest. Fermer une plage a tort
 * empeche un enregistrement ; on ne ferme donc rien.
 */
return new class extends Migration
{
    /**
     * [slug de marque, nom du modele, annee de debut, generation]
     *
     * L'annee de debut fait partie de la cle : elle distingue la bonne ligne
     * quand un modele en a plusieurs, et empeche de nommer une generation
     * voisine par accident.
     */
    private const GENERATIONS = [
        ['mitsubishi',    'Eclipse Cross', 2017, 'GK/GL'],
        ['suzuki',        'Alto',          2015, 'HA36'],
        ['kia',           'Stonic',        2017, 'YB CUV'],
        ['kia',           'Seltos',        2019, 'SP2'],
        ['peugeot',       '301',           2012, 'M33'],
        ['peugeot',       '307',           2001, 'T5'],
        ['toyota',        'Rush',          2017, 'F800'],
        ['mazda',         'CX-3',          2015, 'DK'],
        ['lexus',         'UX',            2018, 'ZA10'],
        ['lexus',         'CT',            2010, 'ZWA10'],
        ['lexus',         'RC',            2014, 'XC10'],
        ['fiat',          'Tipo',          2015, '356'],
        ['fiat',          '500',           2007, 'Type 312'],
        ['fiat',          '500X',          2014, 'Type 334'],
        ['ford',          'EcoSport',      2012, 'B515 / II'],
        ['chevrolet',     'Aveo',          2011, 'T300'],
        ['chevrolet',     'Trailblazer',   2012, 'RG'],
        ['ssangyong',     'Musso',         2018, 'Q200/Q250'],
        ['ssangyong',     'Actyon',        2006, 'C100'],
        ['mazda',         'Tribute',       2007, 'II (marché NA)'],
    ];

    public function up(): void
    {
        $marques = DB::table('brands')->pluck('id', 'slug')->all();
        $nommees = 0;

        foreach (self::GENERATIONS as [$marque, $nom, $debut, $generation]) {
            if (! isset($marques[$marque])) {
                continue;
            }

            $nommees += DB::table('vehicle_models')
                ->where('brand_id', $marques[$marque])
                ->where('name', $nom)
                ->where('production_start', $debut)
                // Seulement si la case est vide : une generation deja posee a
                // la main, depuis l'application ou par un correctif ulterieur,
                // ne doit pas etre ecrasee par cette reprise.
                ->whereNull('generation')
                ->update([
                    'generation' => $generation,
                    'slug'       => Str::slug($nom.'-'.$generation),
                    'updated_at' => now(),
                ]);
        }

        echo sprintf('  %d generation(s) nommee(s).%s', $nommees, PHP_EOL);
    }

    /**
     * Le retour arriere rend les cases vides et les slugs d'origine.
     *
     * Il ne vise que les lignes dont la generation est exactement celle posee
     * ici : une correction faite entre-temps est laissee tranquille.
     */
    public function down(): void
    {
        $marques = DB::table('brands')->pluck('id', 'slug')->all();

        foreach (self::GENERATIONS as [$marque, $nom, $debut, $generation]) {
            if (! isset($marques[$marque])) {
                continue;
            }

            DB::table('vehicle_models')
                ->where('brand_id', $marques[$marque])
                ->where('name', $nom)
                ->where('production_start', $debut)
                ->where('generation', $generation)
                ->update([
                    'generation' => null,
                    'slug'       => Str::slug($nom),
                    'updated_at' => now(),
                ]);
        }
    }
};
