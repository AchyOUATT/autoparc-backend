<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enregistrer un vehicule dont le modele n'est pas au referentiel.
 *
 * Le referentiel est bati sur les flux d'occasion europeens ; le parc
 * burkinabe vient aussi des Etats-Unis, du Golfe et du Japon. Il manquera
 * toujours des modeles — un CX-9, jamais vendu en Europe, rendait
 * l'enregistrement impossible : le formulaire exigeait un modele de la liste,
 * la validation aussi, et la colonne etait NOT NULL. Aucune issue, pas meme
 * celle de signaler le manque.
 *
 * Ces tests gardent les deux cotes de l'ouverture. D'un cote, plus d'impasse :
 * qui ne trouve pas son modele le tape, et le vehicule entre au garage. De
 * l'autre, pas de mensonge : un vehicule sans modele du referentiel ne peut
 * etre rattache a aucune compatibilite, et l'application doit dire « on ne
 * sait pas » plutot que « aucune piece ne convient ».
 */
class ModeleAbsentDuReferentielTest extends TestCase
{
    use RefreshDatabase;

    private function mazda(): Brand
    {
        return Brand::create(['name' => 'Mazda', 'slug' => 'mazda', 'is_active' => true]);
    }

    private function modele(Brand $marque, string $nom = 'Mazda3'): VehicleModel
    {
        return VehicleModel::create([
            'brand_id'  => $marque->id,
            'name'      => $nom,
            'slug'      => \Illuminate\Support\Str::slug($nom),
            'is_active' => true,
        ]);
    }

    // ── L'impasse est levee ──────────────────────────────────────────────────

    public function test_un_vehicule_s_enregistre_avec_un_modele_tape_a_la_main(): void
    {
        $marque = $this->mazda();

        $this->actingAsClient(uid: 'proprio')
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $marque->id,
                'model_libre'        => 'CX-9',
                'manufacturing_year' => 2015,
            ])
            ->assertCreated()
            ->assertJsonPath('data.model_libre', 'CX-9')
            ->assertJsonPath('data.vehicle_model_id', null);

        $this->assertDatabaseHas('owned_vehicles', [
            'model_libre'      => 'CX-9',
            'vehicle_model_id' => null,
        ]);
    }

    public function test_la_designation_reprend_le_modele_tape_a_la_main(): void
    {
        $marque = $this->mazda();

        $reponse = $this->actingAsClient(uid: 'proprio')
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $marque->id,
                'model_libre'        => 'CX-9',
                'manufacturing_year' => 2015,
            ])
            ->assertCreated();

        // « Mazda 2015 » serait un libelle plausible et incomplet : rien a
        // l'ecran ne dirait que le modele a disparu de la fiche.
        $this->assertSame('Mazda CX-9 2015', $reponse->json('data.designation'));
        $this->assertSame('CX-9', $reponse->json('data.identity.model'));
    }

    public function test_un_vehicule_sans_aucun_modele_est_refuse(): void
    {
        $marque = $this->mazda();

        $this->actingAsClient(uid: 'proprio')
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $marque->id,
                'manufacturing_year' => 2015,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_model_id', 'model_libre']);
    }

    /**
     * Une saisie blanche ne vaut pas un modele.
     *
     * Sans la normalisation, la colonne aurait stocke une chaine vide : ni la
     * validation ni l'affichage ne la distinguent de NULL, mais `whereNull` ne
     * la trouve pas. Le vehicule aurait eu un modele vide introuvable.
     */
    public function test_une_saisie_libre_blanche_vaut_une_absence(): void
    {
        $marque = $this->mazda();

        $this->actingAsClient(uid: 'proprio')
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $marque->id,
                'model_libre'        => '   ',
                'manufacturing_year' => 2015,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['model_libre']);

        $this->assertDatabaseCount('owned_vehicles', 0);
    }

    public function test_le_modele_tape_est_debarrasse_de_ses_espaces(): void
    {
        $marque = $this->mazda();

        $this->actingAsClient(uid: 'proprio')
            ->postJson('/api/my/vehicles', [
                'brand_id'           => $marque->id,
                'model_libre'        => '  CX-9  ',
                'manufacturing_year' => 2015,
            ])
            ->assertCreated()
            ->assertJsonPath('data.model_libre', 'CX-9');
    }

    // ── La mise a jour ───────────────────────────────────────────────────────

    public function test_une_mise_a_jour_partielle_n_exige_pas_le_modele(): void
    {
        $proprio  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicule = OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $this->mazda()->id,
            'model_libre'        => 'CX-9',
            'manufacturing_year' => 2015,
        ]);

        // Le kilometrage seul : ni modele ni saisie libre dans la requete.
        $this->actingAsClient($proprio)
            ->putJson("/api/my/vehicles/{$vehicule->id}", ['mileage_km' => 142000])
            ->assertOk();

        $this->assertDatabaseHas('owned_vehicles', [
            'id'          => $vehicule->id,
            'mileage_km'  => 142000,
            'model_libre' => 'CX-9',
        ]);
    }

    /**
     * On ne peut pas vider les deux champs.
     *
     * C'est la porte qu'a ouverte le retrait de `required_without` sur la mise
     * a jour : une requete portant `vehicle_model_id: null` et rien d'autre
     * effacait le seul champ qui nommait le vehicule.
     */
    public function test_une_mise_a_jour_ne_peut_pas_laisser_le_vehicule_sans_modele(): void
    {
        $proprio  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $marque   = $this->mazda();
        $modele   = $this->modele($marque);
        $vehicule = OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $marque->id,
            'vehicle_model_id'   => $modele->id,
            'manufacturing_year' => 2015,
        ]);

        $this->actingAsClient($proprio)
            ->putJson("/api/my/vehicles/{$vehicule->id}", ['vehicle_model_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_model_id']);

        $this->assertDatabaseHas('owned_vehicles', [
            'id'               => $vehicule->id,
            'vehicle_model_id' => $modele->id,
        ]);
    }

    public function test_une_mise_a_jour_ne_peut_pas_effacer_la_seule_saisie_libre(): void
    {
        $proprio  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicule = OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $this->mazda()->id,
            'model_libre'        => 'CX-9',
            'manufacturing_year' => 2015,
        ]);

        $this->actingAsClient($proprio)
            ->putJson("/api/my/vehicles/{$vehicule->id}", ['model_libre' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_model_id']);

        $this->assertDatabaseHas('owned_vehicles', [
            'id'          => $vehicule->id,
            'model_libre' => 'CX-9',
        ]);
    }

    /** Remplacer une saisie libre par le modele du referentiel reste permis. */
    public function test_le_referentiel_peut_prendre_la_place_de_la_saisie_libre(): void
    {
        $proprio  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $marque   = $this->mazda();
        $modele   = $this->modele($marque, 'CX-9');
        $vehicule = OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $marque->id,
            'model_libre'        => 'cx9',
            'manufacturing_year' => 2015,
        ]);

        $this->actingAsClient($proprio)
            ->putJson("/api/my/vehicles/{$vehicule->id}", [
                'vehicle_model_id' => $modele->id,
                'model_libre'      => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.identity.model', 'CX-9');

        $this->assertDatabaseHas('owned_vehicles', [
            'id'               => $vehicule->id,
            'vehicle_model_id' => $modele->id,
            'model_libre'      => null,
        ]);
    }

    // ── Pas de mensonge sur la compatibilite ─────────────────────────────────

    /**
     * Sans modele du referentiel, aucune compatibilite ne peut correspondre.
     *
     * Le service passait `vehicle_model_id` a un parametre typé `int` : la
     * recherche de pieces rendait une erreur 500 sur un vehicule parfaitement
     * valide.
     */
    public function test_la_recherche_de_pieces_repond_sans_erreur(): void
    {
        $proprio  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicule = OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $this->mazda()->id,
            'model_libre'        => 'CX-9',
            'manufacturing_year' => 2015,
        ]);

        $this->actingAsClient($proprio)
            ->getJson("/api/my/vehicles/{$vehicule->id}/compatible-parts")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Zero piece ne veut pas dire « aucune ne convient » mais « on ne sait
     * pas » — et c'est `compatibility_ready` qui porte la difference.
     *
     * Le code moteur est renseigne a dessein : c'est le cas ou la precision
     * moteur suffisait a repondre vrai, ce qui aurait affiche « Non
     * compatible » en rouge sur un vehicule dont on ignore le modele.
     */
    public function test_un_vehicule_sans_modele_annonce_une_compatibilite_indeterminee(): void
    {
        $proprio  = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $vehicule = OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $this->mazda()->id,
            'model_libre'        => 'CX-9',
            'manufacturing_year' => 2015,
            'engine_code'        => 'PY-VPS',
        ]);

        $this->assertFalse(
            $vehicule->hasPreciseEngineData(),
            "Sans modele, un code moteur ne rend pas la compatibilite determinable.",
        );

        $this->actingAsClient($proprio)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->assertOk()
            ->assertJsonPath('data.compatibility_ready', false);
    }

    public function test_la_verification_d_une_piece_ne_conclut_pas_a_l_incompatibilite(): void
    {
        $proprio = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        OwnedVehicle::create([
            'user_id'            => $proprio->id,
            'brand_id'           => $this->mazda()->id,
            'model_libre'        => 'CX-9',
            'manufacturing_year' => 2015,
            'engine_code'        => 'PY-VPS',
        ]);

        $categorie = \App\Models\PartCategory::create([
            'name' => 'Filtre a huile',
            'slug' => 'filtre-a-huile',
        ]);

        $piece = Part::create([
            'sku'              => '90915-YZZD4',
            'name'             => 'Filtre a huile',
            'part_category_id' => $categorie->id,
            'selling_price'    => 3000,
            'is_active'        => true,
        ]);

        $reponse = $this->actingAsClient($proprio)
            ->getJson("/api/my/parts/{$piece->id}/garage-compatibility")
            ->assertOk();

        $this->assertFalse($reponse->json('data.0.compatible'));
        $this->assertFalse(
            $reponse->json('data.0.compatibility_ready'),
            'Les deux a faux : c\'est ce couple que l\'ecran lit comme « on ne peut pas savoir ».',
        );
    }
}
