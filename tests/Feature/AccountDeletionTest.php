<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\ClientFcmToken;
use App\Models\CustomerNeed;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\FirebaseAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeFirebaseAuthService;
use Tests\TestCase;

/**
 * Suppression du compte client.
 *
 * Google Play l'exige de toute application qui permet d'en creer un. Au-dela de
 * l'exigence, c'est une promesse : ce qui est annonce comme efface doit l'etre,
 * partout — y compris dans les trois tables qui designent le client par son uid
 * Firebase sans cle etrangere, et que rien n'emporterait avec le compte.
 *
 * Le test miroir compte autant : une suppression qui deborde sur le garage du
 * voisin serait pire que pas de suppression du tout.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const UID    = 'firebase-uid-test';
    private const AUTRUI = 'uid-de-quelqu-un-d-autre';

    private function vehiculePour(User $proprietaire): OwnedVehicle
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'toyota'],
            ['name' => 'Toyota', 'is_active' => true],
        );
        $modele = VehicleModel::firstOrCreate(
            ['brand_id' => $marque->id, 'slug' => 'corolla'],
            ['name' => 'Corolla', 'is_active' => true],
        );

        return OwnedVehicle::create([
            'user_id'            => $proprietaire->id,
            'brand_id'           => $marque->id,
            'vehicle_model_id'   => $modele->id,
            'manufacturing_year' => 2018,
            'nickname'           => 'La voiture',
        ]);
    }

    private function garnir(User $proprietaire, string $uid): void
    {
        $this->vehiculePour($proprietaire);

        CustomerNeed::create([
            'firebase_uid' => $uid,
            'type'         => 'part',
            'description'  => 'Plaquettes avant',
            'status'       => 'pending',
        ]);

        ClientFcmToken::create([
            'firebase_uid' => $uid,
            'fcm_token'    => "jeton-{$uid}",
        ]);

        AppNotification::create([
            'recipient_type' => 'client',
            'recipient_id'   => $uid,
            'type'           => 'reminder',
            'title'          => 'Visite technique',
            'body'           => 'Elle expire bientot.',
        ]);
    }

    public function test_la_suppression_emporte_toutes_les_donnees_du_client(): void
    {
        $client = User::factory()->create(['firebase_uid' => self::UID]);
        $this->garnir($client, self::UID);

        $this->actingAsClient($client)
            ->deleteJson('/api/my/account')
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseMissing('customer_needs', ['firebase_uid' => self::UID]);
        $this->assertDatabaseMissing('client_fcm_tokens', ['firebase_uid' => self::UID]);
        $this->assertDatabaseMissing('app_notifications', ['recipient_id' => self::UID]);

        // Les vehicules sont en suppression douce : `assertDatabaseMissing` ne
        // suffirait pas, une ligne marquee supprimee y echapperait. On compte
        // donc sur l'ensemble, corbeille comprise.
        $this->assertSame(
            0,
            OwnedVehicle::withTrashed()->where('user_id', $client->id)->count(),
            'Un vehicule en corbeille reste un vehicule conserve.',
        );
    }

    public function test_la_suppression_ne_touche_pas_au_garage_du_voisin(): void
    {
        $client = User::factory()->create(['firebase_uid' => self::UID]);
        $voisin = User::factory()->create(['firebase_uid' => self::AUTRUI]);

        $this->garnir($client, self::UID);
        $this->garnir($voisin, self::AUTRUI);

        $this->actingAsClient($client)
            ->deleteJson('/api/my/account')
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $voisin->id]);
        $this->assertDatabaseHas('customer_needs', ['firebase_uid' => self::AUTRUI]);
        $this->assertDatabaseHas('client_fcm_tokens', ['firebase_uid' => self::AUTRUI]);
        $this->assertDatabaseHas('app_notifications', ['recipient_id' => self::AUTRUI]);
        $this->assertSame(1, OwnedVehicle::withTrashed()->where('user_id', $voisin->id)->count());
    }

    public function test_l_identite_firebase_est_supprimee_elle_aussi(): void
    {
        $client = User::factory()->create(['firebase_uid' => self::UID]);

        $this->actingAsClient($client)
            ->deleteJson('/api/my/account')
            ->assertOk();

        $double = $this->app->make(FirebaseAuthService::class);

        $this->assertSame(
            self::UID,
            $double->uidSupprime,
            'Effacer nos donnees sans effacer l\'identite laisserait un compte '
            .'capable de se reconnecter.',
        );
    }

    public function test_un_vehicule_deja_mis_a_la_corbeille_est_efface_aussi(): void
    {
        $client   = User::factory()->create(['firebase_uid' => self::UID]);
        $vehicule = $this->vehiculePour($client);
        $vehicule->delete();

        $this->actingAsClient($client)
            ->deleteJson('/api/my/account')
            ->assertOk();

        $this->assertSame(0, OwnedVehicle::withTrashed()->count());
    }

    public function test_sans_jeton_la_route_refuse(): void
    {
        $this->deleteJson('/api/my/account')->assertUnauthorized();
    }

    /**
     * L'ordre choisi — donnees locales d'abord, identite Firebase ensuite — est
     * un compromis assume. Si l'appel a Google echoue, les donnees sont deja
     * parties : c'est ce qu'on voulait, et la personne garde un compte vide
     * avec lequel se reconnecter. Dans l'autre sens, elle n'aurait plus aucun
     * moyen de redemander l'effacement.
     */
    public function test_une_panne_chez_firebase_n_annule_pas_la_suppression(): void
    {
        $client = User::factory()->create(['firebase_uid' => self::UID]);
        $this->garnir($client, self::UID);

        $this->actingAsClient($client);
        $this->app->instance(
            FirebaseAuthService::class,
            new class(['uid' => self::UID, 'email' => 'x@y.z', 'name' => 'Client'])
                extends FakeFirebaseAuthService {
                public function deleteUser(string $uid): void
                {
                    throw new \RuntimeException('Google injoignable');
                }
            },
        );

        $this->deleteJson('/api/my/account')->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseMissing('customer_needs', ['firebase_uid' => self::UID]);
    }
}
