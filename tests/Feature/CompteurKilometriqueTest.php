<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le kilometrage, et ce qu'un chiffre absurde fait au rappel de vidange.
 *
 * `mileage_km` n'etait valide que `min:0`, a la creation comme a la mise a
 * jour — UpdateOwnedVehicleRequest herite des regles de la creation. Tout
 * entier positif passait donc, y compris un zero de trop.
 *
 * Ce n'est pas un defaut d'affichage. OwnedVehicle::kilometresAvantVidange()
 * calcule `intervalle - (kilometrage - kilometrage de la derniere vidange)`,
 * et c'est ce nombre que SendMaintenanceReminders relit pour decider s'il
 * previent le proprietaire. Un kilometrage faux ne provoque aucune erreur : il
 * deplace silencieusement l'echeance, dans un sens ou dans l'autre, et le
 * rappel part trop tot ou jamais.
 *
 * Le recul n'est pas refuse ici, et c'est deliberе : un compteur remplace
 * existe sur un parc importe, et une saisie trop haute doit pouvoir etre
 * corrigee a la baisse. Ce qui a ete supprime, c'est le recul SILENCIEUX — le
 * dialogue de saisie cote application nomme desormais les deux chiffres et
 * demande un second appui (test/compteur_recul_test.dart).
 */
class CompteurKilometriqueTest extends TestCase
{
    use RefreshDatabase;

    private function vehiculeDe(User $user, array $attributs = []): OwnedVehicle
    {
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id'  => $brand->id,
            'name'      => 'Corolla',
            'slug'      => 'corolla',
            'is_active' => true,
        ]);

        return OwnedVehicle::create(array_merge([
            'user_id'            => $user->id,
            'brand_id'           => $brand->id,
            'vehicle_model_id'   => $model->id,
            'manufacturing_year' => 2018,
            'mileage_km'         => 96000,
        ], $attributs));
    }

    public function test_un_kilometrage_hors_de_portee_est_refuse(): void
    {
        $user    = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehiculeDe($user);

        // 960 000 avec un zero de trop. Aucun vehicule routier n'atteint ce
        // chiffre, et rien en aval ne le remarquerait.
        $this->actingAsClient($user)
            ->putJson("/api/my/vehicles/{$vehicle->id}", ['mileage_km' => 9600000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mileage_km');

        $this->assertSame(
            96000,
            $vehicle->fresh()->mileage_km,
            'Une saisie refusee ne doit rien avoir ecrit.',
        );
    }

    public function test_la_meme_borne_vaut_a_la_creation(): void
    {
        $user  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $brand = Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Mazda3', 'slug' => 'mazda3', 'is_active' => true,
        ]);

        $this->actingAsClient($user)
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $brand->id,
                'vehicle_model_id'   => $model->id,
                'manufacturing_year' => 2015,
                'mileage_km'         => 9600000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mileage_km');
    }

    public function test_un_kilometrage_eleve_mais_plausible_passe(): void
    {
        // La borne doit laisser passer un vehicule reellement tres use : un
        // poids lourd ou un taxi de brousse depasse largement le million.
        $user    = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->putJson("/api/my/vehicles/{$vehicle->id}", ['mileage_km' => 1200000])
            ->assertOk();

        $this->assertSame(1200000, $vehicle->fresh()->mileage_km);
    }

    public function test_un_recul_reste_possible_car_un_compteur_se_remplace(): void
    {
        $user    = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->putJson("/api/my/vehicles/{$vehicle->id}", ['mileage_km' => 1200])
            ->assertOk();

        $this->assertSame(
            1200,
            $vehicle->fresh()->mileage_km,
            'Le serveur ne tranche pas : il ne peut pas distinguer une faute '
            .'de frappe d\'un compteur neuf. C\'est la saisie qui le demande.',
        );
    }

    public function test_un_compteur_en_recul_derange_le_rappel_de_vidange(): void
    {
        // Le test qui dit pourquoi les precedents existent. Il ne protege pas
        // un comportement souhaitable : il mesure le degat qu'un chiffre faux
        // cause en aval, pour que personne ne prenne ce champ pour un detail.
        $user    = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicle = $this->vehiculeDe($user, [
            'last_service_mileage_km' => 90000,
            'service_interval_km'     => 10000,
        ]);

        $this->assertSame(
            4000,
            $vehicle->kilometresAvantVidange(),
            'A 96 000 km, la vidange des 100 000 est dans 4 000 km.',
        );

        // 9 600 tape pour 96 000.
        $vehicle->update(['mileage_km' => 9600]);

        $this->assertSame(
            90400,
            $vehicle->fresh()->kilometresAvantVidange(),
            'Le rappel est repousse de 80 400 km : il ne partira jamais, et '
            .'rien ne le signale.',
        );
    }
}
