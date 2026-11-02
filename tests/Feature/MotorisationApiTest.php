<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Database\Seeders\ReferenceDataSeeder;
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
        $this->seed([ReferenceDataSeeder::class]);

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
        $this->assertSame('X', $premiere['fuel_code']);
        $this->assertSame('AS6', $premiere['transmission_code']);
        $this->assertSame(8.2, $premiere['consumption']['reference_combined_l_100km']);
        $this->assertSame(9.5, $premiere['consumption']['reference_city_l_100km']);

        // Sans provenance ni cycle, le chiffre ne se discute pas.
        $this->assertSame('Ressources naturelles Canada', $premiere['source']['label']);
        $this->assertSame('méthode canadienne, cinq cycles', $premiere['source']['cycle']);
        $this->assertSame('nrcan', $premiere['source']['code']);
        $this->assertSame('5-cycle', $premiere['source']['cycle_code']);
    }

    public function test_les_millesimes_restent_distincts_sans_promouvoir_une_annee_voisine(): void
    {
        // Each year is source evidence in its own right; a nearby model year
        // must remain visible as a comparison, not silently replace the requested one.
        foreach ([2011, 2012, 2013, 2014] as $annee) {
            $this->cote(['model_year' => $annee, 'cle_source' => 'camry-'.$annee]);
        }

        $this->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->camry->id.'&year=2013')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.complete', true)
            ->assertJsonPath('meta.automatic_selection', false)
            ->assertJsonPath('data.0.match.level', 'incomplete');
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

    public function test_la_fiche_d_une_annonce_preserve_les_references_sans_fourchette_arbitraire(): void
    {
        $this->cote();
        $this->cote(['engine_l' => 3.5, 'consumption_combined' => 9.4, 'cle_source' => 'v6']);

        $annonce = Vehicle::factory()->create([
            'brand_id' => $this->camry->brand_id,
            'vehicle_model_id' => $this->camry->id,
            'manufacturing_year' => 2013,
            'engine_type_id' => EngineType::where('code', 'petrol')->value('id'),
            'transmission' => null,
            'gear_count' => null,
        ]);

        // Une annonce connait son modele, rarement sa motorisation : annoncer
        // 8,2 seul reviendrait a choisir un moteur pour l'acheteur.
        $this->getJson('/api/catalog/vehicles/'.$annonce->id)
            ->assertOk()
            ->assertJsonPath('data.consumption.official', null)
            ->assertJsonCount(2, 'data.consumption.references');
    }

    public function test_la_liste_des_annonces_ne_paie_pas_ce_calcul(): void
    {
        $this->cote();
        Vehicle::factory()->create([
            'brand_id' => $this->camry->brand_id,
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
        $annonce = Vehicle::factory()->create([
            'brand_id' => $this->camry->brand_id,
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
            'user_id' => $user->id,
            'brand_id' => $this->camry->brand_id,
            'vehicle_model_id' => $this->camry->id,
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
            ->assertJsonPath('data.consumption.combined_l_100km', null)
            ->assertJsonPath('data.consumption.reference_combined_l_100km', 8.2)
            ->assertJsonPath('data.consumption.match.level', 'incomplete')
            ->assertJsonPath('data.consumption.label', '2,5 l 4 cyl. boîte auto. 6')
            ->assertJsonPath('data.consumption.source', 'Ressources naturelles Canada')
            ->assertJsonPath('data.consumption.source_code', 'nrcan')
            ->assertJsonPath('data.consumption.cycle_code', '5-cycle')
            ->assertJsonPath('data.consumption.fuel_code', 'X');
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

    public function test_english_reference_metadata_keeps_codes_and_does_not_promote_an_uncertain_hybrid(): void
    {
        $this->cote(['fuel_code' => 'H']);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/catalog/motorisations?vehicle_model_id='.$this->camry->id)
            ->assertOk()
            ->assertJsonPath('data.0.fuel', 'Hybrid (type to be confirmed)')
            ->assertJsonPath('data.0.fuel_code', 'H')
            ->assertJsonPath('data.0.source.cycle', 'Canadian five-cycle method')
            ->assertJsonPath('data.0.source.cycle_code', '5-cycle')
            ->assertJsonPath('data.0.transmission_code', 'AS6')
            ->assertJsonPath('data.0.consumption.combined_l_100km', null)
            ->assertJsonPath('data.0.consumption.reference_combined_l_100km', 8.2)
            ->assertJsonPath('data.0.match.level', 'incomplete');
    }

    public function test_withdrawn_reference_selection_is_rejected_in_english(): void
    {
        $user = User::factory()->create(['role' => 'client', 'firebase_uid' => 'uid-withdrawn-en']);
        $vehicle = $this->vehiculeDeGarage($user);
        $reference = $this->cote(['reference_status' => 'withdrawn']);

        $this->actingAsClient($user)->withHeader('Accept-Language', 'en')
            ->patchJson('/api/my/vehicles/'.$vehicle->id, ['motorisation_id' => $reference->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motorisation_id.0', 'This reference has been withdrawn.');
    }
}
