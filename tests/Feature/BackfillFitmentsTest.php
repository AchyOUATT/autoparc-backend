<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La commande qui repare un catalogue sans compatibilites.
 *
 * Le defaut qu'elle corrige ne produisait aucune erreur : 200 pieces, 161
 * modeles, zero ligne dans `part_fitments`, et une liste de pieces compatibles
 * systematiquement vide. C'est le genre de panne qu'aucun test d'API ne voit,
 * puisque la reponse est un 200 avec un tableau vide — parfaitement valide.
 *
 * Ces tests tiennent donc le resultat metier : apres la commande, un vehicule
 * de garage doit voir des pieces. Et ils tiennent la relance, parce que la
 * commande est faite pour tourner sur la base en ligne, plusieurs fois.
 */
class BackfillFitmentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seule la nomenclature des pieces est seedee, pas les modeles.
     *
     * PartFactory a besoin de categories pour ranger ce qu'elle cree, mais les
     * modeles de vehicule sont poses test par test : l'un d'eux verifie
     * justement ce que fait la commande quand il n'y en a aucun.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);
    }

    private function modele(string $nom, ?int $debut = 2010, ?int $fin = null): VehicleModel
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'marque-test'],
            ['name' => 'Marque Test', 'is_active' => true],
        );

        return VehicleModel::create([
            'brand_id'         => $marque->id,
            'name'             => $nom,
            'production_start' => $debut,
            'production_end'   => $fin,
            'is_active'        => true,
        ]);
    }

    public function test_la_commande_rattache_les_pieces_orphelines(): void
    {
        $this->modele('Alpha');
        $this->modele('Beta');
        Part::factory()->count(5)->create();

        $this->assertSame(0, PartFitment::count(), 'Point de depart : aucune compatibilite.');

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        $this->assertGreaterThan(0, PartFitment::count());
        $this->assertSame(
            0,
            Part::doesntHave('fitments')->count(),
            'Aucune piece ne doit rester sans compatibilite.',
        );
    }

    /**
     * Le vrai critere : ce que voit la personne dans son garage. Compter des
     * lignes en base ne dit rien si la requete de compatibilite ne les trouve
     * pas — c'etait precisement le cas avant, sauf qu'il n'y avait pas de
     * lignes du tout.
     */
    public function test_un_vehicule_de_garage_voit_enfin_des_pieces(): void
    {
        $modele = $this->modele('Corolla', 2013, 2019);
        // Un deuxieme modele, pour que la selection ait un choix a faire.
        $this->modele('Hilux', 2015);

        Part::factory()->count(20)->create();

        $client  = User::factory()->create();
        $voiture = OwnedVehicle::create([
            'user_id'            => $client->id,
            'brand_id'           => $modele->brand_id,
            'vehicle_model_id'   => $modele->id,
            'manufacturing_year' => 2016,
        ]);

        $avant = app(CompatibilityService::class)->partsForOwnedVehicle($voiture)->count();
        $this->assertSame(0, $avant, 'Avant la commande, la liste est vide.');

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        $apres = app(CompatibilityService::class)->partsForOwnedVehicle($voiture->fresh())->count();
        $this->assertGreaterThan(0, $apres);
    }

    public function test_relancer_la_commande_ne_duplique_rien(): void
    {
        $this->modele('Alpha');
        $this->modele('Beta');
        Part::factory()->count(5)->create();
        Accessory::factory()->count(3)->create();

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();
        $premier = PartFitment::count();

        // Sans --fresh : les pieces deja traitees sont ignorees.
        $this->artisan('catalog:backfill-fitments')->assertSuccessful();
        $this->assertSame($premier, PartFitment::count());

        // Avec --fresh : tout est recalcule, et le resultat est identique
        // puisque la selection est deterministe.
        $this->artisan('catalog:backfill-fitments --fresh')->assertSuccessful();
        $this->assertSame($premier, PartFitment::count());
    }

    public function test_le_mode_essai_n_ecrit_rien(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(3)->create();

        $this->artisan('catalog:backfill-fitments --dry-run')->assertSuccessful();

        $this->assertSame(0, PartFitment::count());
    }

    /**
     * Un catalogue sans modele de vehicule n'est pas une situation normale :
     * ecrire zero ligne en annoncant un succes laisserait croire que le
     * rattrapage a eu lieu.
     */
    public function test_sans_modele_la_commande_echoue_au_lieu_de_ne_rien_dire(): void
    {
        Part::factory()->count(2)->create();

        $this->artisan('catalog:backfill-fitments')->assertFailed();
    }

    public function test_la_commande_refuse_un_perimetre_inconnu(): void
    {
        $this->modele('Alpha');

        $this->artisan('catalog:backfill-fitments --only=pieces')->assertFailed();
    }

    public function test_le_perimetre_limite_ne_touche_que_ce_qu_on_lui_demande(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(3)->create();
        Accessory::factory()->count(3)->create();

        $this->artisan('catalog:backfill-fitments --only=accessories')->assertSuccessful();

        $this->assertSame(0, PartFitment::count());
        $this->assertGreaterThan(0, \App\Models\AccessoryFitment::count());
    }

    /**
     * La recherche par numero OEM etait morte pour la meme raison : la table
     * etait vide. Une piece rattachee doit desormais porter sa reference.
     */
    public function test_chaque_piece_recoit_une_reference_constructeur(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(4)->create();

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        foreach (Part::with('oemNumbers')->get() as $piece) {
            $this->assertCount(1, $piece->oemNumbers, "SKU {$piece->sku}");
        }
    }
}
