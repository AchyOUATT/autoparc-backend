<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\OemNumber;
use App\Models\OemNumberRelation;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\PartCompatibilityAssessment;
use App\Support\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Châssis, marché de destination et mois de fabrication.
 *
 * Positif : critère satisfait → confirmé.
 * Négatif : critère incompatible → la règle est exclue.
 * Manquant : critère exigé mais inconnu → candidat, jamais confirmé.
 */
class CompatibiliteChassisMarcheMoisTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $model;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::create(['name' => 'Marque fictive', 'slug' => 'marque-fictive-cm']);
        $this->model = VehicleModel::create([
            'brand_id' => $this->brand->id, 'name' => 'Tout-terrain fictif', 'generation' => 'N280',
            'production_start' => 2009, 'production_end' => 2024,
        ]);
    }

    private function part(array ...$fitments): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'categorie-cm'], ['name' => 'Catégorie CM']);
        $part = Part::create([
            'name' => 'Filtre fictif', 'part_category_id' => $category->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000,
        ]);
        foreach ($fitments ?: [[]] as $fitment) {
            $part->fitments()->create([
                'vehicle_model_id' => $this->model->id,
                'source' => FitmentSource::Declared,
                ...$fitment,
            ]);
        }

        return $part->fresh();
    }

    private function assess(Part $part, array $criteria = []): array
    {
        return app(PartCompatibilityAssessment::class)->assess($part, [
            'vehicle_model_id' => $this->model->id,
            'year' => 2010,
            ...$criteria,
        ]);
    }

    // ── Non-régression ─────────────────────────────────────────────────

    public function test_un_code_chassis_informatif_ne_change_rien(): void
    {
        $part = $this->part(['chassis_code' => 'TRN280/TRN285', 'engine_code' => '2TR-FE']);

        $this->assertSame('confirmed', $this->assess($part, ['engine_code' => '2TR-FE'])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['engine_code' => '2TR-FE', 'chassis_code' => 'GRN285'])['status']);
    }

    public function test_une_regle_sans_restriction_reste_confirmee_sans_donnees_nouvelles(): void
    {
        $part = $this->part(['year_from' => 2009, 'year_to' => 2011]);

        $this->assertSame('confirmed', $this->assess($part)['status']);
    }

    // ── Châssis ────────────────────────────────────────────────────────

    public function test_chassis_restrictif_positif_negatif_et_manquant(): void
    {
        $part = $this->part(['chassis_code' => 'TRN280/TRN285', 'chassis_restricted' => true]);

        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'TRN285'])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'trn280'])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'TRN285L-GKPSKA'])['status']);

        $this->assertSame('unknown', $this->assess($part, ['chassis_code' => 'GRN285'])['status']);

        $manquant = $this->assess($part);
        $this->assertSame('candidate', $manquant['status']);
        $this->assertFalse($manquant['compatible']);
        $this->assertSame(['chassis_code'], $manquant['missing_criteria']);
    }

    public function test_chassis_plus_vague_que_la_regle_reste_a_verifier(): void
    {
        $part = $this->part(['chassis_code' => 'ACV30L', 'chassis_restricted' => true]);

        $this->assertSame('candidate', $this->assess($part, ['chassis_code' => 'ACV30'])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'ACV30L-AEPNKA'])['status']);
    }

    public function test_famille_declaree_par_etoile(): void
    {
        $part = $this->part(['chassis_code' => 'MCV3*', 'chassis_restricted' => true]);

        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'MCV36'])['status']);
        $this->assertSame('unknown', $this->assess($part, ['chassis_code' => 'VCV10'])['status']);
    }

    // ── Marché ─────────────────────────────────────────────────────────

    public function test_marche_positif_negatif_manquant_et_inconnu(): void
    {
        $part = $this->part(['market_codes' => ['USA', 'CAN']]);

        $this->assertSame('confirmed', $this->assess($part, ['specification_market' => 'USA'])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['specification_market' => 'us'])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['specification_market' => 'CAN'])['status']);
        $this->assertSame('unknown', $this->assess($part, ['specification_market' => 'EUR'])['status']);

        $this->assertSame(['specification_market'], $this->assess($part)['missing_criteria']);
        // Un code que le référentiel ne connaît pas ne se compare pas.
        $inconnu = $this->assess($part, ['specification_market' => 'XYZ']);
        $this->assertSame('candidate', $inconnu['status']);
        $this->assertSame(['specification_market'], $inconnu['missing_criteria']);
    }

    public function test_hierarchie_du_catalogue_general(): void
    {
        $general = $this->part(['market_codes' => ['GEN']]);
        $this->assertSame('confirmed', $this->assess($general, ['specification_market' => 'AFR'])['status']);

        $afrique = $this->part(['market_codes' => ['AFR']]);
        $this->assertSame('candidate', $this->assess($afrique, ['specification_market' => 'GEN'])['status']);
        $this->assertSame('unknown', $this->assess($afrique, ['specification_market' => 'MDE'])['status']);
    }

    public function test_une_liste_de_marches_vide_est_stockee_nulle(): void
    {
        $part = $this->part(['market_codes' => []]);

        $this->assertNull(DB::table('part_fitments')->where('part_id', $part->id)->value('market_codes'));
        $this->assertSame('confirmed', $this->assess($part)['status']);
    }

    // ── Mois aux bornes ────────────────────────────────────────────────

    public function test_mois_aux_bornes(): void
    {
        // 11/2009 – 08/2011, l'exemple des TRN280/TRN285 de l'EPC USA.
        $part = $this->part(['year_from' => 2009, 'month_from' => 11, 'year_to' => 2011, 'month_to' => 8]);

        $this->assertSame('confirmed', $this->assess($part, ['year' => 2010])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['year' => 2009, 'month' => 12])['status']);
        $this->assertSame('confirmed', $this->assess($part, ['year' => 2011, 'month' => 8])['status']);
        $this->assertSame('unknown', $this->assess($part, ['year' => 2009, 'month' => 10])['status']);
        $this->assertSame('unknown', $this->assess($part, ['year' => 2011, 'month' => 9])['status']);

        $borne = $this->assess($part, ['year' => 2009]);
        $this->assertSame('candidate', $borne['status']);
        $this->assertSame(['manufacturing_month'], $borne['missing_criteria']);

        $sansAnnee = $this->assess($part, ['year' => null]);
        $this->assertSame('candidate', $sansAnnee['status']);
        $this->assertSame(['year'], $sansAnnee['missing_criteria']);
    }

    // ── Combinaisons ───────────────────────────────────────────────────

    public function test_criteres_manquants_cumules(): void
    {
        $part = $this->part([
            'engine_code' => '2TR-FE', 'chassis_code' => 'TRN285', 'chassis_restricted' => true,
            'market_codes' => ['USA'],
        ]);

        $resultat = $this->assess($part);
        $this->assertSame('candidate', $resultat['status']);
        $this->assertEqualsCanonicalizing(['engine_code', 'chassis_code', 'specification_market'], $resultat['missing_criteria']);

        // Un seul critère incompatible exclut la règle, même si d'autres manquent.
        $this->assertSame('unknown', $this->assess($part, ['specification_market' => 'JPN'])['status']);
    }

    public function test_une_regle_exclue_n_empeche_pas_une_autre_de_confirmer(): void
    {
        $part = $this->part(
            ['chassis_code' => 'TRN285', 'chassis_restricted' => true, 'market_codes' => ['USA']],
            ['chassis_code' => 'TRN285', 'chassis_restricted' => true, 'market_codes' => ['GEN']],
        );

        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'TRN285', 'specification_market' => 'AFR'])['status']);
    }

    public function test_compatibilite_par_numero_oem_restreinte(): void
    {
        $part = $this->part();
        $part->fitments()->delete();
        $oem = OemNumber::create(['number' => '90915-TEST1']);
        $part->oemNumbers()->attach($oem->id, ['is_primary' => true]);
        $oem->fitments()->create([
            'vehicle_model_id' => $this->model->id, 'chassis_code' => 'TRN285', 'chassis_restricted' => true,
        ]);
        $part = $part->fresh();

        $this->assertSame('confirmed', $this->assess($part, ['chassis_code' => 'TRN285'])['status']);
        $this->assertSame('candidate', $this->assess($part)['status']);
        $this->assertSame('unknown', $this->assess($part, ['chassis_code' => 'TRN280'])['status']);
    }

    // ── API publique ───────────────────────────────────────────────────

    private function url(array $extra = []): string
    {
        return '/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $this->model->id, 'year' => 2010, ...$extra,
        ]);
    }

    public function test_api_publique_confirme_exclut_et_signale_les_manquants(): void
    {
        $part = $this->part([
            'chassis_code' => 'TRN280/TRN285', 'chassis_restricted' => true, 'market_codes' => ['USA'],
            'year_from' => 2009, 'month_from' => 11, 'year_to' => 2011, 'month_to' => 8,
        ]);

        $this->getJson($this->url(['chassis_code' => 'trn285', 'specification_market' => 'USA']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');

        // Sans châssis ni marché : rien dans les confirmations…
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
        // …mais la pièce reste proposée à vérifier.
        $this->getJson($this->url(['include_candidates' => 1]))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.compatibility_assessment.status', 'candidate');

        // Châssis incompatible : exclue même des candidats.
        $this->getJson($this->url(['chassis_code' => 'GRN285', 'include_candidates' => 1]))
            ->assertOk()->assertJsonCount(0, 'data');
        // Marché incompatible : exclue.
        $this->getJson($this->url(['specification_market' => 'EUR', 'include_candidates' => 1]))
            ->assertOk()->assertJsonCount(0, 'data');
        // Mois hors borne : exclue ; année-borne sans mois : candidate.
        $this->getJson($this->url(['year' => 2009, 'manufacturing_month' => 10, 'include_candidates' => 1]))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url(['year' => 2009, 'chassis_code' => 'TRN285', 'specification_market' => 'USA', 'include_candidates' => 1]))
            ->assertOk()->assertJsonPath('data.0.compatibility_assessment.missing_criteria', ['manufacturing_month']);
    }

    public function test_api_publique_valide_le_mois(): void
    {
        $this->getJson($this->url(['manufacturing_month' => 13]))->assertUnprocessable();
    }

    // ── Garage ─────────────────────────────────────────────────────────

    public function test_garage_lit_le_chassis_sur_le_numero_japonais(): void
    {
        $part = $this->part(['chassis_code' => 'NZE121', 'chassis_restricted' => true]);
        $owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'chassis-test']);
        OwnedVehicle::create([
            'user_id' => $owner->id, 'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'manufacturing_year' => 2010, 'vin' => 'NZE121-3123456',
        ]);
        OwnedVehicle::create([
            'user_id' => $owner->id, 'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'manufacturing_year' => 2010, 'vin' => 'JTDBR32E720123456',
        ]);

        $data = $this->actingAsClient($owner)
            ->getJson("/api/my/parts/{$part->id}/garage-compatibility")
            ->assertOk()->json('data');

        $this->assertSame('confirmed', $data[0]['status']);
        $this->assertSame('candidate', $data[1]['status']);
        $this->assertSame(['chassis_code'], $data[1]['missing_criteria']);
    }

    public function test_garage_enregistre_chassis_marche_et_mois_puis_confirme(): void
    {
        $part = $this->part([
            'chassis_code' => 'TRN285', 'chassis_restricted' => true, 'market_codes' => ['USA'],
            'year_from' => 2009, 'month_from' => 11,
        ]);
        $owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'chassis-test-2']);

        $id = $this->actingAsClient($owner)->postJson('/api/my/vehicles', [
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'manufacturing_year' => 2009, 'manufacturing_month' => 12,
            'chassis_code' => ' trn285 ', 'specification_market' => 'usa',
        ])->assertCreated()
            ->assertJsonPath('data.chassis_code', 'TRN285')
            ->assertJsonPath('data.manufacturing_month', 12)
            ->json('data.id');

        $this->actingAsClient($owner)->getJson("/api/my/vehicles/{$id}/compatible-parts")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $part->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');

        // Mois hors borne : plus rien, même à vérifier.
        $this->actingAsClient($owner)->patchJson("/api/my/vehicles/{$id}", ['manufacturing_month' => 10])->assertOk();
        $this->actingAsClient($owner)->getJson("/api/my/vehicles/{$id}/compatible-parts?include_candidates=1")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsClient($owner)->patchJson("/api/my/vehicles/{$id}", ['manufacturing_month' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('manufacturing_month');
    }

    // ── Écriture ───────────────────────────────────────────────────────

    private function corps(array $fitments): array
    {
        return [
            'sku' => 'CM-'.bin2hex(random_bytes(4)), 'name' => 'Pièce CM',
            'part_category_id' => PartCategory::firstOrCreate(['slug' => 'categorie-cm'], ['name' => 'Catégorie CM'])->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000,
            'fitments' => $fitments,
        ];
    }

    public function test_un_editeur_qui_ignore_les_nouvelles_cles_ne_les_efface_pas(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $ligne = [
            'vehicle_model_id' => $this->model->id, 'engine_code' => '2TR-FE', 'chassis_code' => 'TRN285',
            'year_from' => 2009, 'year_to' => 2011,
        ];

        $id = $this->postJson('/api/parts', $this->corps([$ligne + [
            'chassis_restricted' => true, 'market_codes' => ['us'], 'month_from' => 11, 'month_to' => 8,
        ]]))->assertCreated()->json('data.id');

        $lue = $this->getJson("/api/parts/{$id}")->assertOk()->json('data.fitments.0');
        $this->assertTrue($lue['chassis_restricted']);
        $this->assertSame(['USA'], $lue['market_codes']);

        // Application installée : renvoie la ligne sans les nouvelles clés.
        $this->putJson("/api/parts/{$id}", $this->corps([$ligne]))->assertOk();
        $relue = DB::table('part_fitments')->where('part_id', $id)->first();
        $this->assertTrue((bool) $relue->chassis_restricted);
        $this->assertSame(['USA'], json_decode($relue->market_codes, true));
        $this->assertSame(11, (int) $relue->month_from);
        $this->assertSame(8, (int) $relue->month_to);

        // Clé présente et vide : le personnel peut lever la restriction.
        $this->putJson("/api/parts/{$id}", $this->corps([$ligne + ['market_codes' => [], 'chassis_restricted' => false]]))->assertOk();
        $levee = DB::table('part_fitments')->where('part_id', $id)->first();
        $this->assertFalse((bool) $levee->chassis_restricted);
        $this->assertNull($levee->market_codes);
    }

    public function test_validation_des_criteres_restrictifs(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $base = ['vehicle_model_id' => $this->model->id];

        $this->postJson('/api/parts', $this->corps([$base + ['chassis_restricted' => true]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fitments.0.chassis_code');
        $this->postJson('/api/parts', $this->corps([$base + ['market_codes' => ['XYZ']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fitments.0.market_codes.0');
        $this->postJson('/api/parts', $this->corps([$base + ['month_from' => 5]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fitments.0.month_from');
        $this->postJson('/api/parts', $this->corps([$base + ['year_from' => 2009, 'month_from' => 13]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fitments.0.month_from');
    }

    // ── Référentiels ───────────────────────────────────────────────────

    public function test_la_table_des_marches_reprend_le_referentiel(): void
    {
        $table = DB::table('markets')->orderBy('sort')->get()->keyBy('code');

        $this->assertEqualsCanonicalizing(Market::codes(), $table->keys()->all());
        foreach (Market::ALL as $code => $marche) {
            $this->assertSame($marche['epc_region'], $table[$code]->epc_region);
            $this->assertSame($marche['parent'], $table[$code]->parent_code);
        }
    }

    public function test_la_migration_est_rejouable(): void
    {
        $migration = require database_path('migrations/2026_11_26_200000_ajouter_les_criteres_chassis_marche_et_mois_aux_compatibilites.php');
        $migration->up();

        $this->assertSame(count(Market::ALL), DB::table('markets')->count());
    }

    public function test_remplacement_distributeur_distinct_de_la_supersession_constructeur(): void
    {
        $ancien = OemNumber::create(['number' => '90915-20001']);
        $officiel = OemNumber::create(['number' => '90915-TESTA']);
        $distributeur = OemNumber::create(['number' => '90915-TESTB']);

        OemNumberRelation::create([
            'from_oem_number_id' => $ancien->id, 'to_oem_number_id' => $distributeur->id,
            'kind' => OemNumberRelation::KIND_DISTRIBUTOR_REPLACEMENT, 'evidence_level' => 'dealer_catalog',
        ]);
        $this->assertSame($ancien->id, $ancien->currentManufacturerReference()->id);

        OemNumberRelation::create([
            'from_oem_number_id' => $ancien->id, 'to_oem_number_id' => $officiel->id,
            'kind' => OemNumberRelation::KIND_MANUFACTURER_SUPERSESSION, 'evidence_level' => 'manufacturer_official',
        ]);
        $this->assertSame($officiel->id, $ancien->fresh()->currentManufacturerReference()->id);

        $this->expectException(\InvalidArgumentException::class);
        OemNumberRelation::create([
            'from_oem_number_id' => $distributeur->id, 'to_oem_number_id' => $officiel->id,
            'kind' => OemNumberRelation::KIND_DISTRIBUTOR_REPLACEMENT, 'evidence_level' => 'manufacturer_official',
        ]);
    }
}
