<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Immatricule » se declare ; il ne se devine pas depuis la plaque.
 *
 * ── CE QUE CE BANC CORRIGE ─────────────────────────────────────────────────
 *
 * `VehicleController::store()` DEDUISAIT le statut de la presence d'une
 * plaque, et ecrasait sans un mot ce que le client envoyait — alors que
 * `StoreVehicleRequest` acceptait le champ. Le champ etait donc recu, valide,
 * puis jete.
 *
 * Le cout n'est pas qu'un libelle faux. `syncTypeSpecificDetails()` ecrit le
 * bloc IMPORT ou le bloc IMMATRICULATION selon ce statut : un vendeur qui
 * declarait un vehicule immatricule sans avoir sa plaque sous la main voyait
 * donc le carnet d'immatriculation qu'il venait de remplir purement ignore, et
 * sa fiche basculer du cote import.
 *
 * Une plaque n'est pas la preuve de l'immatriculation, c'est une de ses
 * donnees. On a la plaque quand on l'a ; on sait que le vehicule est
 * immatricule bien avant.
 *
 * ── ET LA DEDUCTION RESTE, QUAND RIEN N'EST DIT ────────────────────────────
 *
 * Les deux derniers tests tiennent l'ancien comportement : sans statut
 * explicite, une plaque vaut toujours immatriculation. Le correctif AJOUTE
 * une porte, il n'en ferme aucune.
 */
class StatutImmatriculationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Brand, VehicleModel, EngineType} */
    private function referentiel(): array
    {
        $brand  = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);
        $model  = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Hilux', 'slug' => 'hilux', 'is_active' => true,
        ]);
        $engine = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);

        return [$brand, $model, $engine];
    }

    private function enAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
    }

    private function charge(array $extra = []): array
    {
        [$brand, $model, $engine] = $this->referentiel();

        return array_merge([
            'brand_id'           => $brand->id,
            'vehicle_model_id'   => $model->id,
            'engine_type_id'     => $engine->id,
            'manufacturing_year' => 2018,
            'availability'       => 'sale',
            'sale_price'         => 7500000,
        ], $extra);
    }

    public function test_un_vehicule_se_declare_immatricule_sans_sa_plaque(): void
    {
        $this->enAdmin();

        $this->postJson('/api/vehicles', $this->charge([
            'registration_status' => 'registered',
            // Pas de plate_number : c'est tout l'objet de ce test.
        ]))->assertCreated();

        $this->assertSame(
            RegistrationStatus::Registered,
            Vehicle::first()->registration_status,
            'Le statut envoye a ete ecrase par la deduction : un vehicule immatricule '
            .'dont on n\'a pas la plaque sous la main entre au catalogue du cote import.'
        );
    }

    public function test_le_carnet_d_immatriculation_est_alors_reellement_ecrit(): void
    {
        // La consequence qui fait que cela compte. `syncTypeSpecificDetails()`
        // choisit le bloc a ecrire SELON le statut : si celui-ci avait ete
        // ramene a « non immatricule », ce carnet serait parti a la poubelle
        // sans erreur, et le kilometrage avec lui.
        $this->enAdmin();

        $this->postJson('/api/vehicles', $this->charge([
            'registration_status' => 'registered',
            'registration'        => [
                'mileage_km'            => 142000,
                'previous_owners_count' => 2,
            ],
        ]))->assertCreated();

        $carnet = Vehicle::first()->registrationDetail;

        $this->assertNotNull($carnet, 'Le carnet d\'immatriculation a ete ignore.');
        $this->assertSame(142000, $carnet->mileage_km);
    }

    public function test_un_statut_explicite_prime_meme_contre_une_plaque(): void
    {
        // Le cas inverse, et il est reel : un vehicule importe qui porte
        // encore sa plaque etrangere n'est pas immatricule ici. L'explicite
        // doit gagner dans les deux sens, sinon ce n'est pas une declaration,
        // c'est une suggestion.
        $this->enAdmin();

        $this->postJson('/api/vehicles', $this->charge([
            'registration_status' => 'unregistered',
            'plate_number'        => '11 AA 1234',
        ]))->assertCreated();

        $this->assertSame(
            RegistrationStatus::Unregistered,
            Vehicle::first()->registration_status,
        );
    }

    public function test_sans_statut_explicite_une_plaque_vaut_immatriculation(): void
    {
        $this->enAdmin();

        $this->postJson('/api/vehicles', $this->charge([
            'plate_number' => '11 AA 1234',
        ]))->assertCreated();

        $this->assertSame(
            RegistrationStatus::Registered,
            Vehicle::first()->registration_status,
            'La deduction d\'avant a ete perdue : le correctif devait ajouter une porte, pas en fermer une.'
        );
    }

    public function test_sans_statut_ni_plaque_le_vehicule_reste_non_immatricule(): void
    {
        $this->enAdmin();

        $this->postJson('/api/vehicles', $this->charge())->assertCreated();

        $this->assertSame(
            RegistrationStatus::Unregistered,
            Vehicle::first()->registration_status,
        );
    }

    // ── Le filtre des resultats ────────────────────────────────────────────

    public function test_la_liste_se_filtre_sur_le_statut_d_immatriculation(): void
    {
        $this->enAdmin();

        $this->postJson('/api/vehicles', $this->charge(['registration_status' => 'registered']))
            ->assertCreated();

        // Un second vehicule, non immatricule, sur le meme referentiel.
        $premier = Vehicle::first();
        $this->postJson('/api/vehicles', [
            'brand_id'           => $premier->brand_id,
            'vehicle_model_id'   => $premier->vehicle_model_id,
            'engine_type_id'     => $premier->engine_type_id,
            'manufacturing_year' => 2012,
            'availability'       => 'sale',
            'sale_price'         => 4200000,
        ])->assertCreated();

        $this->assertSame(2, Vehicle::count());

        $immatricules = $this->getJson('/api/catalog/vehicles?registration_status=registered')
            ->assertOk()->json('data');

        $this->assertCount(1, $immatricules);
        $this->assertSame(2018, $immatricules[0]['identity']['year']);

        $importes = $this->getJson('/api/catalog/vehicles?registration_status=unregistered')
            ->assertOk()->json('data');

        $this->assertCount(1, $importes);
        $this->assertSame(2012, $importes[0]['identity']['year']);
    }

    public function test_la_liste_se_filtre_sur_le_dedouanement(): void
    {
        $this->enAdmin();

        // Un importe dedouane, un importe qui ne l'est pas.
        $this->postJson('/api/vehicles', $this->charge([
            'import' => ['customs_cleared' => true],
        ]))->assertCreated();

        $premier = Vehicle::first();
        $this->postJson('/api/vehicles', [
            'brand_id'           => $premier->brand_id,
            'vehicle_model_id'   => $premier->vehicle_model_id,
            'engine_type_id'     => $premier->engine_type_id,
            'manufacturing_year' => 2012,
            'availability'       => 'sale',
            'sale_price'         => 4200000,
            'import'             => ['customs_cleared' => false],
        ])->assertCreated();

        $dedouanes = $this->getJson('/api/catalog/vehicles?customs_cleared=1')
            ->assertOk()->json('data');

        $this->assertCount(1, $dedouanes);
        $this->assertSame(2018, $dedouanes[0]['identity']['year']);

        // Sans le parametre, les deux reviennent : l'API n'applique ce filtre
        // que lorsqu'il est vrai, il n'existe pas de cas « non dedouane ».
        $this->assertCount(2, $this->getJson('/api/catalog/vehicles')->assertOk()->json('data'));
    }
}
