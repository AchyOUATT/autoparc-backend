<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le vidage du catalogue de demonstration.
 *
 * Une commande qui supprime se teste autrement qu'une commande qui ecrit : ce
 * qui compte n'est pas ce qu'elle enleve, c'est ce qu'elle laisse. Trois
 * regles sont donc verrouillees ici.
 *
 * *La suppression est definitive.* Les tables visees sont en suppression
 * douce. Une ligne restee en base avec son `deleted_at` n'apparait plus a
 * l'ecran mais occupe toujours son SKU dans l'index unique : la premiere
 * vraie piece portant cette reference serait refusee pour « deja utilisee »,
 * sur une ligne invisible. Un test ecrit donc une piece avec le SKU d'une
 * piece supprimee et exige qu'elle passe.
 *
 * *Un historique de commandes arrete tout.* Les lignes de commande sont en
 * RESTRICT : la suppression echouerait de toute facon, mais sur une erreur SQL
 * illisible. La commande doit refuser avant de toucher a quoi que ce soit, et
 * un test verifie qu'apres son refus le catalogue est intact.
 *
 * *Ce qui n'est pas du catalogue reste.* Les comptes du personnel et les
 * vehicules de garage des utilisateurs ne sont pas des donnees de
 * demonstration. Les supprimer fermerait le back-office et effacerait les
 * vehicules des gens.
 */
class PurgeCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // VehicleFactory tire sa motorisation et sa couleur du referentiel et
        // lit leur identifiant sans precaution : sans ces seeders, elle echoue
        // sur un « Attempt to read property id on null » qui ne dit rien.
        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
            \Database\Seeders\EngineTypesSeeder::class,
            \Database\Seeders\DrivetrainsSeeder::class,
            \Database\Seeders\ColorsSeeder::class,
        ]);
    }

    private function catalogue(): void
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'marque-test'],
            ['name' => 'Marque Test', 'is_active' => true],
        );

        $modele = \App\Models\VehicleModel::create([
            'brand_id'  => $marque->id,
            'name'      => 'Modele Test',
            'slug'      => 'modele-test',
            'is_active' => true,
        ]);

        Vehicle::factory()->count(3)->create();
        Part::factory()->count(4)->create();
        Accessory::factory()->count(2)->create();

        // Une compatibilite, pour verifier que la cascade suit.
        Part::query()->first()->fitments()->create(['vehicle_model_id' => $modele->id]);

        Customer::factory()->count(5)->create();
    }

    public function test_le_catalogue_est_vide(): void
    {
        $this->catalogue();

        $this->artisan('catalog:purge --force')->assertSuccessful();

        $this->assertSame(0, DB::table('vehicles')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('accessories')->count());
        $this->assertSame(0, DB::table('customers')->count());
    }

    /**
     * Le defaut qu'une suppression douce aurait laisse passer.
     *
     * Rien ne l'aurait signale : l'ecran montre un catalogue vide, et c'est au
     * moment de saisir la premiere vraie piece que la reference est refusee.
     */
    public function test_un_sku_supprime_redevient_disponible(): void
    {
        $piece = Part::factory()->create(['sku' => 'REF-REELLE-1']);

        $this->artisan('catalog:purge --force')->assertSuccessful();

        $this->assertDatabaseMissing('parts', ['id' => $piece->id]);

        // Et la preuve par l'usage : le SKU se reprend.
        $reprise = Part::factory()->create(['sku' => 'REF-REELLE-1']);

        $this->assertNotSame($piece->id, $reprise->id);
    }

    /** Les cascades suivent : aucune compatibilite orpheline ne survit. */
    public function test_les_compatibilites_partent_avec_les_pieces(): void
    {
        $this->catalogue();

        $this->assertGreaterThan(0, PartFitment::count());

        $this->artisan('catalog:purge --force')->assertSuccessful();

        $this->assertSame(0, PartFitment::count());
        $this->assertSame(0, DB::table('accessory_fitments')->count());
        $this->assertSame(0, DB::table('oem_numbers')->count());
    }

    public function test_le_personnel_et_les_garages_sont_conserves(): void
    {
        $this->catalogue();

        $utilisateur = User::factory()->create(['role' => 'admin']);
        $garage      = OwnedVehicle::factory()->create(['user_id' => $utilisateur->id]);

        $this->artisan('catalog:purge --force')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $utilisateur->id]);
        $this->assertDatabaseHas('owned_vehicles', ['id' => $garage->id]);
    }

    /** Le referentiel reste : les vraies saisies s'y rattachent. */
    public function test_le_referentiel_est_conserve(): void
    {
        $this->catalogue();

        $this->artisan('catalog:purge --force')->assertSuccessful();

        foreach (['brands', 'vehicle_models', 'part_categories', 'manufacturers'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }
    }

    /**
     * Une base qui porte de vraies commandes n'est pas une base a vider.
     */
    public function test_un_historique_de_commandes_arrete_tout(): void
    {
        $this->catalogue();

        $piece = Part::query()->first();

        $commande = DB::table('part_orders')->insertGetId([
            'reference'  => 'CMD-1',
            'status'     => 'draft',
            'ordered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('part_order_items')->insert([
            'part_order_id' => $commande,
            'part_id'       => $piece->id,
            'designation'   => $piece->name,
            'quantity'      => 1,
            'unit_price'    => 1000,
            'line_total'    => 1000,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $avant = DB::table('parts')->count();

        $this->artisan('catalog:purge --force')->assertFailed();

        $this->assertSame($avant, DB::table('parts')->count(),
            'Un refus doit laisser le catalogue intact.');
        $this->assertDatabaseHas('part_order_items', ['part_id' => $piece->id]);
    }

    public function test_le_mode_essai_ne_supprime_rien(): void
    {
        $this->catalogue();

        $avant = DB::table('parts')->count();

        $this->artisan('catalog:purge --dry-run')->assertSuccessful();

        $this->assertSame($avant, DB::table('parts')->count());
    }

    public function test_un_refus_de_confirmation_ne_supprime_rien(): void
    {
        $this->catalogue();

        $avant = DB::table('vehicles')->count();

        $this->artisan('catalog:purge')
            ->expectsConfirmation('Supprimer definitivement ces lignes ?', 'no')
            ->assertFailed();

        $this->assertSame($avant, DB::table('vehicles')->count());
    }

    /** Relancer sur une base deja vide n'est pas une erreur. */
    public function test_relancer_sur_un_catalogue_vide_reussit(): void
    {
        $this->artisan('catalog:purge --force')->assertSuccessful();
        $this->artisan('catalog:purge --force')->assertSuccessful();
    }
}
