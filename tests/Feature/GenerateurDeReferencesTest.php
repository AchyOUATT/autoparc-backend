<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\ReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le generateur de references, et le 500 permanent qu'il causait.
 *
 * Il lisait le maximum par `orderByDesc` sur une colonne TEXTE. Tant que
 * toutes les suites tiennent sur six chiffres, le tri de chaines coincide avec
 * l'ordre numerique et le defaut est invisible. Au-dela, il s'en ecarte et n'y
 * revient jamais : le maximum reste bloque sur `...-999999` puisque '9' > '1',
 * `next()` reproduit indefiniment `...-1000000`, et la violation de contrainte
 * remonte nue.
 *
 * Mesure du 25/10/2026 sur le vrai chemin HTTP, avant correction : premiere
 * creation 201, les suivantes 500, pour le reste de l'annee.
 *
 * ── CE QUE CE BANC TIENT, ET DANS QUEL ORDRE D'IMPORTANCE ─────────────────
 *
 * Le test qui compte est celui qui passe par l'API et cree TROIS vehicules
 * d'affilee apres une reference elevee. Verifier la valeur rendue par
 * `next()` montrerait que l'arithmetique est juste ; seul l'enchainement
 * montre que le parc tient — et c'est la deuxieme creation qui tombait, pas la
 * premiere.
 *
 * Les autres tiennent les deux gardes separement : le maximum numerique, et la
 * verification que la valeur calculee est libre. La seconde ne corrige pas le
 * defaut mesure, elle ferme sa classe — et sans test dedie, personne ne verrait
 * qu'on l'a retiree.
 */
class GenerateurDeReferencesTest extends TestCase
{
    use RefreshDatabase;

    private ReferenceGenerator $generateur;
    private array $identite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateur = app(ReferenceGenerator::class);

        $marque = Brand::create(['name' => 'Toyota']);
        $modele = VehicleModel::create(['brand_id' => $marque->id, 'name' => 'Corolla']);
        $moteur = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);

        $this->identite = [
            'brand_id' => $marque->id,
            'vehicle_model_id' => $modele->id,
            'engine_type_id' => $moteur->id,
            'manufacturing_year' => 2020,
            'condition' => 'used',
            'availability' => 'sale',
            'sale_price' => 4_500_000,
        ];
    }

    private function annee(): string
    {
        return now()->format('Y');
    }

    /** Pose une reference directement en base, sans passer par la validation. */
    private function poser(string $reference): void
    {
        Vehicle::create($this->identite + ['reference' => $reference, 'status' => 'in_stock']);
    }

    // ── LE TEST CENTRAL ──────────────────────────────────────────────────────

    public function test_le_parc_continue_de_creer_apres_une_reference_elevee(): void
    {
        $this->poser('VEH-'.$this->annee().'-999999');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        // TROIS, et non une : c'est la DEUXIEME qui tombait en 500, la
        // premiere passant parce que `str_pad` ne tronque pas.
        foreach ([1, 2, 3] as $rang) {
            $this->postJson('/api/vehicles', $this->identite)
                ->assertCreated("La creation {$rang} a echoue : la suite des references est cassee.");
        }

        $references = Vehicle::pluck('reference');

        $this->assertSame(
            $references->count(),
            $references->unique()->count(),
            'Deux vehicules portent la meme reference.'
        );
    }

    // ── Premiere garde : le maximum est numerique ────────────────────────────

    public function test_la_suite_franchit_le_million(): void
    {
        $this->poser('VEH-'.$this->annee().'-999999');

        $this->assertSame('VEH-'.$this->annee().'-1000000', $this->generateur->next('VEH'));

        $this->poser('VEH-'.$this->annee().'-1000000');

        // LE COEUR DU DEFAUT. L'ancienne version rendait ici 1000000 une
        // seconde fois, parce que '...999999' > '...1000000' en comparaison de
        // chaines.
        $this->assertSame('VEH-'.$this->annee().'-1000001', $this->generateur->next('VEH'));
    }

    public function test_la_premiere_reference_de_l_annee(): void
    {
        $this->assertSame('VEH-'.$this->annee().'-000001', $this->generateur->next('VEH'));
    }

    public function test_une_reference_hors_forme_n_affole_pas_la_suite(): void
    {
        // `(int) 'ABC'` vaut 0. Comptee, elle ferait repartir la suite de 1 et
        // heurterait une reference existante.
        $this->poser('VEH-'.$this->annee().'-000004');
        $this->poser('VEH-'.$this->annee().'-ABC');
        $this->poser('VEH-'.$this->annee().'-12-bis');

        $this->assertSame('VEH-'.$this->annee().'-000005', $this->generateur->next('VEH'));
    }

    public function test_une_autre_annee_ou_un_autre_prefixe_n_interfere_pas(): void
    {
        $this->poser('VEH-1999-999999');
        $this->poser('LOC-'.$this->annee().'-999999');

        $this->assertSame('VEH-'.$this->annee().'-000001', $this->generateur->next('VEH'));
    }

    public function test_la_valeur_rendue_est_toujours_libre(): void
    {
        // La propriete qui compte, et la raison pour laquelle une seconde garde
        // — une boucle qui verifiait que la place etait libre — a ete ECRITE
        // puis RETIREE : les tests par mutation ont montre qu'elle etait
        // inatteignable. Toute valeur de la forme `base+chiffres` entre dans le
        // maximum, donc `max+1` est strictement superieure a toutes ; et une
        // valeur d'une autre forme ne peut pas lui etre egale. Ce test tient la
        // propriete sans la boucle.
        $this->poser('VEH-'.$this->annee().'-000001');
        $this->poser('VEH-'.$this->annee().'-1000000');
        $this->poser('VEH-'.$this->annee().'-ABC');

        $rendue = $this->generateur->next('VEH');

        $this->assertSame(0, Vehicle::where('reference', $rendue)->count(),
            'Le generateur a rendu une reference deja prise.');
    }

    public function test_deux_appels_sans_ecriture_rendent_la_meme_valeur(): void
    {
        // `next()` LIT, il n'ecrit pas. Deux lectures sans creation entre les
        // deux doivent rendre la meme valeur, sinon chaque consultation
        // trouerait la numerotation.
        $this->poser('VEH-'.$this->annee().'-000001');

        $this->assertSame($this->generateur->next('VEH'), $this->generateur->next('VEH'));
    }

    // ── Les autres prefixes ──────────────────────────────────────────────────

    public function test_le_prefixe_client_ecrit_dans_la_colonne_code(): void
    {
        // `CLI` est le seul a ne pas utiliser `reference`. Un test, parce que
        // c'est une exception portee par un `if` et que rien d'autre ne la
        // garde.
        DB::table('customers')->insert([
            'code' => 'CLI-'.$this->annee().'-999999',
            'first_name' => 'Awa',
            'last_name' => 'Traore',
            'phone' => '+22670000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('CLI-'.$this->annee().'-1000000', $this->generateur->next('CLI'));
    }

    public function test_une_table_passee_explicitement_est_respectee(): void
    {
        // `ACC` n'est pas dans la table de correspondance et retomberait sur
        // `vehicles` ; son appelant passe donc `accessory_orders` a la main
        // (AccessoryOrderController:50).
        $this->poser('ACC-'.$this->annee().'-000009');

        // La reference posee vit dans `vehicles`, pas dans `accessory_orders` :
        // le generateur ne doit pas la voir.
        $this->assertSame(
            'ACC-'.$this->annee().'-000001',
            $this->generateur->next('ACC', 'accessory_orders')
        );
    }
}
