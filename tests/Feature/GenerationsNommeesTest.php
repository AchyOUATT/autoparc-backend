<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nommer une generation sans dupliquer le modele.
 *
 * Le piege est dans l'assemblage, pas dans la donnee. `VehicleModelsSeeder` se
 * rejoue a chaque demarrage du conteneur, et son upsert porte sur le couple
 * (brand_id, slug) — un slug qui contient la generation :
 *
 *     slug = Str::slug($nom . ($generation ? '-' . $generation : ''))
 *
 * Poser une generation dans le seeder sans renommer le slug des lignes
 * existantes ferait donc INSERER une seconde ligne au prochain deploiement.
 * Deux « CX-3 » dans le selecteur, et les compatibilites declarees sur
 * l'ancienne invisibles depuis la nouvelle : un defaut qui ne se verrait qu'en
 * production, et seulement a l'usage.
 */
class GenerationsNommeesTest extends TestCase
{
    use RefreshDatabase;

    /** Les modeles nommes par la migration, avec leur slug attendu. */
    private const ATTENDUS = [
        ['mazda',     'CX-3',          'DK',              'cx-3-dk'],
        ['lexus',     'UX',            'ZA10',            'ux-za10'],
        ['fiat',      '500',           'Type 312',        '500-type-312'],
        ['toyota',    'Rush',          'F800',            'rush-f800'],
        ['ssangyong', 'Actyon',        'C100',            'actyon-c100'],
        ['mazda',     'Tribute',       'II (marché NA)',  'tribute-ii-marche-na'],
    ];

    private function marques(): void
    {
        Artisan::call('db:seed', ['--class' => \Database\Seeders\CountriesSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\BrandsSeeder::class, '--force' => true]);
    }

    /**
     * La vraie chaine de demarrage, dans l'ordre du Dockerfile.
     *
     * Surtout pas ReferenceDataSeeder : c'est un jeu d'essai qui cree ses
     * propres Corolla et 208 sans generation. Combine au referentiel reel, il
     * produit des doublons qui n'existent que dans les tests — et masquerait
     * donc exactement le defaut que ce fichier surveille.
     */
    private function referentiel(): void
    {
        $this->marques();
        Artisan::call('db:seed', ['--class' => \Database\Seeders\VehicleModelsSeeder::class, '--force' => true]);
    }

    /**
     * Une ligne telle qu'elle existe en production : sans generation, et avec
     * l'ancien slug.
     *
     * Indispensable, et ce fichier a failli ne rien verifier sans cela :
     * RefreshDatabase part d'une table vide, la migration n'y renomme rien, et
     * le seeder insere ensuite tout avec les bons slugs. Le defaut surveille ne
     * peut pas survenir dans ce decor — la premiere version de ce test passait
     * encore apres qu'on eut retire le renommage de la migration. Il faut donc
     * recreer l'etat d'AVANT.
     */
    private function ligneAncienneFacon(string $marque, string $nom, int $debut, ?int $fin, string $carrosserie): int
    {
        return DB::table('vehicle_models')->insertGetId([
            'brand_id'         => Brand::where('slug', $marque)->value('id'),
            'name'             => $nom,
            'generation'       => null,
            'slug'             => Str::slug($nom),
            'body_type'        => $carrosserie,
            'segment'          => 'C',
            'production_start' => $debut,
            'production_end'   => $fin,
            'is_active'        => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** Joue la migration de nommage sur la base telle qu'elle est. */
    private function jouerLaMigration(): void
    {
        (require database_path('migrations/2026_10_03_000003_nommer_les_generations_manquantes.php'))->up();
    }

    // ── Le defaut que tout ceci evite ────────────────────────────────────────

    /**
     * LE test : une ligne deja en base survit au nommage sans se dedoubler.
     *
     * C'est la sequence du Dockerfile — migrate, puis db:seed — jouee sur une
     * base qui contient deja la ligne. Sans le renommage de slug dans la
     * migration, le seeder ne reconnait pas la ligne existante et en insere une
     * seconde.
     */
    public function test_une_ligne_existante_est_renommee_et_non_dupliquee(): void
    {
        $this->marques();

        $cx3Id     = $this->ligneAncienneFacon('mazda', 'CX-3', 2015, null, 'suv');
        $tributeId = $this->ligneAncienneFacon('mazda', 'Tribute', 2007, 2011, 'suv');

        $this->jouerLaMigration();
        Artisan::call('db:seed', ['--class' => \Database\Seeders\VehicleModelsSeeder::class, '--force' => true]);

        $mazda = Brand::where('slug', 'mazda')->value('id');

        $cx3 = VehicleModel::where('brand_id', $mazda)->where('name', 'CX-3')->get();
        $this->assertCount(1, $cx3, "Le seeder a insere un second CX-3 : le slug n'a pas suivi la generation.");
        $this->assertSame($cx3Id, $cx3->first()->id, "La ligne d'origine doit etre conservee, pas remplacee.");
        $this->assertSame('DK', $cx3->first()->generation);
        $this->assertSame('cx-3-dk', $cx3->first()->slug);

        $tribute = VehicleModel::where('brand_id', $mazda)->where('name', 'Tribute')->where('production_start', 2007)->get();
        $this->assertCount(1, $tribute, 'Le Tribute 2007 ne doit pas se dedoubler.');
        $this->assertSame($tributeId, $tribute->first()->id);
    }

    /**
     * L'identifiant est preserve.
     *
     * Les compatibilites declarees et les vehicules du garage pointent sur
     * `vehicle_models.id` : une ligne remplacee plutot que modifiee les
     * emporterait toutes.
     */
    public function test_le_nommage_preserve_l_identifiant(): void
    {
        $this->marques();

        $id = $this->ligneAncienneFacon('lexus', 'UX', 2018, null, 'suv');

        $this->jouerLaMigration();

        $apres = VehicleModel::find($id);
        $this->assertNotNull($apres, 'La ligne ne doit pas disparaitre : des fitments pointent sur son id.');
        $this->assertSame('ZA10', $apres->generation);
        $this->assertSame('ux-za10', $apres->slug);
    }

    /** Une generation deja posee a la main n'est pas ecrasee. */
    public function test_une_generation_deja_renseignee_est_laissee_tranquille(): void
    {
        $this->marques();

        $id = $this->ligneAncienneFacon('mazda', 'CX-3', 2015, null, 'suv');
        DB::table('vehicle_models')->where('id', $id)->update(['generation' => 'DK saisi a la main']);

        $this->jouerLaMigration();

        $this->assertSame('DK saisi a la main', VehicleModel::find($id)->generation);
    }

    // ── Le referentiel au complet ────────────────────────────────────────────

    public function test_le_seeder_rejoue_ne_duplique_aucun_modele(): void
    {
        $this->referentiel();

        $avant = VehicleModel::count();

        Artisan::call('db:seed', ['--class' => \Database\Seeders\VehicleModelsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\VehicleModelsSeeder::class, '--force' => true]);

        $this->assertSame($avant, VehicleModel::count(), 'Rejouer le seeder ne doit rien ajouter.');

        $doublons = DB::table('vehicle_models')
            ->select('brand_id', 'name', 'production_start', DB::raw('COUNT(*) as n'))
            ->groupBy('brand_id', 'name', 'production_start')
            ->having('n', '>', 1)
            ->get();

        $this->assertCount(0, $doublons, 'Deux lignes pour le meme modele a la meme annee de debut.');
    }

    public function test_la_migration_et_le_seeder_posent_les_memes_slugs(): void
    {
        $this->referentiel();

        foreach (self::ATTENDUS as [$marque, $nom, $generation, $slug]) {
            $marqueId = Brand::where('slug', $marque)->value('id');
            $this->assertNotNull($marqueId, "Marque {$marque} absente du referentiel.");

            // On vise la ligne par sa generation : un modele peut en avoir
            // plusieurs, et le Tribute en a justement deux.
            $ligne = VehicleModel::where('brand_id', $marqueId)
                ->where('name', $nom)
                ->where('generation', $generation)
                ->get();

            $this->assertCount(1, $ligne, "{$nom} ({$generation}) doit exister en une seule ligne.");
            $this->assertSame($slug, $ligne->first()->slug, "Le slug de {$nom} doit etre celui que le seeder recalcule.");
        }
    }

    // ── Ce que cette reprise ne fait PAS ─────────────────────────────────────

    /**
     * Aucune plage n'est fermee.
     *
     * La revision du referentiel en proposait quatre-vingt-deux. Verification
     * faite aupres des constructeurs sur quatorze d'entre elles, neuf etaient
     * fausses — la date d'arret europeenne prise pour une date mondiale, alors
     * que la production continuait sur les marches qui approvisionnent
     * l'Afrique de l'Ouest. Une plage fermee a tort refuse l'enregistrement
     * d'un vehicule parfaitement reel.
     */
    public function test_aucune_plage_n_est_fermee_par_cette_reprise(): void
    {
        $this->referentiel();

        $ouvertes = [
            ['fiat',      '500',         2007],
            ['chevrolet', 'Trailblazer', 2012],
            ['renault',   'Mégane',      2015],
            ['kia',       'Cerato',      2018],
            ['lexus',     'RC',          2014],
        ];

        foreach ($ouvertes as [$marque, $nom, $debut]) {
            $modele = VehicleModel::where('brand_id', Brand::where('slug', $marque)->value('id'))
                ->where('name', $nom)
                ->where('production_start', $debut)
                ->first();

            $this->assertNotNull($modele, "{$nom} introuvable.");
            $this->assertNull(
                $modele->production_end,
                "{$nom} doit rester ouvert : la production continue hors d'Europe.",
            );
        }
    }

    /**
     * Les vingt-cinq codes volontairement laisses de cote.
     *
     * Ils nomment la premiere d'un jeu de plusieurs generations que la revision
     * veut substituer a notre ligne unique. Les reprendre sans la scission
     * collerait l'etiquette « A9 » sur une 208 declaree « depuis 2012 », alors
     * que la A9 s'arrete en 2019 : l'ecran annoncerait une generation precise
     * et fausse. Une case vide dit « on ne sait pas ».
     */
    public function test_les_modeles_a_scinder_restent_sans_generation(): void
    {
        $this->referentiel();

        // Le Suzuki Alto figure ici parce qu'il avait ete nomme par erreur :
        // la revision le scinde en HA36 (2015-2021) et HA37/HA97 (2021-), et
        // notre ligne court toujours. Une migration lui a rendu sa case vide.
        foreach ([['peugeot', '208'], ['renault', 'Duster'], ['volkswagen', 'Amarok'], ['mercedes-benz', 'Sprinter'], ['suzuki', 'Alto']] as [$marque, $nom]) {
            $modele = VehicleModel::where('brand_id', Brand::where('slug', $marque)->value('id'))
                ->where('name', $nom)
                ->first();

            $this->assertNotNull($modele, "{$nom} introuvable.");
            $this->assertNull(
                $modele->generation,
                "{$nom} couvre plusieurs generations : lui en coller une seule serait faux.",
            );
        }
    }

    // ── Les quatre modeles ajoutes ───────────────────────────────────────────

    public function test_les_nouveaux_modeles_sont_distincts_de_leur_voisin(): void
    {
        $this->referentiel();

        foreach ([['fiat', '500e'], ['opel', 'Zafira Life'], ['ssangyong', 'Actyon Sports'], ['subaru', 'Crosstrek']] as [$marque, $nom]) {
            $this->assertTrue(
                VehicleModel::where('brand_id', Brand::where('slug', $marque)->value('id'))->where('name', $nom)->exists(),
                "{$nom} doit exister.",
            );
        }

        // La correction de carrosserie qui accompagne l'Actyon Sports : une
        // seule ligne classee pick-up couvrait les deux vehicules. Ajouter le
        // pick-up sans reclasser le SUV aurait donne deux pick-up et aucun SUV.
        $ssangyong = Brand::where('slug', 'ssangyong')->value('id');
        $this->assertSame('suv', VehicleModel::where('brand_id', $ssangyong)->where('name', 'Actyon')->value('body_type'));
        $this->assertSame('pick-up', VehicleModel::where('brand_id', $ssangyong)->where('name', 'Actyon Sports')->value('body_type'));
    }
}
