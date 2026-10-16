<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\FuelFill;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Alerte de surconsommation — la contrepartie de la saisie a la pompe.
 *
 * Comme les rappels d'entretien, elle tourne sans personne pour la regarder.
 * Les deux fautes possibles sont silencieuses et opposees : une alerte qui ne
 * part jamais rend la saisie inutile, une alerte qui part tous les matins fait
 * desinstaller l'application. Aucune des deux ne leve d'erreur.
 *
 * Le declenchement lui-meme est teste dans SuiviConsommation ; ce qui se
 * verifie ici, c'est que la tache LIT ce verdict au lieu d'en recalculer un
 * autre, et que le dedoublonnage tient.
 */
class AlerteConsommationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel reseau : on ne teste pas Firebase.
        $this->instance(FcmService::class, Mockery::mock(FcmService::class)->shouldIgnoreMissing());
    }

    private function vehicule(): OwnedVehicle
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
        ]);
    }

    /**
     * Une chaine de pleins espaces de 500 km, un par litrage donne.
     *
     * Le premier passage n'ouvre qu'un intervalle, il n'en ferme aucun : il
     * faut donc un litrage de plus que d'intervalles voulus. Les nombres sont
     * poses a la main plutot que tires d'une factory — il n'en existe d'ailleurs
     * pas pour cette table — pour que le l/100 attendu se lise dans le test.
     *
     * @param  array<int, float>  $litrages
     */
    private function pleins(OwnedVehicle $vehicule, array $litrages): void
    {
        $compteur = 10_000;

        foreach ($litrages as $litres) {
            FuelFill::create([
                'owned_vehicle_id' => $vehicule->id,
                'is_full'          => true,
                'filled_at'        => now()->toDateString(),
                'mileage_km'       => $compteur,
                'litres'           => $litres,
            ]);

            $compteur += 500;
        }
    }

    public function test_un_vehicule_sans_passage_ne_declenche_rien(): void
    {
        $this->vehicule();

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }

    /** Six intervalles a 8,2 l/100 : la mediane vaut 8,2 et rien n'en sort. */
    public function test_une_consommation_stable_ne_declenche_rien(): void
    {
        $this->pleins($this->vehicule(), [41, 41, 41, 41, 41, 41, 41]);

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }

    /**
     * Quatre intervalles a 8,2 puis deux a 11,2 : la mediane de reference vaut
     * 8,2, et 11,2 passe les deux seuils (8,2 x 1,25 = 10,25 ; 8,2 + 1,5 = 9,7).
     *
     * Des taux non entiers, et c'est delibere : avec 8,0 et 11,0 les chiffres
     * ressortiraient du JSON en entiers — json_encode ecrit 8 pour 8.0 — et la
     * comparaison stricte du test suivant ne verrait plus rien.
     */
    public function test_deux_derniers_intervalles_en_hausse_declenchent_l_alerte(): void
    {
        $vehicule = $this->vehicule();
        $this->pleins($vehicule, [41, 41, 41, 41, 41, 56, 56]);

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $notification = AppNotification::first();

        $this->assertNotNull($notification, 'La hausse aurait du produire une alerte.');
        $this->assertSame('fuel_anomaly', $notification->type);
        $this->assertSame($vehicule->id, $notification->data['owned_vehicle_id']);

        // Le dedoublonnage compare data->owned_vehicle_id par whereJsonContains.
        // En chaine, la comparaison echouerait sans rien lever et l'alerte
        // repartirait chaque matin.
        $this->assertIsInt($notification->data['owned_vehicle_id']);
    }

    /**
     * Le verdict de la notification est celui de l'ecran, pas un second calcul :
     * les deux nombres du payload doivent etre ceux de suiviConsommation().
     */
    public function test_le_payload_reprend_les_chiffres_du_suivi(): void
    {
        $vehicule = $this->vehicule();
        $this->pleins($vehicule, [41, 41, 41, 41, 41, 56, 56]);

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $suivi = $vehicule->fresh()->suiviConsommation();
        $data  = AppNotification::first()->data;

        $this->assertSame($suivi['l_100km'], $data['l_100km']);
        $this->assertSame($suivi['baseline_l_100km'], $data['baseline_l_100km']);

        // Le chiffre du verdict voyage lui aussi : l'application en a besoin
        // pour redire dans l'ecran ce que la notification a dit dans la barre.
        $this->assertSame($suivi['alerte_l_100km'], $data['alerte_l_100km']);
    }

    /**
     * LE CORPS CITE LE CHIFFRE QUI A TRANCHE, pas celui de l'ecran.
     *
     * Serie 8 8 8 8 11 11 — 40 L puis 55 L par 500 km. La baseline vaut 8,0,
     * le verdict se prend sur les DEUX derniers intervalles, donc sur 11,0,
     * tandis que 'l_100km' porte les CINQ derniers et vaut 9,2.
     *
     * Tant que le corps citait 9,2, il annoncait sous le titre « Consommation
     * en hausse » un ecart de 1,2 l/100 qui ne franchit aucun des deux seuils
     * publies (8,0 x 1,25 = 10,0 et 8,0 + 1,5 = 9,5) : le proprietaire qui
     * refaisait le calcul sur les nombres de la notification ne retrouvait pas
     * son alerte. La notification part REELLEMENT ici, et le corps est relu en
     * base — c'est le seul endroit ou le texte emis se verifie.
     */
    public function test_le_corps_oppose_le_chiffre_du_verdict_a_la_baseline(): void
    {
        $vehicule = $this->vehicule();
        $this->pleins($vehicule, [40, 40, 40, 40, 40, 55, 55]);

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $suivi = $vehicule->fresh()->suiviConsommation();

        // assertEquals et non assertSame : 11,0 et 8,0 sont des floats ronds,
        // que le JSON du payload rendra en entiers. Ce sont les memes nombres.
        $this->assertEquals(11.0, $suivi['alerte_l_100km']);
        $this->assertEquals(8.0, $suivi['baseline_l_100km']);
        $this->assertEquals(9.2, $suivi['l_100km'], 'Le chiffre de l\'ecran, celui qu\'il ne faut PAS citer.');

        $notification = AppNotification::first();
        $this->assertNotNull($notification, 'La hausse aurait du produire une alerte.');

        $this->assertStringContainsString(
            '11,0 l/100 km, contre 8,0 l/100 km',
            $notification->body,
            'Le corps doit opposer le chiffre du verdict a la baseline.',
        );
        $this->assertStringNotContainsString(
            '9,2',
            $notification->body,
            'Le chiffre des cinq derniers intervalles n\'a rien a faire dans le corps.',
        );

        $this->assertEquals(11.0, $notification->data['alerte_l_100km']);
        $this->assertEquals(9.2, $notification->data['l_100km'], 'L\'ecran ouvert par le tap affiche celui-la.');
    }

    /**
     * Le point le plus fragile : la tache tourne tous les matins, et une derive
     * ne se corrige pas dans la nuit. Sans dedoublonnage, le meme vehicule
     * produirait la meme alerte jusqu'au passage a l'atelier.
     */
    public function test_relancee_le_lendemain_la_tache_ne_renvoie_rien(): void
    {
        $this->pleins($this->vehicule(), [41, 41, 41, 41, 41, 56, 56]);

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $this->travel(1)->days();

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $this->assertSame(1, AppNotification::count(), 'Deux passages ne doivent produire qu\'une alerte.');
    }

    public function test_le_mode_essai_n_enregistre_rien(): void
    {
        $this->pleins($this->vehicule(), [41, 41, 41, 41, 41, 56, 56]);

        $this->artisan('alerts:fuel-anomaly', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }

    /** Sans identifiant Firebase, aucun canal pour joindre le proprietaire. */
    public function test_un_proprietaire_sans_compte_firebase_est_ignore(): void
    {
        $vehicule = $this->vehicule();
        $this->pleins($vehicule, [41, 41, 41, 41, 41, 56, 56]);
        $vehicule->user->forceFill(['firebase_uid' => null])->save();

        $this->artisan('alerts:fuel-anomaly')->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }
}
