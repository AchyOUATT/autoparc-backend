<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Le SKU est facultatif partout, sauf la ou il compte : en base.
 *
 * La reference interne etait declaree optionnelle a chaque etage de la chaine.
 * `StorePartRequest` l'annonce `nullable`, et ne verifie son unicite que parmi
 * les lignes qui en portent une (`whereNotNull`) — une precaution qui n'a de
 * sens que si la colonne peut etre vide. L'ecran de saisie mobile l'affiche
 * « SKU / Reference interne » avec la mention « optionnel », sans controle, et
 * retire carrement la cle de la charge utile quand le champ est laisse vide.
 * Les modeles Dart la lisent en `String?` et chaque affichage la teste avant
 * de la rendre.
 *
 * Un seul endroit ne l'avait pas entendu : `parts.sku` et `accessories.sku`
 * etaient NOT NULL. Enregistrer une piece sans reference ne rendait donc pas
 * un 422 explicable mais un 500 — une violation de contrainte remontee brute
 * depuis le pilote SQL, cote mobile un « erreur serveur » sans rien a corriger
 * dans le formulaire.
 *
 * Rendre la colonne nullable ne suffisait pas. Le SKU sert aussi de cle stable
 * au rattrapage du catalogue : `FitmentPlanner::modelesPour()` l'attend en
 * `string` (null y aurait ete une erreur fatale) et `attacherOem()` derive la
 * reference constructeur de `md5($piece->sku)` — toutes les pieces sans SKU
 * auraient partage la meme empreinte, donc le meme numero OEM. Le repli par
 * identifiant, verifie plus bas, garde ces deux proprietes.
 */
class SkuFacultatifTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    /** Le corps minimal que StorePartRequest exige, sans reference interne. */
    private function piece(array $extra = []): array
    {
        return array_merge([
            'name'             => 'Piece sans reference',
            'part_category_id' => PartCategory::query()->value('id'),
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'selling_price'    => 15000,
        ], $extra);
    }

    /** Le corps minimal que StoreAccessoryRequest exige, sans reference interne. */
    private function accessoire(array $extra = []): array
    {
        return array_merge([
            'name'          => 'Accessoire sans reference',
            'category'      => 'confort',
            'selling_price' => 9000,
        ], $extra);
    }

    private function modele(string $nom): VehicleModel
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'marque-test'],
            ['name' => 'Marque Test', 'is_active' => true],
        );

        return VehicleModel::create([
            'brand_id'         => $marque->id,
            'name'             => $nom,
            'production_start' => 2010,
            'production_end'   => 2018,
            'is_active'        => true,
        ]);
    }

    // ── Ce que l'API doit accepter ───────────────────────────────────────

    /** Le defaut d'origine : la cle absente rendait un 500. */
    public function test_une_piece_sans_sku_est_enregistree(): void
    {
        $reponse = $this->postJson('/api/parts', $this->piece());

        $reponse->assertCreated();
        $this->assertNull($reponse->json('data.sku'));
        $this->assertNull(Part::findOrFail($reponse->json('data.id'))->sku);
    }

    /**
     * Deux pieces sans reference doivent cohabiter.
     *
     * L'index unique reste en place sur la colonne : c'est la norme SQL qui
     * autorise plusieurs NULL sous une contrainte d'unicite, et c'est de cela
     * que depend tout l'interet d'un SKU facultatif. Une seule piece sans
     * reference toleree n'aurait servi a rien.
     */
    public function test_deux_pieces_sans_sku_cohabitent(): void
    {
        $this->postJson('/api/parts', $this->piece(['name' => 'Premiere']))->assertCreated();
        $this->postJson('/api/parts', $this->piece(['name' => 'Seconde']))->assertCreated();

        $this->assertSame(2, Part::whereNull('sku')->count());
    }

    /**
     * Un champ vide vaut une absence, pas une chaine vide.
     *
     * L'application mobile retire la cle quand le champ est vide, mais rien
     * n'oblige un autre client a faire de meme. Enregistrer `''` occuperait
     * une valeur dans l'index unique : la deuxieme piece sans reference serait
     * refusee pour « SKU deja utilise », sur un champ que personne n'a rempli.
     *
     * Ce n'est pas la requete qui s'en charge mais la pile globale de Laravel
     * — `TrimStrings` puis `ConvertEmptyStringsToNull`. Ce test tient donc une
     * garantie qui vient d'ailleurs que du code de ce depot : la premiere
     * version du correctif normalisait le champ dans `prepareForValidation()`,
     * et aucune mutation de ces lignes ne faisait echouer quoi que ce soit —
     * elles ne servaient a rien. Retirer l'un de ces deux intergiciels de
     * `bootstrap/app.php` ramenerait le defaut, et ce test le dirait.
     */
    public function test_un_sku_vide_vaut_une_absence_de_sku(): void
    {
        $premiere = $this->postJson('/api/parts', $this->piece(['sku' => '']));
        $premiere->assertCreated();

        $this->assertNull(Part::findOrFail($premiere->json('data.id'))->sku);

        $this->postJson('/api/parts', $this->piece(['sku' => '   ']))->assertCreated();

        $this->assertSame(2, Part::whereNull('sku')->count());
    }

    /**
     * Un SKU renseigne reste unique, et le refus vient de la validation.
     *
     * C'est l'autre moitie du contrat : si le doublon remontait de l'index
     * SQL, l'application recevrait un 500 au lieu d'un message designant le
     * champ fautif.
     */
    public function test_un_sku_deja_pris_est_refuse_par_la_validation(): void
    {
        $this->postJson('/api/parts', $this->piece(['sku' => 'PRT-UNIQUE']))->assertCreated();

        $this->postJson('/api/parts', $this->piece(['sku' => 'PRT-UNIQUE']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    // ── Les accessoires : meme defaut, autre controleur ──────────────────

    public function test_un_accessoire_sans_sku_est_enregistre(): void
    {
        $reponse = $this->postJson('/api/accessories', $this->accessoire());

        $reponse->assertCreated();
        $this->assertNull($reponse->json('data.sku'));
        $this->assertNull(Accessory::findOrFail($reponse->json('data.id'))->sku);
    }

    public function test_deux_accessoires_sans_sku_cohabitent(): void
    {
        $this->postJson('/api/accessories', $this->accessoire(['name' => 'Premier']))->assertCreated();
        $this->postJson('/api/accessories', $this->accessoire(['name' => 'Second']))->assertCreated();

        $this->assertSame(2, Accessory::whereNull('sku')->count());
    }

    public function test_un_sku_vide_d_accessoire_vaut_une_absence(): void
    {
        $reponse = $this->postJson('/api/accessories', $this->accessoire(['sku' => '']));
        $reponse->assertCreated();

        $this->assertNull(Accessory::findOrFail($reponse->json('data.id'))->sku);

        $this->postJson('/api/accessories', $this->accessoire(['sku' => '  ']))->assertCreated();

        $this->assertSame(2, Accessory::whereNull('sku')->count());
    }

    public function test_un_sku_d_accessoire_deja_pris_est_refuse_par_la_validation(): void
    {
        $this->postJson('/api/accessories', $this->accessoire(['sku' => 'ACC-UNIQUE']))->assertCreated();

        $this->postJson('/api/accessories', $this->accessoire(['sku' => 'ACC-UNIQUE']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    /**
     * La contrainte d'unicite doit survivre a la migration.
     *
     * Sur SQLite, rendre une colonne nullable reconstruit la table entiere :
     * elle est recreee, les donnees recopiees, les index rejoues. Perdre
     * l'index en route ne ferait echouer aucun test d'ecriture — la validation
     * suffit a refuser les doublons en conditions normales — mais la base
     * n'aurait plus aucun garde-fou sous deux ecritures simultanees.
     */
    public function test_l_unicite_du_sku_reste_garantie_en_base(): void
    {
        foreach (['parts', 'accessories'] as $table) {
            $index = collect(Schema::getIndexes($table))
                ->first(fn (array $i) => $i['columns'] === ['sku']);

            $this->assertNotNull($index, "La table « {$table} » n'a plus d'index sur sku.");
            $this->assertTrue($index['unique'], "L'index sur {$table}.sku n'est plus unique.");
        }
    }

    // ── Ce qui derive du SKU ─────────────────────────────────────────────

    /**
     * Le rattrapage du catalogue doit traiter un article sans reference.
     *
     * `modelesPour()` recevait `$piece->sku` sur un parametre `string` : null
     * y arretait la commande net, en pleine boucle, apres avoir deja ecrit une
     * partie du catalogue. Et `md5(null)` donnait la meme empreinte a toutes
     * les pieces sans SKU : `firstOrCreate` leur rendait a toutes le meme
     * numero OEM, quand `attacherOem()` promet une reference qui « ne peut pas
     * entrer en collision ».
     */
    public function test_le_rattrapage_traite_les_articles_sans_sku(): void
    {
        $this->modele('Alpha');
        $this->modele('Beta');

        $pieces = Part::factory()->count(2)->create(['sku' => null]);
        Accessory::factory()->create(['sku' => null]);

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        foreach ($pieces as $piece) {
            $this->assertGreaterThan(
                0,
                $piece->fitments()->count(),
                'Une piece sans SKU doit etre rattachee comme les autres.',
            );
        }

        $this->assertGreaterThan(0, Accessory::whereNull('sku')->first()->fitments()->count());

        $references = $pieces
            ->map(fn (Part $piece) => $piece->oemNumbers()->value('normalized_number'))
            ->all();

        $this->assertCount(2, array_filter($references), 'Chaque piece doit porter une reference OEM.');
        $this->assertCount(
            2,
            array_unique($references),
            'Deux pieces sans SKU ne peuvent pas partager le meme numero OEM.',
        );
    }

    /**
     * Un message de stock doit nommer l'article meme sans reference.
     *
     * « Stock insuffisant pour la piece  : 1 disponible(s) » laisse un trou la
     * ou la personne attend de savoir de quel article on lui parle.
     */
    public function test_un_refus_de_stock_nomme_la_piece_sans_sku(): void
    {
        $piece = Part::factory()->create([
            'sku'            => null,
            'name'           => 'Filtre essai',
            'stock_quantity' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stock insuffisant pour la piece Filtre essai : 1 disponible(s), 3 demande(s).');

        app(StockService::class)->decrease($piece, 3);
    }

    public function test_un_refus_de_stock_nomme_l_accessoire_sans_sku(): void
    {
        $accessoire = Accessory::factory()->create([
            'sku'            => null,
            'name'           => 'Tapis essai',
            'stock_quantity' => 1,
        ]);

        $this->postJson("/api/accessories/{$accessoire->id}/stock", [
            'operation' => 'decrease',
            'quantity'  => 3,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuffisant pour Tapis essai : 1 disponible(s), 3 demande(s).');
    }
}
