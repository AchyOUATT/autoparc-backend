<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mettre en vente un vehicule, de bout en bout.
 *
 * Ce chemin n'etait couvert par AUCUN test reussi. StaffPermissionsHttpTest
 * poste un corps vide sur /api/vehicles et verifie les 401, 403 et 422 : il
 * etablit qui a le droit d'entrer, jamais que la creation aboutit. Une erreur
 * a l'interieur de VehicleController::store — la transaction, la sequence de
 * reference, le chargement des treize relations, la serialisation — passait
 * donc la suite entiere sans etre vue.
 *
 * Le defaut remonte du terrain : « Erreur du serveur », sans reference. Or
 * cette route pose bien un request_id (verifie en production : un POST sans
 * jeton rend {"message":"Unauthenticated.","request_id":"..."}), et le
 * gestionnaire de bootstrap/app.php l'ajoute a toute reponse JSON d'exception.
 * Une erreur rendue par Laravel porterait donc une reference. Son absence
 * disait que la reponse ne venait pas de Laravel — reste a savoir si le code
 * lui-meme tient.
 */
class MiseEnVenteVehiculeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Brand, VehicleModel, EngineType} */
    private function referentiel(): array
    {
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id'  => $brand->id,
            'name'      => 'Corolla',
            'slug'      => 'corolla',
            'is_active' => true,
        ]);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);

        return [$brand, $model, $engine];
    }

    private function enAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** La charge utile minimale que le formulaire de l'application envoie. */
    private function charge(Brand $brand, VehicleModel $model, EngineType $engine): array
    {
        return [
            'brand_id'           => $brand->id,
            'vehicle_model_id'   => $model->id,
            'engine_type_id'     => $engine->id,
            'manufacturing_year' => 2018,
            'availability'       => 'sale',
            'sale_price'         => 7500000,
        ];
    }

    public function test_un_admin_met_un_vehicule_en_vente(): void
    {
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $reponse = $this->postJson('/api/vehicles', $this->charge($brand, $model, $engine));

        // On affiche la cause avant d'echouer : un 500 nu ne dit rien, et
        // c'est precisement le symptome rapporte depuis le terrain.
        if ($reponse->status() >= 500) {
            $this->fail(
                "POST /api/vehicles a rendu {$reponse->status()}.\n"
                .substr($reponse->getContent(), 0, 2000)
            );
        }

        $reponse->assertCreated();

        $this->assertSame(1, Vehicle::count(), 'Le vehicule doit exister en base.');
    }

    public function test_la_reponse_porte_de_quoi_afficher_la_fiche(): void
    {
        // Le defaut deja vu sur les accessoires : la creation reussit, mais la
        // reponse ne porte pas ce que le modele Dart lit en type strict, et
        // l'ecran annonce un echec sur un enregistrement qui a eu lieu.
        // Vehicle.fromJson lit identity.brand et identity.model en `as String`
        // non nullable : les deux doivent etre presents et non nuls.
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $reponse = $this->postJson('/api/vehicles', $this->charge($brand, $model, $engine));

        if ($reponse->status() >= 500) {
            $this->fail("POST /api/vehicles a rendu {$reponse->status()}.\n"
                .substr($reponse->getContent(), 0, 2000));
        }

        $reponse->assertCreated()
            ->assertJsonPath('data.identity.brand', 'Toyota')
            ->assertJsonPath('data.identity.model', 'Corolla');

        $donnees = $reponse->json('data');

        $this->assertIsString($donnees['reference'] ?? null, 'reference est lue en `as String`.');
        $this->assertIsString($donnees['designation'] ?? null, 'designation est lue en `as String`.');
    }

    public function test_un_equipement_inconnu_ne_doit_pas_faire_tomber_le_serveur(): void
    {
        // La regle de validation est `'features' => ['array']`, sans aucune
        // contrainte sur les elements : n'importe quel entier la franchit, et
        // c'est sync() qui bute ensuite sur la cle etrangere.
        //
        // Le cas n'est pas theorique. L'application garde les referentiels en
        // cache pendant 24 h, et une purge du catalogue a deja eu lieu
        // (2026_10_02_000003_purge_catalogue_demonstration). Un telephone qui
        // n'a pas rafraichi son cache envoie donc des identifiants
        // d'equipements qui n'existent plus.
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $charge = $this->charge($brand, $model, $engine);
        $charge['features'] = [999999];

        $reponse = $this->postJson('/api/vehicles', $charge);

        $this->assertLessThan(
            500,
            $reponse->status(),
            "Un identifiant d'equipement inconnu doit etre refuse proprement, "
            ."pas faire tomber la requete.\n".substr($reponse->getContent(), 0, 1000),
        );
    }

    public function test_le_lieu_choisi_dans_le_formulaire_est_bien_enregistre(): void
    {
        // location_id est `fillable` sur le modele Vehicle, l'application
        // l'envoie des qu'un lieu est selectionne, et VehicleResource le rend.
        // Mais StoreVehicleRequest ne le valide pas, et le controleur part de
        // validated() : la cle est donc retiree avant create(). Le lieu
        // disparait sans que rien ne le signale — ni erreur, ni champ refuse,
        // la fiche s'enregistre simplement sans son lieu.
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $lieu = \App\Models\Location::create([
            'name' => 'Depot central',
            'city' => 'Ouagadougou',
        ]);

        $charge = $this->charge($brand, $model, $engine);
        $charge['location_id'] = $lieu->id;

        $this->postJson('/api/vehicles', $charge)->assertCreated();

        $this->assertSame(
            $lieu->id,
            Vehicle::first()->location_id,
            'Le lieu selectionne doit arriver en base.',
        );
    }

    public function test_deux_creations_de_suite_ne_se_disputent_pas_la_reference(): void
    {
        // La reference vient d'une sequence (ReferenceService::next('VEH')).
        // Une collision rendrait une violation de contrainte unique, donc un
        // 500 sans message exploitable — exactement le symptome rapporte.
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        foreach ([1, 2] as $tour) {
            $reponse = $this->postJson('/api/vehicles', $this->charge($brand, $model, $engine));

            if ($reponse->status() >= 500) {
                $this->fail("Creation n°{$tour} : HTTP {$reponse->status()}.\n"
                    .substr($reponse->getContent(), 0, 2000));
            }

            $reponse->assertCreated();
        }

        $this->assertSame(2, Vehicle::count());
        $this->assertSame(
            2,
            Vehicle::distinct()->count('reference'),
            'Deux vehicules ne peuvent pas porter la meme reference.',
        );
    }

    public function test_la_charge_complete_du_formulaire_passe(): void
    {
        // Les tests precedents envoyaient le minimum. Le formulaire, lui, fait
        // 1247 lignes et joint des sous-objets que rien ne couvrait : un bloc
        // `import` ou `registration` des qu'un kilometrage est saisi, la liste
        // des equipements, le lieu, la devise, le type de promotion.
        //
        // C'est exactement la zone ou une panne de production peut se cacher
        // sans qu'aucun test ne la voie : le minimum passe, le reel echoue.
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $lieu = \App\Models\Location::create([
            'name' => 'Depot central', 'city' => 'Ouagadougou',
        ]);

        $charge = array_merge($this->charge($brand, $model, $engine), [
            'vehicle_type'     => 'passenger',
            'body_style'       => 'sedan',
            'transmission'     => 'automatic',
            'condition'        => 'used',
            'price_negotiable' => true,
            'deal_type'        => null,
            'currency'         => 'XOF',
            'site'             => 'Ouagadougou',
            'description'      => 'Vehicule de demonstration.',
            'location_id'      => $lieu->id,
            'power_hp'         => 132,
            'seats'            => 5,
            'doors'            => 4,
            // Sans plaque, le statut est « unregistered » et le kilometrage
            // part dans le bloc import — c'est ce que fait _buildPayload().
            'import'           => ['odometer_at_import_km' => 96000],
        ]);

        $reponse = $this->postJson('/api/vehicles', $charge);

        if ($reponse->status() >= 500) {
            $this->fail("POST /api/vehicles a rendu {$reponse->status()}.\n"
                .substr($reponse->getContent(), 0, 2000));
        }

        $reponse->assertCreated();

        $vehicule = Vehicle::first();
        $this->assertSame($lieu->id, $vehicule->location_id);
        $this->assertSame(
            96000,
            $vehicule->importDetail?->odometer_at_import_km,
            'Le kilometrage saisi sans plaque atterrit dans le bloc import.',
        );
    }

    public function test_la_charge_complete_avec_plaque_passe(): void
    {
        // L'autre branche : avec une plaque, le statut devient « registered »
        // et le kilometrage part dans le bloc registration. Les deux chemins
        // de syncTypeSpecificDetails doivent etre couverts.
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $charge = array_merge($this->charge($brand, $model, $engine), [
            'plate_number'  => '11 AB 1234',
            'registration'  => ['mileage_km' => 96000],
        ]);

        $reponse = $this->postJson('/api/vehicles', $charge);

        if ($reponse->status() >= 500) {
            $this->fail("POST /api/vehicles a rendu {$reponse->status()}.\n"
                .substr($reponse->getContent(), 0, 2000));
        }

        $reponse->assertCreated();

        $vehicule = Vehicle::first();
        $this->assertSame('11 AB 1234', $vehicule->registrationDetail?->plate_number);
        $this->assertSame(96000, $vehicule->registrationDetail?->mileage_km);
    }

    public function test_les_equipements_choisis_sont_rattaches(): void
    {
        [$brand, $model, $engine] = $this->referentiel();
        $this->enAdmin();

        $equipement = \App\Models\Feature::create([
            'name' => 'Climatisation', 'category' => 'confort',
        ]);

        $charge = array_merge($this->charge($brand, $model, $engine), [
            'features' => [$equipement->id],
        ]);

        $reponse = $this->postJson('/api/vehicles', $charge);

        if ($reponse->status() >= 500) {
            $this->fail("POST /api/vehicles a rendu {$reponse->status()}.\n"
                .substr($reponse->getContent(), 0, 2000));
        }

        $reponse->assertCreated();
        $this->assertSame(1, Vehicle::first()->features()->count());
    }

}
