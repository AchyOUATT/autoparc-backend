<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\ClientFcmToken;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use ReflectionProperty;
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

    public function test_an_english_job_keeps_canonical_reminders_and_sends_each_device_its_own_language(): void
    {
        $vehicle = $this->vehicleWith([
            'nickname' => 'La voiture de René',
            'insurance_expiry' => now()->subDays(2)->toDateString(),
            'mileage_km' => 65001,
            'last_service_mileage_km' => 60000,
            'service_interval_km' => 5000,
        ]);
        ClientFcmToken::upsertToken($vehicle->user->firebase_uid, 'french-device', 'fr');
        ClientFcmToken::upsertToken($vehicle->user->firebase_uid, 'english-device', 'en');
        $deliveries = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->times(4)->andReturnUsing(function ($message, $tokens) use (&$deliveries) {
            $deliveries[] = ['message' => $message->jsonSerialize(), 'tokens' => $tokens];

            return MulticastSendReport::withItems([]);
        });
        $service = new FcmService();
        (new ReflectionProperty(FcmService::class, 'messaging'))->setValue($service, $messaging);
        $this->instance(FcmService::class, $service);

        app()->setLocale('en');
        $this->artisan('reminders:maintenance')->assertSuccessful();
        $this->assertSame('en', app()->getLocale(), 'The command must restore the caller’s locale.');
        $stored = AppNotification::orderBy('id')->get();
        $this->assertSame(['Assurance depassee', 'Vidange depassee'], $stored->pluck('title')->all());
        $this->assertSame('La voiture de René : Vidange — Depassee de 1 km.', $stored[1]->getRawOriginal('body'));

        $titles = [];
        foreach ($deliveries as $delivery) {
            $token = $delivery['tokens'][0];
            $titles[$token][] = $delivery['message']['notification']['title'];
            $this->assertStringContainsString('La voiture de René', $delivery['message']['notification']['body']);
            if ($token === 'english-device') {
                $this->assertStringNotContainsString('depassee', $delivery['message']['notification']['title']);
                $this->assertStringNotContainsString('Depassee', $delivery['message']['notification']['body']);
            }
        }
        $this->assertSame(['Assurance depassee', 'Vidange depassee'], $titles['french-device']);
        $this->assertSame(['Insurance overdue', 'Oil change overdue'], $titles['english-device']);
    }
}
