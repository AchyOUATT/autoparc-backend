<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\VehicleModel;
use App\Support\Toyota90915Yzzd1AnciensModelesImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comportement RÉEL de l'API sur les générations anciennes et leurs lignes
 * 90915-YZZD1 : bonnes et mauvaises combinaisons, critères inconnus, et
 * année de transition partagée par deux générations.
 */
class Toyota90915Yzzd1AnciensModelesApiTest extends TestCase
{
    use RefreshDatabase;

    private Part $filtre;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['toyota' => 'Toyota', 'lexus' => 'Lexus'] as $slug => $nom) {
            Brand::firstOrCreate(['slug' => $slug], ['name' => $nom, 'is_active' => true]);
        }
        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);
        $this->filtre = Part::create([
            'sku' => '90915-YZZD1', 'name' => 'Filtre a huile visse 90915-YZZD1',
            'part_category_id' => $categorie->id, 'manufacturer_reference' => '90915-YZZD1',
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 3000, 'currency' => 'XOF',
            'stock_quantity' => 15, 'is_active' => true, 'is_available' => true,
        ]);

        Toyota90915Yzzd1AnciensModelesImport::run();
    }

    private function statut(string $slug, ?int $annee, ?string $moteur): ?string
    {
        $criteres = array_filter([
            'vehicle_model_id' => VehicleModel::where('slug', $slug)->value('id'),
            'year' => $annee,
            'engine_code' => $moteur,
            'include_candidates' => 1,
        ], fn ($v) => $v !== null);

        foreach ($this->getJson('/api/catalog/compatible-parts?'.http_build_query($criteres))->assertOk()->json('data') as $piece) {
            if ((int) $piece['id'] === $this->filtre->id) {
                return $piece['compatibility_assessment']['status'];
            }
        }

        return null;
    }

    public function test_4runner_n280_2tr_fe_seulement_sur_ses_annees_de_fabrication(): void
    {
        foreach ([2009, 2010, 2011] as $annee) {
            $this->assertSame('confirmed', $this->statut('4runner-n280', $annee, '2TR-FE'), "2TR-FE {$annee}");
        }
        foreach ([2012, 2015, 2024] as $annee) {
            $this->assertNull($this->statut('4runner-n280', $annee, '2TR-FE'), "2TR-FE {$annee} : hors période EPC.");
        }
        $this->assertNull($this->statut('4runner-n280', 2010, '1GR-FE'), 'Le V6 1GR-FE prend une cartouche.');
        $this->assertSame('candidate', $this->statut('4runner-n280', 2010, null), 'Moteur inconnu : à vérifier.');
        $this->assertNull($this->statut('4runner-n280', 2015, null), 'Moteur inconnu mais année hors ligne : rien.');
    }

    public function test_sienna_xl10_suit_les_annees_de_fabrication(): void
    {
        $this->assertSame('confirmed', $this->statut('sienna-xl10', 1997, '1MZ-FE'));
        $this->assertSame('confirmed', $this->statut('sienna-xl10', 2002, '1MZ-FE'));
        $this->assertNull($this->statut('sienna-xl10', 2003, '1MZ-FE'), 'Aucune Sienna XL10 fabriquée en 2003.');
    }

    public function test_les_lexus_restent_au_moteur_documente(): void
    {
        $this->assertSame('confirmed', $this->statut('gs-s160', 2004, '2JZ-GE'));
        $this->assertNull($this->statut('gs-s160', 2005, '2JZ-GE'), 'GS S160 fabriquée jusqu\'en 12/2004.');
        $this->assertNull($this->statut('gs-s160', 2002, '3UZ-FE'), 'GS 430 V8 exclue.');
        $this->assertNull($this->statut('is-xe10', 2003, '1G-FE'), 'IS 200 exclue.');
        $this->assertSame('candidate', $this->statut('is-xe10', 2003, null));
    }

    public function test_annee_de_transition_chaque_generation_porte_sa_propre_ligne(): void
    {
        // 1999 : l'Avalon XX10 (jusqu'en 08/1999) et la XX20 (dès 08/1999)
        // ont toutes deux été fabriquées. Le véhicule porte sa génération ;
        // chacune confirme pour elle-même.
        $this->assertSame('confirmed', $this->statut('avalon-xx10', 1999, '1MZ-FE'));
        $this->assertSame('confirmed', $this->statut('avalon-xx20', 1999, '1MZ-FE'));
        $this->assertNull($this->statut('avalon-xx10', 2000, '1MZ-FE'));
    }

    public function test_garage_sans_moteur_puis_avec_moteur(): void
    {
        $client = User::factory()->create(['role' => 'client', 'firebase_uid' => 'yzzd1-anciens']);
        $vehicule = OwnedVehicle::create([
            'user_id' => $client->id,
            'brand_id' => Brand::where('slug', 'toyota')->value('id'),
            'vehicle_model_id' => VehicleModel::where('slug', 'highlander-xu20')->value('id'),
            'manufacturing_year' => 2003,
            'model_year' => 2004,
        ]);
        $url = "/api/my/parts/{$this->filtre->id}/garage-compatibility";

        $this->actingAsClient($client)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.status', 'candidate')
            ->assertJsonPath('data.0.missing_criteria', ['engine_code']);

        // 2003 est l'année du passage 1MZ-FE → 3MZ-FE : les deux confirment.
        foreach (['1MZ-FE', '3MZ-FE'] as $moteur) {
            $vehicule->update(['engine_code' => $moteur]);
            $this->actingAsClient($client)->getJson($url)->assertOk()
                ->assertJsonPath('data.0.status', 'confirmed');
        }

        $vehicule->update(['engine_code' => '2AZ-FE']);
        $this->actingAsClient($client)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.status', 'unknown');
    }
}
