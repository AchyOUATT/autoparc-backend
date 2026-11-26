<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\User;
use App\Models\VehicleModel;
use App\Support\Toyota90915Yzzd1Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comportement RÉEL de l'API sur les lignes posées par l'import 90915-YZZD1 :
 * cas positifs, cas négatifs, et critères inconnus (moteur, finition, année,
 * châssis, marché).
 *
 * Rappel du moteur de compatibilité : `confirmed` exige que TOUTES les
 * contraintes déclarées par la ligne soient connues et égales ; un critère
 * inconnu donne `candidate` (« à vérifier »), jamais `confirmed` ; un critère
 * connu et différent écarte la ligne. La liste publique ne rend que les
 * `confirmed` sauf `include_candidates=1`.
 */
class Toyota90915Yzzd1CompatibiliteApiTest extends TestCase
{
    use RefreshDatabase;

    private Part $filtre;

    protected function setUp(): void
    {
        parent::setUp();

        $toyota = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota', 'is_active' => true]);
        $lexus = Brand::firstOrCreate(['slug' => 'lexus'], ['name' => 'Lexus', 'is_active' => true]);
        VehicleModel::create(['brand_id' => $toyota->id, 'slug' => 'camry-xv20', 'name' => 'Camry', 'generation' => 'XV20', 'is_active' => true]);
        $rx = VehicleModel::create(['brand_id' => $lexus->id, 'slug' => 'rx-xu30', 'name' => 'RX', 'generation' => 'XU30', 'is_active' => true,
            'production_start' => 2003, 'production_end' => 2009]);
        foreach ([['330', 'RX 330', 1], ['400h', 'RX 400h', 2], ['350', 'RX 350', 3]] as [$code, $nom, $rang]) {
            Trim::create(['vehicle_model_id' => $rx->id, 'code' => $code, 'name' => $nom, 'rank' => $rang]);
        }

        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);
        $this->filtre = Part::create([
            'sku' => Toyota90915Yzzd1Import::SKU, 'name' => 'Filtre a huile visse 90915-YZZD1',
            'part_category_id' => $categorie->id, 'manufacturer_reference' => '90915-YZZD1',
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 3000, 'currency' => 'XOF',
            'stock_quantity' => 15, 'is_active' => true, 'is_available' => true,
        ]);

        Toyota90915Yzzd1Import::run();
    }

    private function modele(string $slug): int
    {
        return (int) VehicleModel::where('slug', $slug)->value('id');
    }

    private function finition(string $code): int
    {
        return (int) Trim::where('vehicle_model_id', $this->modele('rx-xu30'))->where('code', $code)->value('id');
    }

    /** @param array<string, mixed> $criteres */
    private function recherche(array $criteres): array
    {
        return $this->getJson('/api/catalog/compatible-parts?'.http_build_query($criteres))->assertOk()->json('data');
    }

    /** Statut de la pièce, avec les candidats inclus ; null si elle n'est même pas candidate. */
    private function statut(array $criteres): ?array
    {
        foreach ($this->recherche([...$criteres, 'include_candidates' => 1]) as $piece) {
            if ((int) $piece['id'] === $this->filtre->id) {
                return $piece['compatibility_assessment'];
            }
        }

        return null;
    }

    public function test_bon_modele_bon_moteur_bonne_annee_est_confirme(): void
    {
        $criteres = ['vehicle_model_id' => $this->modele('camry-xv20'), 'year' => 1999, 'engine_code' => '1mz-fe'];

        $this->assertSame([$this->filtre->id], array_column($this->recherche($criteres), 'id'));
        $this->assertSame('confirmed', $this->statut($criteres)['status']);
    }

    public function test_un_autre_moteur_connu_ecarte_la_piece(): void
    {
        $criteres = ['vehicle_model_id' => $this->modele('camry-xv20'), 'year' => 1999, 'engine_code' => '5S-FE'];

        $this->assertSame([], $this->recherche($criteres));
        $this->assertNull($this->statut($criteres), 'Un moteur différent n\'est même pas candidat.');
    }

    public function test_une_annee_hors_bornes_ecarte_la_piece(): void
    {
        foreach ([1995, 2002] as $annee) {
            $criteres = ['vehicle_model_id' => $this->modele('camry-xv20'), 'year' => $annee, 'engine_code' => '1MZ-FE'];
            $this->assertNull($this->statut($criteres), "Année {$annee}");
        }
        // Les bornes sont inclusives : 1996 et 2001 (années de fabrication EPC).
        foreach ([1996, 2001] as $annee) {
            $criteres = ['vehicle_model_id' => $this->modele('camry-xv20'), 'year' => $annee, 'engine_code' => '1MZ-FE'];
            $this->assertSame('confirmed', $this->statut($criteres)['status'], "Année {$annee}");
        }
    }

    public function test_moteur_inconnu_reste_a_verifier(): void
    {
        $criteres = ['vehicle_model_id' => $this->modele('camry-xv20'), 'year' => 1999];

        $this->assertSame([], $this->recherche($criteres), 'Sans moteur, rien n\'est présenté comme compatible.');
        $statut = $this->statut($criteres);
        $this->assertSame('candidate', $statut['status']);
        $this->assertFalse($statut['compatible']);
        $this->assertSame(['engine_code'], $statut['missing_criteria']);
    }

    public function test_annee_inconnue_reste_a_verifier(): void
    {
        $statut = $this->statut(['vehicle_model_id' => $this->modele('camry-xv20'), 'engine_code' => '1MZ-FE']);

        $this->assertSame('candidate', $statut['status']);
        $this->assertSame(['year'], $statut['missing_criteria']);
    }

    public function test_la_finition_est_exigee_quand_la_ligne_la_declare(): void
    {
        $base = ['vehicle_model_id' => $this->modele('rx-xu30'), 'year' => 2004, 'engine_code' => '3MZ-FE'];

        $this->assertSame('confirmed', $this->statut([...$base, 'trim_id' => $this->finition('330')])['status']);
        $this->assertNull($this->statut([...$base, 'trim_id' => $this->finition('350')]), 'RX 350 (2GR-FE) : écartée.');

        $inconnue = $this->statut($base);
        $this->assertSame('candidate', $inconnue['status']);
        $this->assertSame(['trim_id'], $inconnue['missing_criteria']);
        $this->assertSame([], $this->recherche($base));
    }

    public function test_les_finitions_du_rx_suivent_leurs_propres_annees(): void
    {
        $base = ['vehicle_model_id' => $this->modele('rx-xu30'), 'engine_code' => '3MZ-FE'];

        $this->assertNull($this->statut([...$base, 'year' => 2004, 'trim_id' => $this->finition('400h')]), 'RX 400h avant 2005.');
        $this->assertSame('confirmed', $this->statut([...$base, 'year' => 2008, 'trim_id' => $this->finition('400h')])['status']);
        $this->assertNull($this->statut([...$base, 'year' => 2007, 'trim_id' => $this->finition('330')]), 'RX 330 après 2006.');
    }

    public function test_garage_moteur_inconnu_puis_renseigne_puis_different(): void
    {
        $client = User::factory()->create(['role' => 'client', 'firebase_uid' => 'yzzd1-garage']);
        $vehicule = OwnedVehicle::create([
            'user_id' => $client->id,
            'brand_id' => Brand::where('slug', 'toyota')->value('id'),
            'vehicle_model_id' => $this->modele('camry-xv20'),
            'manufacturing_year' => 1999,
        ]);
        $url = "/api/my/parts/{$this->filtre->id}/garage-compatibility";

        $this->actingAsClient($client)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.status', 'candidate')
            ->assertJsonPath('data.0.compatible', false)
            ->assertJsonPath('data.0.missing_criteria', ['engine_code']);

        $vehicule->update(['engine_code' => '1MZ-FE']);
        $this->actingAsClient($client)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.status', 'confirmed')
            ->assertJsonPath('data.0.compatible', true);

        $vehicule->update(['engine_code' => '2AZ-FE']);
        $this->actingAsClient($client)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.status', 'unknown')
            ->assertJsonPath('data.0.compatible', false)
            ->assertJsonPath('data.0.reason', 'no_matching_fitment');
    }

    public function test_le_chassis_et_le_marche_documentent_sans_filtrer(): void
    {
        // Le châssis (`part_fitments.chassis_code`) et le marché (note) sont
        // affichés, pas comparés : le véhicule n'a pas de châssis saisi, et
        // `specification_market` n'entre pas dans l'appariement.
        $client = User::factory()->create(['role' => 'client', 'firebase_uid' => 'yzzd1-chassis']);
        OwnedVehicle::create([
            'user_id' => $client->id,
            'brand_id' => Brand::where('slug', 'toyota')->value('id'),
            'vehicle_model_id' => $this->modele('camry-xv20'),
            'manufacturing_year' => 1999,
            'engine_code' => '1MZ-FE',
            'specification_market' => 'EU',
        ]);

        $this->assertSame('MCV20', $this->filtre->fitments()->where('vehicle_model_id', $this->modele('camry-xv20'))->value('chassis_code'));
        $this->actingAsClient($client)->getJson("/api/my/parts/{$this->filtre->id}/garage-compatibility")->assertOk()
            ->assertJsonPath('data.0.status', 'confirmed');
    }
}
