<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le choix de sa motorisation, et la cote qui en decoule.
 *
 * C'est le chainon qui manquait : 29 000 cotes officielles dormaient en base
 * sans qu'aucun ecran ne les atteigne. Un proprietaire reconnait son moteur —
 * « 2,5 l 4 cyl. » — et l'application sait alors ce que sa voiture consomme.
 *
 * Deux exigences tiennent ces tests :
 *   - une cote ne s'affiche jamais sans sa provenance ni son cycle d'essai,
 *     sans quoi 8,2 et 6,4 pour la meme voiture passeraient pour une
 *     contradiction plutot que pour deux protocoles ;
 *   - une motorisation d'un autre modele est refusee : une erreur de saisie
 *     afficherait une consommation credible et fausse.
 */
class MotorisationApiTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $camry;

    protected function setUp(): void
    {
        parent::setUp();

        // La factory de vehicule pioche un type de moteur, une couleur et une
        // motricite dans les donnees de reference : sans elles, elle ne peut
        // rien construire.
        $this->seed([\Database\Seeders\ReferenceDataSeeder::class]);

        $toyota = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);
        $this->camry = VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Camry', 'generation' => 'XV50',
            'production_start' => 2011, 'production_end' => 2017,
        ]);
    }

    private function cote(array $attributs = []): Motorisation
    {
        $defaut = [
            'source' => 'nrcan', 'cycle' => '5-cycle',
            'model_year' => 2013, 'make_raw' => 'Toyota', 'model_raw' => 'Camry',
            'engine_l' => 2.5, 'cylinders' => 4, 'transmission_code' => 'AS6', 'fuel_code' => 'X',
            'consumption_city' => 9.5, 'consumption_highway' => 6.6, 'consumption_combined' => 8.2,
            'vehicle_model_id' => $this->camry->id,
        ];
        $attributs = array_merge($defaut, $attributs);
        $attributs['cle_source'] ??= md5(json_encode($attributs));

        return Motorisation::create($attributs);
    }

    // ── Catalogue ────────────────────────────────────────────────────

    public function test_les_motorisations_d_un_modele_se_listent(): void
    {
        $this->cote();
        $this->cote(['engine_l' => 3.5, 'cylinders' => 6, 'consumption_combined' => 9.4, 'cle_source' => 'v6']);

        $reponse = $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->camry->id.'&year=2013')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $premiere = $reponse->json('data.0');
        $this->assertSame('2,5 l 4 cyl. boîte auto. 6', $premiere['label']);
        $this->assertSame('Essence ordinaire', $premiere['fuel']);
        $this->assertSame(8.2, $premiere['consumption']['combined_l_100km']);
        $this->assertSame(9.5, $premiere['consumption']['city_l_100km']);

        // Sans provenance ni cycle, le chiffre ne se discute pas.
        $this->assertSame('Ressources naturelles Canada', $premiere['source']['label']);
        $this->assertSame('essai canadien, cinq cycles', $premiere['source']['cycle']);
    }

    public function test_une_meme_motorisation_ne_se_repete_pas_d_un_millesime_a_l_autre(): void
    {
        // Le meme moteur revient chaque annee dans les sources : la liste
        // proposee au proprietaire ne doit pas lui offrir six fois le meme.
        foreach ([2011, 2012, 2013, 2014] as $annee) {
            $this->cote(['model_year' => $annee, 'cle_source' => 'camry-'.$annee]);
        }

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->camry->id.'&year=2013')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model_year', 2013);
    }

    public function test_une_cylindree_ronde_garde_sa_decimale(): void
    {
        // « 2 l » s'affichait la ou les papiers du vehicule portent « 2,0 l ».
        $this->cote(['engine_l' => 2.0, 'cle_source' => 'deux-litres']);

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->camry->id)
            ->assertOk()
            ->assertJsonPath('data.0.label', '2,0 l 4 cyl. boîte auto. 6');
    }

    public function test_un_modele_inconnu_est_refuse(): void
    {
        $this->getJson('/api/catalog/motorisations?vehicle_model_id=99999')
            ->assertStatus(422);
    }

    // ── Fiche d'une annonce ──────────────────────────────────────────

    public function test_la_fiche_d_une_annonce_annonce_une_fourchette(): void
    {
        $this->cote();
        $this->cote(['engine_l' => 3.5, 'consumption_combined' => 9.4, 'cle_source' => 'v6']);

        $annonce = \App\Models\Vehicle::factory()->create([
            'brand_id'           => $this->camry->brand_id,
            'vehicle_model_id'   => $this->camry->id,
            'manufacturing_year' => 2013,
        ]);

        // Une annonce connait son modele, rarement sa motorisation : annoncer
        // 8,2 seul reviendrait a choisir un moteur pour l'acheteur.
        $this->getJson('/api/catalog/vehicles/'.$annonce->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.official.min', 8.2)
            ->assertJsonPath('data.consumption.official.max', 9.4)
            ->assertJsonPath('data.consumption.official.motorisations', 2)
            ->assertJsonPath('data.consumption.official.source', 'Ressources naturelles Canada');
    }

    public function test_la_liste_des_annonces_ne_paie_pas_ce_calcul(): void
    {
        $this->cote();
        \App\Models\Vehicle::factory()->create([
            'brand_id'         => $this->camry->brand_id,
            'vehicle_model_id' => $this->camry->id,
        ]);

        // Une requete par annonce sur une liste de quatre-vingts fiches : la
        // fourchette reste reservee a la fiche detaillee.
        $this->getJson('/api/catalog/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.consumption.official', null);
    }

    public function test_un_modele_sans_cote_ne_promet_rien(): void
    {
        $fortuner = VehicleModel::create([
            'brand_id' => $this->camry->brand_id, 'name' => 'Fortuner',
        ]);
        $annonce = \App\Models\Vehicle::factory()->create([
            'brand_id'         => $this->camry->brand_id,
            'vehicle_model_id' => $fortuner->id,
        ]);

        $this->getJson('/api/catalog/vehicles/'.$annonce->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.official', null);
    }

    // ── Garage ───────────────────────────────────────────────────────

    private function vehiculeDeGarage(User $user): OwnedVehicle
    {
        return OwnedVehicle::create([
            'user_id'            => $user->id,
            'brand_id'           => $this->camry->brand_id,
            'vehicle_model_id'   => $this->camry->id,
            'manufacturing_year' => 2013,
        ]);
    }

    public function test_choisir_sa_motorisation_donne_sa_consommation(): void
    {
        $user = User::factory()->create(['role' => 'client', 'firebase_uid' => 'uid-camry']);
        $vehicule = $this->vehiculeDeGarage($user);
        $cote = $this->cote();

        $this->actingAsClient($user)
            ->patchJson('/api/my/vehicles/'.$vehicule->id, ['motorisation_id' => $cote->id])
            ->assertOk()
            ->assertJsonPath('data.consumption.combined_l_100km', 8.2)
            ->assertJsonPath('data.consumption.label', '2,5 l 4 cyl. boîte auto. 6')
            ->assertJsonPath('data.consumption.source', 'Ressources naturelles Canada');
    }

    public function test_sans_motorisation_choisie_aucune_consommation_n_est_affichee(): void
    {
        $user = User::factory()->create(['role' => 'client', 'firebase_uid' => 'uid-sans-moteur']);
        $vehicule = $this->vehiculeDeGarage($user);

        $this->actingAsClient($user)
            ->getJson('/api/my/vehicles/'.$vehicule->id)
            ->assertOk()
            ->assertJsonPath('data.consumption', null)
            ->assertJsonPath('data.motorisation_id', null);
    }

    public function test_une_motorisation_d_un_autre_modele_est_refusee(): void
    {
        $user = User::factory()->create(['role' => 'client', 'firebase_uid' => 'uid-erreur']);
        $vehicule = $this->vehiculeDeGarage($user);

        $hilux = VehicleModel::create(['brand_id' => $this->camry->brand_id, 'name' => 'Hilux']);
        $coteHilux = $this->cote([
            'model_raw' => 'Hilux', 'vehicle_model_id' => $hilux->id,
            'consumption_combined' => 9.4, 'cle_source' => 'hilux',
        ]);

        // Sans ce refus, la Camry afficherait 9,4 l/100 — credible, et faux.
        $this->actingAsClient($user)
            ->patchJson('/api/my/vehicles/'.$vehicule->id, ['motorisation_id' => $coteHilux->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motorisation_id');
    }
}
