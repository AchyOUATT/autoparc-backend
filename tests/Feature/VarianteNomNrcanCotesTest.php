<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Motricité, turbo et désactivation de cylindres lus dans le nom NRCan.
 *
 * Le cas de départ : une annonce CX-5 2019 ne pouvait départager les cinq
 * CX-5 2,5 l automatiques de la source, parce que « 4WD », « Turbo » et
 * « Cylinder Deactivation » n'existaient que dans le nom.
 */
class VarianteNomNrcanCotesTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $cx5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $mazda = Brand::firstOrCreate(['slug' => 'mazda'], ['name' => 'Mazda']);
        $this->cx5 = VehicleModel::create([
            'brand_id' => $mazda->id, 'name' => 'CX-5', 'slug' => 'cx-5-kf', 'generation' => 'KF',
            'production_start' => 2017,
        ]);
    }

    private function importer(): void
    {
        $this->artisan('catalog:import-consommations', [
            '--fichier' => [base_path('tests/Fixtures/nrcan-variantes.csv')],
        ])->assertSuccessful();
    }

    private function cote(string $nom, ?float $litres = null): Motorisation
    {
        return Motorisation::where('model_raw', $nom)
            ->when($litres !== null, fn ($q) => $q->where('engine_l', $litres))
            ->sole();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_23_090000_lire_la_variante_dans_le_nom_des_cotes_nrcan.php');
    }

    public function test_l_import_range_ce_que_le_nom_dit_et_laisse_nul_ce_qu_il_tait(): void
    {
        $this->importer();

        $attendu = [
            ['CX-5', 2.0, null, null, null],
            ['CX-5', 2.5, null, null, null],
            ['CX-5 4WD', null, '4WD', null, null],
            ['CX-5 (Cylinder Deactivation)', null, null, null, true],
            ['CX-5 4WD (Cylinder Deactivation)', null, '4WD', null, true],
            ['CX-5 Turbo 4WD', null, '4WD', 'turbo', null],
            ['X5 xDrive40i', null, 'AWD', null, null],
            ['A4 quattro', null, 'AWD', null, null],
            ['Silverado 4WD (Without DFM)', null, '4WD', null, false],
            ['Ram 1500 4X4 (MDS)', null, '4WD', null, true],
            ['Range Rover 5.0 Supercharged', null, null, 'supercharged', null],
            ['Golf', null, null, null, null],
        ];
        foreach ($attendu as [$nom, $litres, $motricite, $suralimentation, $desactivation]) {
            $cote = $this->cote($nom, $litres);
            $this->assertSame($motricite, $cote->drivetrain_code, $nom);
            $this->assertSame($suralimentation, $cote->aspiration, $nom);
            $this->assertSame($desactivation, $cote->cylinder_deactivation, $nom);
        }
    }

    public function test_le_rattrapage_lit_les_cotes_existantes_sans_ecraser_ni_toucher_l_europe(): void
    {
        $this->importer();
        // État d'avant la migration : colonnes vides.
        DB::table('motorisations')->update([
            'drivetrain_code' => null, 'aspiration' => null, 'cylinder_deactivation' => null,
        ]);
        $turbo = $this->cote('CX-5 Turbo 4WD');
        $avant = DB::table('motorisations')->where('id', $turbo->id)->first(['updated_at', 'cle_source', 'identity_key']);
        // Une valeur déjà posée n'est jamais réécrite.
        DB::table('motorisations')->where('id', $this->cote('A4 quattro')->id)->update(['drivetrain_code' => 'AWD']);
        $europe = Motorisation::create([
            'source' => 'eea', 'cycle' => 'wltp', 'market' => 'EU', 'model_year' => 2019,
            'make_raw' => 'MAZDA', 'model_raw' => 'CX-5 4WD', 'fuel_code' => 'X',
            'consumption_combined' => 7.4, 'cle_source' => 'eea-cx5', 'method' => 'aggregation',
            'year_kind' => 'registration_year', 'reference_status' => 'documented',
        ]);

        $this->migration()->up();
        $this->migration()->up();   // rejouable

        $turbo->refresh();
        $this->assertSame('4WD', $turbo->drivetrain_code);
        $this->assertSame('turbo', $turbo->aspiration);
        $this->assertNull($turbo->cylinder_deactivation);
        $this->assertSame(false, $this->cote('Silverado 4WD (Without DFM)')->cylinder_deactivation);
        $this->assertSame(true, $this->cote('Ram 1500 4X4 (MDS)')->cylinder_deactivation);
        $this->assertNull($this->cote('CX-5', 2.5)->drivetrain_code);
        $this->assertNull($europe->refresh()->drivetrain_code);
        $this->assertEquals($avant, DB::table('motorisations')->where('id', $turbo->id)->first(['updated_at', 'cle_source', 'identity_key']));
    }

    private function annonce(array $attributs = []): Vehicle
    {
        return Vehicle::factory()->create(array_merge([
            'brand_id' => $this->cx5->brand_id,
            'vehicle_model_id' => $this->cx5->id,
            'trim_id' => null,
            'engine_type_id' => EngineType::where('code', 'petrol')->value('id'),
            'drivetrain_id' => null,
            'manufacturing_year' => 2019,
            'transmission' => null,
            'gear_count' => null,
            'engine_displacement_cc' => null,
            'cylinders' => null,
            'power_hp' => null,
            'power_kw' => null,
        ], $attributs));
    }

    private function references(Vehicle $annonce): array
    {
        $response = $this->getJson('/api/catalog/vehicles/'.$annonce->id)->assertOk();

        return collect($response->json('data.consumption.references'))
            ->keyBy(fn ($r) => $r['model_raw'].' '.$r['engine_l'])->all();
    }

    public function test_un_cx5_2019_25_l_automatique_integral_ne_retient_que_les_versions_4wd(): void
    {
        $this->importer();
        $this->assertSame(7, Motorisation::where('vehicle_model_id', $this->cx5->id)->count());

        // Avant : essence seule, six cotes essence à égalité.
        $avant = $this->references($this->annonce());
        $this->assertCount(6, $avant);
        $this->assertCount(6, array_filter($avant, fn ($r) => $r['recommendation']['rank'] === 1));

        $annonce = $this->annonce([
            'transmission' => 'automatic',
            'engine_displacement_cc' => 2488,
            'drivetrain_id' => Drivetrain::where('code', 'AWD')->value('id'),
        ]);
        $apres = $this->references($annonce);

        // La boîte manuelle est exclue ; les 2,5 l au nom muet restent
        // consultables — un nom muet n'est pas une traction.
        $this->assertSame(
            ['CX-5 (Cylinder Deactivation) 2.5', 'CX-5 2.5', 'CX-5 4WD (Cylinder Deactivation) 2.5', 'CX-5 4WD 2.5', 'CX-5 Turbo 4WD 2.5'],
            collect(array_keys($apres))->sort()->values()->all(),
        );
        $premiers = collect($apres)->filter(fn ($r) => $r['recommendation']['rank'] === 1)->keys()->sort()->values()->all();
        $this->assertSame(['CX-5 4WD (Cylinder Deactivation) 2.5', 'CX-5 4WD 2.5', 'CX-5 Turbo 4WD 2.5'], $premiers);
        $this->assertContains('drivetrain', $apres['CX-5 4WD 2.5']['recommendation']['matched_criteria']);
        $this->assertSame('4WD', $apres['CX-5 4WD 2.5']['drivetrain_code']);
        $this->assertSame('turbo', $apres['CX-5 Turbo 4WD 2.5']['aspiration']);
        $this->assertSame('2,5 l 4 cyl. boîte auto. 6 · turbo, 4WD', $apres['CX-5 Turbo 4WD 2.5']['label']);
        $this->assertSame('2,5 l 4 cyl. boîte auto. 6 · 4WD, désactivation de cylindres', $apres['CX-5 4WD (Cylinder Deactivation) 2.5']['label']);
        $this->assertSame('2,5 l 4 cyl. boîte auto. 6', $apres['CX-5 2.5']['label']);
    }

    public function test_une_traction_declaree_exclut_les_versions_4wd_mais_pas_les_noms_muets(): void
    {
        $this->importer();
        $annonce = $this->annonce([
            'transmission' => 'automatic',
            'drivetrain_id' => Drivetrain::where('code', 'FWD')->value('id'),
        ]);

        $this->assertSame(
            ['CX-5 (Cylinder Deactivation) 2.5', 'CX-5 2.5'],
            collect(array_keys($this->references($annonce)))->sort()->values()->all(),
        );
    }

    public function test_la_recherche_du_garage_compare_aussi_la_motricite_lue_dans_le_nom(): void
    {
        $this->importer();
        $query = http_build_query([
            'vehicle_model_id' => $this->cx5->id,
            'engine_type_id' => EngineType::where('code', 'petrol')->value('id'),
            'drivetrain_id' => Drivetrain::where('code', 'FWD')->value('id'),
        ]);

        $noms = collect($this->getJson('/api/catalog/motorisations?'.$query)->assertOk()->json('data'))
            ->pluck('model_raw')->sort()->values()->all();
        $this->assertSame(['CX-5', 'CX-5', 'CX-5 (Cylinder Deactivation)'], $noms);
    }
}
