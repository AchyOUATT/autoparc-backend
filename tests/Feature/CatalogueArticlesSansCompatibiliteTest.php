<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Accessory;
use App\Models\Brand;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le parametre `without_fitments` n'affiche que les articles sans
 * aucune compatibilite, et seulement pour le personnel.
 */
class CatalogueArticlesSansCompatibiliteTest extends TestCase
{
    use RefreshDatabase;

    private Part $pieceAvecFitment;

    private Part $pieceSansFitment;

    private Part $pieceSeulementOem;

    private Accessory $accessoireAvecFitment;

    private Accessory $accessoireSansFitment;

    private VehicleModel $modele;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);

        $marque = Brand::create(['name' => 'Toyota', 'slug' => 'toyota-essai', 'is_active' => true]);
        $this->modele = VehicleModel::create([
            'brand_id' => $marque->id,
            'name' => 'Corolla',
            'slug' => 'corolla-essai',
            'is_active' => true,
        ]);

        $this->pieceAvecFitment = Part::factory()->create(['name' => 'Filtre avec application', 'is_active' => true]);
        $this->pieceAvecFitment->fitments()->create([
            'vehicle_model_id' => $this->modele->id,
            'engine_code' => '1ZZ-FE',
            'source' => FitmentSource::Declared,
        ]);

        $this->pieceSansFitment = Part::factory()->create(['name' => 'Filtre sans application', 'is_active' => true]);

        $this->pieceSeulementOem = Part::factory()->create(['name' => 'Filtre via OEM', 'is_active' => true]);
        $oem = OemNumber::create(['number' => '90915-YZZN1', 'label' => 'Filtre via OEM']);
        $this->pieceSeulementOem->oemNumbers()->attach($oem->id, ['is_primary' => true]);
        $oem->fitments()->create(['vehicle_model_id' => $this->modele->id]);

        $this->accessoireAvecFitment = Accessory::factory()->create([
            'name' => 'Tapis avec application',
            'is_active' => true,
            'is_available' => true,
            'stock_quantity' => 2,
        ]);
        $this->accessoireAvecFitment->fitments()->create([
            'vehicle_model_id' => $this->modele->id,
            'source' => FitmentSource::Declared,
        ]);

        $this->accessoireSansFitment = Accessory::factory()->create([
            'name' => 'Tapis sans application',
            'is_active' => true,
            'is_available' => true,
            'stock_quantity' => 2,
        ]);
    }

    /** @return list<int> */
    private function identifiants(string $uri): array
    {
        return collect($this->getJson($uri)->assertOk()->json('data'))
            ->pluck('id')
            ->all();
    }

    public function test_le_catalogue_public_ignore_without_fitments(): void
    {
        foreach (['/api/catalog/parts', '/api/catalog/parts?without_fitments=1'] as $uri) {
            $ids = $this->identifiants($uri);
            $this->assertContains($this->pieceAvecFitment->id, $ids, $uri);
            $this->assertContains($this->pieceSansFitment->id, $ids, $uri);
            $this->assertContains($this->pieceSeulementOem->id, $ids, $uri);
        }

        foreach (['/api/catalog/accessories', '/api/catalog/accessories?without_fitments=1'] as $uri) {
            $ids = $this->identifiants($uri);
            $this->assertContains($this->accessoireAvecFitment->id, $ids, $uri);
            $this->assertContains($this->accessoireSansFitment->id, $ids, $uri);
        }
    }

    public function test_un_client_ne_peut_pas_utiliser_le_filtre_sur_la_route_du_personnel(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'is_active' => true]));

        $this->getJson('/api/parts?without_fitments=1')->assertForbidden();
        $this->getJson('/api/accessories?without_fitments=1')->assertForbidden();
    }

    public function test_le_personnel_voit_tout_par_defaut(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $pieces = $this->identifiants('/api/parts');
        $this->assertContains($this->pieceAvecFitment->id, $pieces);
        $this->assertContains($this->pieceSansFitment->id, $pieces);
        $this->assertContains($this->pieceSeulementOem->id, $pieces);

        $accessoires = $this->identifiants('/api/accessories');
        $this->assertContains($this->accessoireAvecFitment->id, $accessoires);
        $this->assertContains($this->accessoireSansFitment->id, $accessoires);
    }

    public function test_le_personnel_n_affiche_que_les_articles_sans_compatibilite(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $pieces = $this->identifiants('/api/parts?without_fitments=1');
        $this->assertContains($this->pieceSansFitment->id, $pieces);
        $this->assertNotContains($this->pieceAvecFitment->id, $pieces);
        $this->assertNotContains(
            $this->pieceSeulementOem->id,
            $pieces,
            'Une piece sans part_fitments mais avec une application OEM n\'est pas sans compatibilite.',
        );

        $accessoires = $this->identifiants('/api/accessories?without_fitments=1');
        $this->assertContains($this->accessoireSansFitment->id, $accessoires);
        $this->assertNotContains($this->accessoireAvecFitment->id, $accessoires);
    }

    public function test_un_commercial_et_un_manager_peuvent_utiliser_le_filtre(): void
    {
        foreach (['sales', 'manager'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));

            $pieces = $this->identifiants('/api/parts?without_fitments=1');
            $this->assertContains($this->pieceSansFitment->id, $pieces, $role);
            $this->assertNotContains($this->pieceAvecFitment->id, $pieces, $role);
        }
    }

    public function test_without_fitments_se_combine_avec_inactive_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $inactifSans = Part::factory()->create(['name' => 'Filtre inactif sans application', 'is_active' => false]);
        $inactifAvec = Part::factory()->create(['name' => 'Filtre inactif avec application', 'is_active' => false]);
        $inactifAvec->fitments()->create([
            'vehicle_model_id' => $this->modele->id,
            'source' => FitmentSource::Declared,
        ]);

        $pieces = $this->identifiants('/api/parts?inactive_only=1&without_fitments=1');
        $this->assertContains($inactifSans->id, $pieces);
        $this->assertNotContains($inactifAvec->id, $pieces);
        $this->assertNotContains($this->pieceSansFitment->id, $pieces);
    }
}
