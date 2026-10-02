<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAccessoryRequest;
use App\Http\Requests\StorePartRequest;
use App\Models\Accessory;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Un null explicite sur une colonne a valeur par defaut n'est pas une panne.
 *
 * Devise, taux de TVA, quantite en stock, seuil d'alerte et activite sont
 * declares facultatifs par `StorePartRequest` et `StoreAccessoryRequest`, et
 * NOT NULL avec un `default()` par les migrations 2026_01_01_000300 et
 * 2026_01_01_000600. Les deux moities du contrat ne se rejoignaient que sur
 * un cas : la cle absente. Un client qui envoyait la cle a null voyait son
 * null traverser la validation intact, puis heurter la contrainte SQL — 500,
 * message de pilote, rien a corriger dans le formulaire. C'est la meme classe
 * de defaut que le SKU facultatif traite par 550ba8b, et SkuFacultatifTest en
 * donne la forme.
 *
 * Le cas etait atteignable sans client exotique : `TrimStrings` puis
 * `ConvertEmptyStringsToNull` normalisent deja toute chaine vide en null, si
 * bien qu'un champ « Devise » laisse vide arrive null des lors que le
 * formulaire envoie la cle. L'application Flutter y echappait seulement parce
 * que ses deux ecrans de saisie elaguent les cles vides a la main
 * (`if (_xCtrl.text.isNotEmpty) ...`) — une discipline de client, pas une
 * garantie de l'API.
 *
 * Reponse retenue : un null vaut une absence, pas une erreur. La requete
 * retire la cle avant validation, la colonne reprend son defaut. L'autre
 * lecture — `present`/`filled`, donc 422 — aurait reproche a l'utilisateur un
 * champ que personne n'avait rempli, et impose a chaque client le meme
 * elagage manuel.
 *
 * `is_active` entre dans le lot alors qu'il ne rendait pas 500 : sa regle
 * `boolean`, sans `nullable`, refusait deja le null par un 422 nomme. Le
 * laisser de cote aurait donne cinq colonnes de meme nature et deux
 * comportements — un meme formulaire entierement vide accepte sur quatre
 * champs, refuse sur le cinquieme. Il suit donc la meme regle.
 */
class ValeursParDefautCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les colonnes concernees et le defaut que la base doit appliquer.
     *
     * Les valeurs sont celles que rend le modele une fois ses casts passes :
     * `decimal:2` pour la TVA, `integer` pour le stock, `boolean` pour
     * l'activite.
     */
    private const DEFAUTS = [
        'currency'              => 'XOF',
        'vat_rate'              => '18.00',
        'stock_quantity'        => 0,
        'stock_alert_threshold' => 0,
        'is_active'             => true,
    ];

    /** Les deux requetes du catalogue, et la table que chacune alimente. */
    private const REQUETES = [
        'parts'       => StorePartRequest::class,
        'accessories' => StoreAccessoryRequest::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([\Database\Seeders\PartCategoriesSeeder::class]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    /** Le corps minimal que StorePartRequest exige. */
    private function piece(array $extra = []): array
    {
        return array_merge([
            'name'             => 'Piece essai',
            'part_category_id' => PartCategory::query()->value('id'),
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'selling_price'    => 15000,
        ], $extra);
    }

    /** Le corps minimal que StoreAccessoryRequest exige. */
    private function accessoire(array $extra = []): array
    {
        return array_merge([
            'name'          => 'Accessoire essai',
            'category'      => 'confort',
            'selling_price' => 9000,
        ], $extra);
    }

    /** Les cinq colonnes, une par cas de test. */
    public static function colonnes(): array
    {
        $cas = [];

        foreach (array_keys(self::DEFAUTS) as $colonne) {
            $cas[$colonne] = [$colonne];
        }

        return $cas;
    }

    // ── Le defaut d'origine : un null explicite rendait un 500 ───────────

    #[DataProvider('colonnes')]
    public function test_une_piece_est_acceptee_avec_une_colonne_a_null(string $colonne): void
    {
        $reponse = $this->postJson('/api/parts', $this->piece([$colonne => null]));

        $reponse->assertCreated();

        $piece = Part::findOrFail($reponse->json('data.id'));

        $this->assertEquals(
            self::DEFAUTS[$colonne],
            $piece->{$colonne},
            "Un null sur « {$colonne} » doit laisser la colonne reprendre son defaut.",
        );
    }

    #[DataProvider('colonnes')]
    public function test_un_accessoire_est_accepte_avec_une_colonne_a_null(string $colonne): void
    {
        $reponse = $this->postJson('/api/accessories', $this->accessoire([$colonne => null]));

        $reponse->assertCreated();

        $accessoire = Accessory::findOrFail($reponse->json('data.id'));

        $this->assertEquals(
            self::DEFAUTS[$colonne],
            $accessoire->{$colonne},
            "Un null sur « {$colonne} » doit laisser la colonne reprendre son defaut.",
        );
    }

    /**
     * La fiche entiere envoyee a null, comme la rendrait un modele serialise.
     *
     * C'est le scenario qui justifie le correctif : un client qui ne fait pas
     * le tri avant l'envoi. Les cinq colonnes tombent ensemble, et la reponse
     * doit decrire un article complet, pas une panne.
     */
    public function test_une_fiche_entierement_vide_reprend_tous_les_defauts(): void
    {
        $nuls = array_fill_keys(array_keys(self::DEFAUTS), null);

        $piece = Part::findOrFail(
            $this->postJson('/api/parts', $this->piece($nuls))->assertCreated()->json('data.id')
        );

        $accessoire = Accessory::findOrFail(
            $this->postJson('/api/accessories', $this->accessoire($nuls))->assertCreated()->json('data.id')
        );

        foreach (self::DEFAUTS as $colonne => $defaut) {
            $this->assertEquals($defaut, $piece->{$colonne}, "parts.{$colonne}");
            $this->assertEquals($defaut, $accessoire->{$colonne}, "accessories.{$colonne}");
        }
    }

    // ── Ce que le repli ne doit pas emporter ─────────────────────────────

    /**
     * Une valeur fournie reste une valeur fournie.
     *
     * Le filtre ne retire que les cles a null : s'il elaguait plus large, le
     * defaut de la colonne ecraserait silencieusement la saisie, et aucun des
     * tests ci-dessus ne le dirait.
     */
    public function test_les_valeurs_renseignees_traversent_intactes(): void
    {
        $reponse = $this->postJson('/api/parts', $this->piece([
            'currency'              => 'EUR',
            'vat_rate'              => 20,
            'stock_quantity'        => 7,
            'stock_alert_threshold' => 2,
            'is_active'             => false,
        ]));

        $reponse->assertCreated();

        $piece = Part::findOrFail($reponse->json('data.id'));

        $this->assertSame('EUR', $piece->currency);
        $this->assertEquals('20.00', $piece->vat_rate);
        $this->assertSame(7, $piece->stock_quantity);
        $this->assertEquals(2, $piece->stock_alert_threshold);
        $this->assertFalse($piece->is_active);
    }

    /**
     * Un zero n'est pas un null.
     *
     * Le piege d'un elagage ecrit avec `empty()` ou `isset()` : `0`, `0.00` et
     * `false` partiraient avec les nulls. Un stock remis a zero volontairement
     * reprendrait zero par defaut — invisible — mais un `is_active: false`
     * redeviendrait `true`, et l'article resterait en vente.
     */
    public function test_un_zero_et_un_faux_ne_sont_pas_effaces(): void
    {
        $reponse = $this->postJson('/api/parts', $this->piece([
            'vat_rate'       => 0,
            'stock_quantity' => 0,
            'is_active'      => false,
        ]));

        $reponse->assertCreated();

        $piece = Part::findOrFail($reponse->json('data.id'));

        $this->assertEquals('0.00', $piece->vat_rate);
        $this->assertSame(0, $piece->stock_quantity);
        $this->assertFalse($piece->is_active);
    }

    /**
     * Le repli ne dispense pas de valider ce qui est reellement envoye.
     *
     * Retirer la cle quand elle vaut null ne doit pas devenir une porte
     * ouverte : une devise de quatre lettres, un stock negatif, restent des
     * 422 designant le champ fautif.
     */
    public function test_une_valeur_invalide_reste_refusee(): void
    {
        $this->postJson('/api/parts', $this->piece(['currency' => 'EURO']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);

        $this->postJson('/api/parts', $this->piece(['stock_quantity' => -3]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stock_quantity']);

        $this->postJson('/api/accessories', $this->accessoire(['vat_rate' => 150]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vat_rate']);
    }

    // ── La modification : un null ne remet rien a zero ───────────────────

    /**
     * A la modification, « rien a dire » laisse la valeur en place.
     *
     * C'est la consequence a connaitre du choix retenu : la cle retiree,
     * `update()` ne touche pas la colonne. Un null ne ramene donc pas une
     * fiche deja renseignee a ses defauts — il ne la casse pas non plus, ce
     * qui etait tout l'objet du correctif.
     */
    public function test_un_null_a_la_modification_conserve_la_valeur_en_place(): void
    {
        $piece = Part::factory()->create([
            'currency'              => 'EUR',
            'vat_rate'              => 20,
            'stock_quantity'        => 7,
            'stock_alert_threshold' => 2,
            'is_active'             => false,
        ]);

        $this->putJson("/api/parts/{$piece->id}", $this->piece([
            'name'                  => 'Piece modifiee',
            'currency'              => null,
            'vat_rate'              => null,
            'stock_quantity'        => null,
            'stock_alert_threshold' => null,
            'is_active'             => null,
        ]))->assertOk();

        $piece->refresh();

        $this->assertSame('Piece modifiee', $piece->name);
        $this->assertSame('EUR', $piece->currency);
        $this->assertEquals('20.00', $piece->vat_rate);
        $this->assertSame(7, $piece->stock_quantity);
        $this->assertEquals(2, $piece->stock_alert_threshold);
        $this->assertFalse($piece->is_active);
    }

    public function test_un_null_a_la_modification_d_un_accessoire_conserve_la_valeur(): void
    {
        $accessoire = Accessory::factory()->create([
            'currency'       => 'EUR',
            'stock_quantity' => 7,
            'is_active'      => false,
        ]);

        $this->putJson("/api/accessories/{$accessoire->id}", $this->accessoire([
            'name'           => 'Accessoire modifie',
            'currency'       => null,
            'stock_quantity' => null,
            'is_active'      => null,
        ]))->assertOk();

        $accessoire->refresh();

        $this->assertSame('Accessoire modifie', $accessoire->name);
        $this->assertSame('EUR', $accessoire->currency);
        $this->assertSame(7, $accessoire->stock_quantity);
        $this->assertFalse($accessoire->is_active);
    }

    // ── Ce qui tient la liste a jour ─────────────────────────────────────

    /**
     * La liste des deux requetes doit epouser le schema.
     *
     * Les colonnes a elaguer sont nommees a la main dans chaque requete. Une
     * colonne NOT NULL dotee d'un defaut, declaree facultative dans les regles
     * mais oubliee de la liste, ramenerait exactement le 500 corrige ici — et
     * aucun test d'ecriture ne le dirait tant que personne n'envoie cette cle
     * a null. Ce test ferme l'ecart : il compare le schema aux regles, et
     * exige que tout ce qui n'est pas obligatoire soit elague.
     *
     * Les colonnes NOT NULL a defaut mais `required` (prix de vente, type,
     * etat) restent dehors : un null y est deja refuse par un 422 nomme, et
     * c'est bien la reponse qu'on attend d'un champ obligatoire.
     */
    public function test_aucune_colonne_a_valeur_par_defaut_n_est_oubliee(): void
    {
        foreach (self::REQUETES as $table => $requete) {
            $regles   = (new $requete)->rules();
            $elaguees = $this->colonnesElaguees($requete);

            foreach (Schema::getColumns($table) as $colonne) {
                $nom = $colonne['name'];

                if ($colonne['nullable'] || $colonne['default'] === null) {
                    continue;
                }

                if (! array_key_exists($nom, $regles) || in_array('required', (array) $regles[$nom], true)) {
                    continue;
                }

                $this->assertContains(
                    $nom,
                    $elaguees,
                    "{$table}.{$nom} est NOT NULL avec un defaut, et facultative dans les regles : "
                    ."un null explicite y rendrait un 500. Ajoutez-la a COLONNES_A_VALEUR_PAR_DEFAUT de "
                    .class_basename($requete).'.',
                );
            }
        }
    }

    /**
     * Les colonnes elaguees portent bien un defaut en base, et refusent null.
     *
     * L'inverse du test precedent : si une migration rendait l'une d'elles
     * nullable, ou lui retirait son defaut, l'elaguer n'aurait plus de sens —
     * au mieux inutile, au pire une valeur perdue en silence.
     */
    public function test_les_colonnes_elaguees_portent_bien_un_defaut_en_base(): void
    {
        foreach (self::REQUETES as $table => $requete) {
            $schema = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($this->colonnesElaguees($requete) as $nom) {
                $colonne = $schema->get($nom);

                $this->assertNotNull($colonne, "La table « {$table} » n'a plus de colonne « {$nom} ».");
                $this->assertFalse($colonne['nullable'], "{$table}.{$nom} est devenue nullable : l'elagage n'a plus lieu d'etre.");
                $this->assertNotNull($colonne['default'], "{$table}.{$nom} n'a plus de valeur par defaut : l'elaguer perdrait la saisie.");
            }
        }
    }

    /** @return list<string> */
    private function colonnesElaguees(string $requete): array
    {
        return (new ReflectionClass($requete))->getConstant('COLONNES_A_VALEUR_PAR_DEFAUT');
    }
}
