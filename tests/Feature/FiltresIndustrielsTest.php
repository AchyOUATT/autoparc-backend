<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les onze filtres poids lourds et engins entrent, et n'entrent pas en vente.
 *
 * ── CE QUE CE BANC TIENT, ET POURQUOI ──────────────────────────────────────
 *
 * Trois promesses, dont deux sont commerciales avant d'etre techniques.
 *
 * UN. RIEN N'EST PUBLIE. Aucun prix n'est connu — les filtres industriels
 * valent plusieurs fois les 3 000 F du catalogue Toyota, et reprendre ce
 * chiffre « quitte a reajuster » aurait mis en ligne onze prix faux, visibles
 * par un client, pendant le temps qu'il faut pour s'en apercevoir. Le drapeau
 * les retire du catalogue public, le zero fait qu'un article publie par
 * megarde n'annonce pas un prix credible et faux. Le premier test mesure les
 * deux ensemble.
 *
 * DEUX. OEM ET OES NE SE CONFONDENT PAS. Perkins, John Deere, Caterpillar et
 * JCB fabriquent la machine ou son moteur : piece constructeur. Fleetguard est
 * la marque de filtration de Cummins, donc un equipementier d'origine. La
 * nuance se vend — un acheteur qui demande « du Cummins » accepte un
 * Fleetguard et refuse un adaptable.
 *
 * TROIS. LA CHAINE JCB SE RETROUVE PAR N'IMPORTE LAQUELLE DE SES TROIS
 * REFERENCES. 320/07155 est remplacee par 320/07394, elle-meme par 320/A7170.
 * Un client arrive avec celle qui est gravee sur son vieux filtre ; il doit
 * tomber sur l'article quand meme.
 *
 * ── ET UNE QUATRIEME, QUI EST UNE ABSENCE ──────────────────────────────────
 *
 * AUCUNE COMPATIBILITE. Ni Dongfeng, ni Caterpillar, ni JCB, ni John Deere,
 * ni McCormick n'existent au referentiel vehicules : il n'y a rien a quoi
 * rattacher quoi que ce soit. Le dernier test le verifie, parce que la lecon
 * du catalogue Toyota est qu'une compatibilite posee sans source verifiee
 * coute plus cher qu'une compatibilite absente — et que le jour ou les engins
 * entreront, quelqu'un sera tente d'en poser vite fait.
 */
class FiltresIndustrielsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le banc pose lui-meme ses categories, et c'est une lecon chere.
     *
     * La migration ne les cree PAS : elle serait alors jouee a chaque
     * `migrate`, donc dans chaque test, et les 89 categories arriveraient
     * avant que les tests ne creent les leurs. Mesure du jour ou je l'ai
     * essaye : 49 erreurs et 1 echec sur la suite, toutes sur la contrainte
     * d'unicite de `part_categories.slug`.
     *
     * La production les a (verifie le 24/10/2026 : 89 categories en ligne).
     * Ici, c'est au banc de les poser — comme il poserait n'importe quelle
     * autre donnee dont il a besoin.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PartCategoriesSeeder::class);

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\FiltresIndustrielsBoutiqueSeeder::class,
            '--force' => true,
        ])->assertSuccessful();
    }

    /** Les onze references, telles qu'elles sont marquees sur le filtre. */
    private const SKUS = [
        '26560145', '26560143', '4415122',
        'RE504836', 'RE59754',
        '462-1171', '435-6493',
        'LF4054', 'FF5018', 'FS1280',
        '320/07155',
    ];

    public function test_les_onze_references_entrent(): void
    {
        // La migration 2026_10_24_000002 les verse, et RefreshDatabase la
        // joue : aucun seeder n'est lance a la main ici. C'est voulu — ce que
        // ce test mesure, c'est ce qu'un deploiement produit.
        $this->assertSame(
            11,
            Part::whereIn('sku', self::SKUS)->count(),
            'Le deploiement ne pose pas les onze filtres. Verifier que la migration '
            .'garantit bien ses categories : sans elles, le seeder les saute toutes.'
        );
    }

    public function test_aucun_n_est_mis_en_vente(): void
    {
        $vendables = Part::whereIn('sku', self::SKUS)
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('selling_price', '>', 0))
            ->pluck('sku');

        $this->assertTrue(
            $vendables->isEmpty(),
            'Ces references sont en vente alors qu\'aucun prix n\'est connu : '
            .$vendables->implode(', ')
        );
    }

    public function test_le_stock_ne_promet_rien(): void
    {
        // Le commercant a ces filtres, mais ne les a pas comptes. Un nombre
        // invente serait une promesse faite a un client.
        $this->assertSame(
            0,
            Part::whereIn('sku', self::SKUS)->sum('stock_quantity'),
        );
    }

    public function test_fleetguard_est_un_equipementier_et_non_un_constructeur(): void
    {
        $fleetguard = Part::whereIn('sku', ['LF4054', 'FF5018', 'FS1280'])->pluck('type', 'sku');
        $constructeurs = Part::whereIn('sku', ['462-1171', 'RE504836', '26560145', '320/07155'])
            ->pluck('type', 'sku');

        foreach ($fleetguard as $sku => $type) {
            $this->assertSame('oes', $type->value ?? $type, "{$sku} : Fleetguard est la marque de filtration de Cummins, pas un constructeur de machine.");
        }

        foreach ($constructeurs as $sku => $type) {
            $this->assertSame('oem', $type->value ?? $type, "{$sku} : piece constructeur.");
        }
    }

    public function test_chaque_filtre_porte_son_fabricant_reel(): void
    {
        // Le catalogue Toyota vend des equivalents adaptables sous le
        // fabricant « Chine ». Ceux-ci sont d'origine : les confondre
        // tromperait l'acheteur sur la nature de ce qu'il achete.
        $attendu = [
            '26560145' => 'Perkins',
            'RE504836' => 'John Deere',
            '462-1171' => 'Caterpillar',
            'LF4054'   => 'Fleetguard',
            '320/07155' => 'JCB',
        ];

        foreach ($attendu as $sku => $fabricant) {
            $piece = Part::where('sku', $sku)->firstOrFail();

            $this->assertSame(
                $fabricant,
                Manufacturer::find($piece->manufacturer_id)?->name,
                "{$sku} doit porter son fabricant reel."
            );
        }
    }

    public function test_la_chaine_de_remplacement_jcb_est_enregistree(): void
    {
        $ancienne = OemNumber::where('number', '320/07155')->firstOrFail();
        $moyenne  = OemNumber::where('number', '320/07394')->firstOrFail();
        $recente  = OemNumber::where('number', '320/A7170')->firstOrFail();

        $this->assertTrue((bool) $ancienne->is_superseded);
        $this->assertSame($moyenne->id, $ancienne->superseded_by_id);

        $this->assertTrue((bool) $moyenne->is_superseded);
        $this->assertSame($recente->id, $moyenne->superseded_by_id);

        $this->assertFalse((bool) $recente->is_superseded, 'La derniere de la chaine ne remplace rien.');
    }

    public function test_les_trois_references_jcb_menent_au_meme_article(): void
    {
        // La promesse commerciale : un client arrive avec la reference gravee
        // sur son vieux filtre, et tombe sur l'article quand meme.
        $piece = Part::where('sku', '320/07155')->firstOrFail();
        $numeros = $piece->oemNumbers()->pluck('number')->sort()->values();

        $this->assertEqualsCanonicalizing(
            ['320/07155', '320/07394', '320/A7170'],
            $numeros->all(),
        );
    }

    public function test_le_numero_cummins_douteux_n_est_pas_enregistre(): void
    {
        // FF5018 porte le marquage Cummins 3931063, mais un manuel C8.3
        // associe 3931063 a FF5052. Tant que ce n'est pas tranche, vendre sur
        // cette correspondance serait vendre sur un doute.
        $ff5018 = Part::where('sku', 'FF5018')->firstOrFail();

        $this->assertNotContains(
            '3931063',
            $ff5018->oemNumbers()->pluck('number')->all(),
            'Le numero Cummins douteux est enregistre comme equivalent : '
            .'une recherche sur 3931063 renverrait FF5018 alors que la '
            .'documentation Cummins donne FF5052.'
        );
    }

    public function test_aucune_compatibilite_n_est_posee(): void
    {
        $avecFitments = Part::whereIn('sku', self::SKUS)
            ->whereHas('fitments')
            ->pluck('sku');

        $this->assertTrue(
            $avecFitments->isEmpty(),
            'Une compatibilite a ete posee alors qu\'aucun engin n\'existe au '
            .'referentiel : '.$avecFitments->implode(', ')
        );
    }

    public function test_rejouer_le_seeder_ne_cree_rien(): void
    {
        $avant = Part::whereIn('sku', self::SKUS)->get()->keyBy('sku')
            ->map(fn ($p) => $p->id)->all();

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\FiltresIndustrielsBoutiqueSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $apres = Part::whereIn('sku', self::SKUS)->get()->keyBy('sku')
            ->map(fn ($p) => $p->id)->all();

        $this->assertSame($avant, $apres, 'Une relance a recree des articles : les identifiants ont bouge.');
        $this->assertSame(11, count($apres));
    }

    public function test_une_relance_ne_rend_pas_un_prix_pose_a_la_main(): void
    {
        // LA PROMESSE QUI COMPTE LE PLUS POUR LE COMMERCANT. Il pose un prix
        // le soir depuis l'application ; le deploiement du lendemain ne doit
        // pas le remettre a zero ni depublier l'article.
        $piece = Part::where('sku', '462-1171')->firstOrFail();
        $piece->update(['selling_price' => 48000, 'stock_quantity' => 4, 'is_active' => true]);

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\FiltresIndustrielsBoutiqueSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $piece->refresh();

        $this->assertEquals(48000, $piece->selling_price, 'Le prix pose a la main a ete efface.');
        $this->assertSame(4, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active, 'L\'article a ete depublie par une relance.');
    }
}
