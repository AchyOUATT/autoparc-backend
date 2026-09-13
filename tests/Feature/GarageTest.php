<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Mon garage » de bout en bout : middleware Firebase, liaison de modele,
 * policy, ressource.
 *
 * Cette chaine a deja lache en silence. `apiResource('vehicles')` nommait le
 * parametre {vehicle} alors que le controleur attend $ownedVehicle : Laravel
 * n'injectait aucun modele, en construisait un vide dont user_id valait null,
 * et la policy refusait tout. Resultat : 403 sur show, update et destroy, quel
 * que soit le proprietaire — un ecran entier hors service, invisible tant que
 * personne n'ouvrait le detail d'un vehicule.
 *
 * D'ou le choix de traverser le vrai middleware plutot que de le desactiver :
 * le defaut vivait precisement dans l'assemblage, pas dans une methode isolee.
 */
class GarageTest extends TestCase
{
    use RefreshDatabase;

    private function refs(): array
    {
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name'     => 'Corolla',
            'slug'     => 'corolla',
            'is_active' => true,
        ]);

        return [$brand, $model];
    }

    private function vehicleFor(User $user): OwnedVehicle
    {
        [$brand, $model] = $this->refs();

        return OwnedVehicle::create([
            'user_id'            => $user->id,
            'brand_id'           => $brand->id,
            'vehicle_model_id'   => $model->id,
            'manufacturing_year' => 2018,
            'nickname'           => 'La voiture de Awa',
        ]);
    }

    // ── Acces ────────────────────────────────────────────────────────────────

    public function test_le_garage_exige_un_jeton(): void
    {
        $this->getJson('/api/my/vehicles')->assertUnauthorized();
    }

    public function test_un_client_inconnu_est_provisionne_a_la_premiere_visite(): void
    {
        $this->assertSame(0, User::where('firebase_uid', 'nouveau-client')->count());

        $this->actingAsClient(uid: 'nouveau-client')
            ->getJson('/api/my/vehicles')
            ->assertOk();

        $created = User::where('firebase_uid', 'nouveau-client')->first();

        $this->assertNotNull($created, 'Le middleware doit creer le compte local.');
        $this->assertSame('client', $created->role->value, 'Un compte mobile ne doit jamais naitre staff.');
    }

    // ── Lecture ──────────────────────────────────────────────────────────────

    public function test_le_proprietaire_ouvre_la_fiche_de_son_vehicule(): void
    {
        $user    = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehicleFor($user);

        $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('data.nickname', 'La voiture de Awa');
    }

    public function test_un_autre_client_ne_voit_pas_le_vehicule(): void
    {
        $proprio = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehicleFor($proprio);

        $this->actingAsClient(uid: 'quelqu-un-dautre')
            ->getJson("/api/my/vehicles/{$vehicle->id}")
            ->assertForbidden();
    }

    public function test_la_liste_ne_montre_que_ses_propres_vehicules(): void
    {
        $proprio = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $this->vehicleFor($proprio);

        $this->actingAsClient(uid: 'quelqu-un-dautre')
            ->getJson('/api/my/vehicles')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Ecriture ─────────────────────────────────────────────────────────────

    public function test_un_client_ajoute_un_vehicule(): void
    {
        [$brand, $model] = $this->refs();

        $this->actingAsClient(uid: 'proprio')
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $brand->id,
                'vehicle_model_id'   => $model->id,
                'manufacturing_year' => 2020,
                'nickname'           => 'Ma Corolla',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nickname', 'Ma Corolla');

        $this->assertDatabaseHas('owned_vehicles', ['nickname' => 'Ma Corolla']);
    }

    /**
     * L'enregistrement des echeances echouait, la aussi faute de liaison de
     * modele : le formulaire renvoyait « Reessayer » sans rien dire de plus.
     */
    public function test_le_proprietaire_enregistre_ses_echeances(): void
    {
        $user    = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehicleFor($user);

        $this->actingAsClient($user)
            ->putJson("/api/my/vehicles/{$vehicle->id}", [
                'brand_id'                    => $vehicle->brand_id,
                'vehicle_model_id'            => $vehicle->vehicle_model_id,
                'manufacturing_year'          => $vehicle->manufacturing_year,
                'technical_inspection_expiry' => '2027-03-01',
                'insurance_expiry'            => '2027-01-15',
                'last_service_mileage_km'     => 90000,
                'service_interval_km'         => 10000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('owned_vehicles', [
            'id'                      => $vehicle->id,
            'last_service_mileage_km' => 90000,
            'service_interval_km'     => 10000,
        ]);
    }

    public function test_un_autre_client_ne_peut_pas_modifier_le_vehicule(): void
    {
        $proprio = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehicleFor($proprio);

        $this->actingAsClient(uid: 'quelqu-un-dautre')
            ->putJson("/api/my/vehicles/{$vehicle->id}", ['nickname' => 'Vole'])
            ->assertForbidden();

        $this->assertDatabaseHas('owned_vehicles', ['id' => $vehicle->id, 'nickname' => 'La voiture de Awa']);
    }

    public function test_un_autre_client_ne_peut_pas_supprimer_le_vehicule(): void
    {
        $proprio = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehicleFor($proprio);

        $this->actingAsClient(uid: 'quelqu-un-dautre')
            ->deleteJson("/api/my/vehicles/{$vehicle->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('owned_vehicles', ['id' => $vehicle->id]);
    }
}
