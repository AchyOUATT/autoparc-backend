<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ne proposer en filtre que les categories qui menent quelque part.
 *
 * L'arbre compte 89 categories, l'application en propose les 9 racines, et le
 * catalogue n'en occupe qu'une : « Moteur », par la feuille « Filtre a
 * huile ». Les huit autres pastilles rendaient zero resultat. Une barre de
 * filtres qui promet neuf entrees et n'en honore qu'une se lit comme une
 * panne, pas comme un catalogue jeune.
 *
 * Ce qui est verrouille ici tient en quatre points.
 *
 * L'elagage est un CHOIX de l'appelant, jamais le defaut : le meme appel sert
 * la barre de filtres et les menus ou l'on choisit une categorie. Elaguer pour
 * tout le monde interdirait de classer la premiere piece d'une categorie.
 *
 * Les ancetres d'une categorie peuplee survivent, sans quoi la seule branche
 * occupee serait injoignable depuis les racines.
 *
 * « Contenir une piece » veut dire exactement ce que la liste des pieces
 * entend par la — ni plus, ni moins. Une pastille qui compterait autrement
 * promettrait des articles que l'ecran ne montre pas, ou cacherait une
 * categorie dont il montre les articles.
 *
 * Et `has_own_parts` dit qui porte des pieces et qui n'est la que pour mener
 * ailleurs : c'est ce drapeau qui permet a l'application de descendre dans
 * l'arbre sans rendre des pieces injoignables.
 */
class CategoriesNonVidesTest extends TestCase
{
    use RefreshDatabase;

    /** Moteur → Filtres → Filtre a huile, l'arbre reel du catalogue. */
    private function brancheMoteur(): array
    {
        $racine  = PartCategory::create(['name' => 'Moteur', 'parent_id' => null]);
        $milieu  = PartCategory::create(['name' => 'Filtres', 'parent_id' => $racine->id]);
        $feuille = PartCategory::create(['name' => 'Filtre a huile', 'parent_id' => $milieu->id]);

        return [$racine, $milieu, $feuille];
    }

    private function piece(PartCategory $categorie, array $attributs = []): Part
    {
        static $n = 0;
        $n++;

        return Part::create(array_merge([
            'sku'              => 'PRT-'.$n,
            'name'             => 'Piece '.$n,
            'part_category_id' => $categorie->id,
            'manufacturer_id'  => Manufacturer::create(['name' => 'Equipementier '.$n])->id,
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'selling_price'    => 3000,
            'is_active'        => true,
        ], $attributs));
    }

    /** @return array<int, array<string, mixed>> */
    private function elaguees(): array
    {
        return $this->getJson('/api/catalog/part-categories?non_empty=1')
            ->assertOk()
            ->json();
    }

    // ── L'elagage est un choix, jamais le defaut ─────────────────────────────

    /**
     * Le test qui garde l'administrateur de l'impasse circulaire.
     *
     * Sans parametre, la reponse ne change pas : le menu « Categorie * » du
     * formulaire d'ajout continue de proposer les categories vides. Si elles
     * en disparaissaient, aucune ne recevrait jamais sa premiere piece — elle
     * n'apparaitrait pas tant qu'elle est vide, et resterait vide a jamais.
     */
    public function test_sans_parametre_l_arbre_complet_est_rendu(): void
    {
        [$racine, $milieu, $feuille] = $this->brancheMoteur();
        $vide = PartCategory::create(['name' => 'Climatisation', 'parent_id' => null]);
        $this->piece($feuille);

        $noms = collect($this->getJson('/api/catalog/part-categories')->assertOk()->json())
            ->pluck('name');

        $this->assertCount(4, $noms);
        $this->assertContains('Climatisation', $noms->all(), 'Une categorie vide doit rester choisissable a la saisie.');
        $this->assertSame($vide->id, $vide->fresh()->id);
        $this->assertContains($milieu->name, $noms->all());
        $this->assertContains($racine->name, $noms->all());
    }

    public function test_non_empty_retire_les_categories_sans_piece(): void
    {
        [, , $feuille] = $this->brancheMoteur();
        PartCategory::create(['name' => 'Climatisation', 'parent_id' => null]);
        PartCategory::create(['name' => 'Transmission', 'parent_id' => null]);
        $this->piece($feuille);

        $noms = collect($this->elaguees())->pluck('name')->all();

        $this->assertNotContains('Climatisation', $noms);
        $this->assertNotContains('Transmission', $noms);
    }

    // ── Les ancetres survivent ───────────────────────────────────────────────

    /**
     * Sans cette remontee, « Filtre a huile » serait la seule ligne rendue et
     * la barre de filtres — qui part des racines — n'aurait rien a afficher :
     * la seule branche peuplee du catalogue serait devenue injoignable.
     */
    public function test_les_ancetres_d_une_categorie_peuplee_sont_conserves(): void
    {
        [$racine, $milieu, $feuille] = $this->brancheMoteur();
        $this->piece($feuille);

        $ids = collect($this->elaguees())->pluck('id')->all();

        $this->assertContains($racine->id, $ids, 'La racine doit mener a la feuille peuplee.');
        $this->assertContains($milieu->id, $ids, 'Le niveau intermediaire aussi.');
        $this->assertContains($feuille->id, $ids);
        $this->assertCount(3, $ids);
    }

    public function test_une_branche_entierement_vide_disparait_en_entier(): void
    {
        [, , $feuille] = $this->brancheMoteur();
        $autreRacine = PartCategory::create(['name' => 'Freinage', 'parent_id' => null]);
        $autreMilieu = PartCategory::create(['name' => 'Disques', 'parent_id' => $autreRacine->id]);
        PartCategory::create(['name' => 'Plaquettes avant', 'parent_id' => $autreMilieu->id]);
        $this->piece($feuille);

        $ids = collect($this->elaguees())->pluck('id')->all();

        $this->assertNotContains($autreRacine->id, $ids);
        $this->assertNotContains($autreMilieu->id, $ids);
        $this->assertCount(3, $ids);
    }

    public function test_un_catalogue_vide_rend_une_liste_vide(): void
    {
        $this->brancheMoteur();

        $this->assertSame([], $this->elaguees());
    }

    // ── « Contenir une piece » veut dire ce que la liste en dit ──────────────

    /**
     * La definition doit coller a celle de la liste des pieces, qui n'applique
     * que `active()`. Sinon la pastille et l'ecran se contredisent.
     */
    public function test_une_piece_desactivee_ne_peuple_pas_sa_categorie(): void
    {
        [, , $feuille] = $this->brancheMoteur();
        $this->piece($feuille, ['is_active' => false]);

        $this->assertSame([], $this->elaguees(), 'Une piece hors catalogue ne doit pas allumer une pastille.');
    }

    public function test_une_piece_archivee_ne_peuple_pas_sa_categorie(): void
    {
        [, , $feuille] = $this->brancheMoteur();
        $this->piece($feuille)->delete();

        $this->assertSame([], $this->elaguees());
    }

    /**
     * Une rupture de stock ne vide pas une categorie.
     *
     * La liste des pieces affiche les references indisponibles, barrees
     * « Rupture de stock ». Exclure `is_available` ici ferait disparaitre une
     * pastille dont l'ecran montre pourtant les articles — et il n'y aurait
     * alors plus aucun chemin vers eux.
     */
    public function test_une_rupture_de_stock_ne_vide_pas_la_categorie(): void
    {
        [$racine, , $feuille] = $this->brancheMoteur();
        $this->piece($feuille, ['is_available' => false, 'stock_quantity' => 0]);

        $ids = collect($this->elaguees())->pluck('id')->all();

        $this->assertContains($racine->id, $ids);
        $this->assertContains($feuille->id, $ids);
    }

    // ── Qui porte des pieces, et qui ne fait que mener ailleurs ──────────────

    /**
     * Le drapeau sans lequel l'application rendrait des pieces injoignables.
     *
     * Elle descend dans l'arbre jusqu'au niveau qui separe vraiment : tant
     * qu'un seul noeud subsiste a un niveau, la pastille correspondante
     * couvrirait tout le catalogue et ne filtrerait rien. Mais descendre sous
     * une categorie qui porte SES PROPRES pieces les perdrait en route — aucune
     * pastille de niveau inferieur ne les couvre.
     */
    public function test_le_drapeau_distingue_le_porteur_du_simple_chemin(): void
    {
        [$racine, $milieu, $feuille] = $this->brancheMoteur();
        $this->piece($feuille);

        $parNom = collect($this->elaguees())->keyBy('name');

        $this->assertTrue($parNom['Filtre a huile']['has_own_parts']);
        $this->assertFalse($parNom['Filtres']['has_own_parts'], 'Ce niveau ne porte aucune piece : il ne fait que mener.');
        $this->assertFalse($parNom[$racine->name]['has_own_parts']);
        $this->assertSame($milieu->id, $parNom['Filtre a huile']['parent_id']);
    }

    public function test_une_categorie_intermediaire_peut_porter_ses_propres_pieces(): void
    {
        [, $milieu, $feuille] = $this->brancheMoteur();
        $this->piece($milieu);
        $this->piece($feuille);

        $parNom = collect($this->elaguees())->keyBy('name');

        $this->assertTrue($parNom['Filtres']['has_own_parts']);
        $this->assertTrue($parNom['Filtre a huile']['has_own_parts']);
    }

    /** La forme reste celle de l'arbre complet, au drapeau pres. */
    public function test_la_forme_des_lignes_ne_change_pas(): void
    {
        [, , $feuille] = $this->brancheMoteur();
        $this->piece($feuille);

        $ligne = collect($this->elaguees())->firstWhere('name', 'Filtre a huile');

        $this->assertSame(
            ['id', 'parent_id', 'name', 'has_own_parts'],
            array_keys($ligne),
            "L'application lit le meme modele qu'avant : aucun compteur ne doit voyager.",
        );
    }

    // ── Accessoires ──────────────────────────────────────────────────────────

    private function accessoire(string $categorie, array $attributs = []): Accessory
    {
        static $n = 0;
        $n++;

        return Accessory::create(array_merge([
            'sku'           => 'ACC-'.$n,
            'name'          => 'Accessoire '.$n,
            'category'      => $categorie,
            'selling_price' => 5000,
            'is_active'     => true,
            'is_available'  => true,
        ], $attributs));
    }

    /** @return array<int, string> */
    private function categoriesAccessoires(): array
    {
        return $this->getJson('/api/catalog/accessory-categories')->assertOk()->json();
    }

    public function test_seules_les_categories_d_accessoires_occupees_sont_rendues(): void
    {
        $this->accessoire('confort');
        $this->accessoire('securite');

        $this->assertSame(['confort', 'securite'], $this->categoriesAccessoires());
    }

    public function test_un_catalogue_d_accessoires_vide_ne_propose_aucune_categorie(): void
    {
        $this->assertSame([], $this->categoriesAccessoires());
    }

    /**
     * Ici, contrairement aux pieces, la rupture de stock compte : la liste
     * publique des accessoires exige `is_available`. Les deux routes doivent
     * dire la meme chose que l'ecran qu'elles alimentent, et ces deux ecrans ne
     * se comportent pas pareil.
     */
    public function test_un_accessoire_indisponible_n_allume_pas_sa_categorie(): void
    {
        $this->accessoire('confort', ['is_available' => false]);
        $this->accessoire('securite', ['is_active' => false]);

        $this->assertSame([], $this->categoriesAccessoires());
    }

    public function test_une_valeur_de_categorie_n_est_rendue_qu_une_fois(): void
    {
        $this->accessoire('confort');
        $this->accessoire('confort');
        $this->accessoire('confort');

        $this->assertSame(['confort'], $this->categoriesAccessoires());
    }
}
