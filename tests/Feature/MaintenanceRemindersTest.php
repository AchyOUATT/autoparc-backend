<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Rappels d'entretien — le moteur de recurrence de l'application.
 *
 * Il tourne sans personne pour le regarder : une tache planifiee, a huit
 * heures, sur un serveur distant. Un rappel qui ne part pas ne provoque aucune
 * erreur visible, et un rappel qui part deux fois par jour pendant un mois se
 * remarque seulement quand le client desinstalle l'application. C'est
 * exactement le genre de code qui a besoin d'un test plutot que d'un coup
 * d'oeil.
 */
class MaintenanceRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel reseau : on ne teste pas Firebase, on teste la logique
        // de declenchement et de dedoublonnage.
        $this->instance(FcmService::class, Mockery::mock(FcmService::class)->shouldIgnoreMissing());
    }

    private function vehicleWith(array $attributes): OwnedVehicle
    {
        $user  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'client-'.uniqid()]);
        $brand = Brand::create(['name' => 'Toyota '.uniqid(), 'slug' => 'toyota-'.uniqid(), 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Corolla', 'slug' => 'corolla-'.uniqid(), 'is_active' => true,
        ]);

        return OwnedVehicle::create([
            'user_id'            => $user->id,
            'brand_id'           => $brand->id,
            'vehicle_model_id'   => $model->id,
            'manufacturing_year' => 2018,
        ] + $attributes);
    }

    public function test_une_echeance_proche_declenche_un_rappel(): void
    {
        $this->vehicleWith(['insurance_expiry' => now()->addDays(10)->toDateString()]);

        $this->artisan('reminders:maintenance')->assertSuccessful();

        $this->assertSame(1, AppNotification::count());
    }

    public function test_une_echeance_lointaine_ne_declenche_rien(): void
    {
        $this->vehicleWith(['insurance_expiry' => now()->addMonths(6)->toDateString()]);

        $this->artisan('reminders:maintenance')->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }

    /**
     * Le point le plus fragile : la tache tourne tous les matins. Sans
     * dedoublonnage, le proprietaire recevrait le meme avis trente fois avant
     * l'echeance.
     */
    public function test_le_meme_rappel_ne_part_pas_deux_fois(): void
    {
        $this->vehicleWith(['insurance_expiry' => now()->addDays(10)->toDateString()]);

        $this->artisan('reminders:maintenance')->assertSuccessful();
        $this->artisan('reminders:maintenance')->assertSuccessful();
        $this->artisan('reminders:maintenance')->assertSuccessful();

        $this->assertSame(1, AppNotification::count(), 'Trois passages ne doivent produire qu\'un rappel.');
    }

    /**
     * Le dedoublonnage compare `data->owned_vehicle_id`. Si cette valeur etait
     * enregistree en chaine plutot qu'en entier, la comparaison echouerait en
     * silence et le rappel repartirait chaque jour.
     */
    public function test_l_identifiant_du_vehicule_est_stocke_en_entier(): void
    {
        $vehicule = $this->vehicleWith(['insurance_expiry' => now()->addDays(10)->toDateString()]);

        $this->artisan('reminders:maintenance')->assertSuccessful();

        $data = AppNotification::first()->data;

        $this->assertSame($vehicule->id, $data['owned_vehicle_id']);
        $this->assertIsInt($data['owned_vehicle_id']);
    }

    public function test_deux_echeances_distinctes_donnent_deux_rappels(): void
    {
        $this->vehicleWith([
            'insurance_expiry'            => now()->addDays(10)->toDateString(),
            'technical_inspection_expiry' => now()->addDays(12)->toDateString(),
        ]);

        $this->artisan('reminders:maintenance')->assertSuccessful();

        $this->assertSame(2, AppNotification::count());
    }

    public function test_le_mode_essai_n_enregistre_rien(): void
    {
        $this->vehicleWith(['insurance_expiry' => now()->addDays(10)->toDateString()]);

        $this->artisan('reminders:maintenance', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }

    /** Sans identifiant Firebase, aucun canal pour joindre le proprietaire. */
    public function test_un_proprietaire_sans_compte_firebase_est_ignore(): void
    {
        $vehicule = $this->vehicleWith(['insurance_expiry' => now()->addDays(10)->toDateString()]);
        $vehicule->user->forceFill(['firebase_uid' => null])->save();

        $this->artisan('reminders:maintenance')->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }
}
