<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CX-5 KE et CX-50 VA : les cotes NRCan qui restaient sans modele.
 *
 * Production au 23/11/2026 : 36 cotes CX-5 de 2013 a 2016 et 20 cotes CX-50
 * de 2023 a 2026 sans `vehicle_model_id`, faute de fiche.
 */
class MazdaCx5KeCx50Test extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_23_200000_ajouter_les_mazda_cx5_ke_et_cx50.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            BrandsSeeder::class,
            VehicleModelsSeeder::class,
        ]);
    }

    private function mazda(): int
    {
        return Brand::where('slug', 'mazda')->value('id');
    }

    private function fiche(string $slug): ?VehicleModel
    {
        return VehicleModel::where('brand_id', $this->mazda())->where('slug', $slug)->first();
    }

    private function migrer(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    private function cote(string $nom, int $annee, array $attributs = []): Motorisation
    {
        return Motorisation::create(array_merge([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'market' => 'CA',
            'year_kind' => 'model_year', 'method' => 'unknown',
            'reference_status' => 'documented', 'is_current' => true,
            'model_year' => $annee, 'make_raw' => 'Mazda', 'model_raw' => $nom,
            'engine_l' => 2.5, 'cylinders' => 4, 'transmission_code' => 'AS6',
            'fuel_code' => 'X', 'consumption_combined' => 8.8,
            'brand_id' => $this->mazda(), 'vehicle_model_id' => null,
            'cle_source' => bin2hex(random_bytes(16)),
        ], $attributs));
    }

    public function test_le_referentiel_pose_les_deux_fiches_avec_leurs_bornes(): void
    {
        $ke = $this->fiche('cx-5-ke');
        $this->assertNotNull($ke);
        $this->assertSame(['CX-5', 'KE', 'suv', 'C', 2012, 2016, true],
            [$ke->name, $ke->generation, $ke->body_type, $ke->segment, $ke->production_start, $ke->production_end, (bool) $ke->is_active]);

        $cx50 = $this->fiche('cx-50-va');
        $this->assertNotNull($cx50);
        $this->assertSame(['CX-50', 'VA', 'suv', 'C', 2022, null, true],
            [$cx50->name, $cx50->generation, $cx50->body_type, $cx50->segment, $cx50->production_start, $cx50->production_end, (bool) $cx50->is_active]);

        // La KF n'a pas bougé, et ne chevauche pas la KE.
        $kf = $this->fiche('cx-5-kf');
        $this->assertSame([2017, null], [$kf->production_start, $kf->production_end]);
    }

    public function test_la_migration_pose_les_fiches_et_rattache_les_orphelines_une_seule_fois(): void
    {
        // État de la production avant la migration : ni KE, ni CX-50.
        VehicleModel::whereIn('slug', ['cx-5-ke', 'cx-50-va'])->delete();
        $kf = $this->fiche('cx-5-kf');
        $cx30 = $this->fiche('cx-30-dm');

        $ke = [
            $this->cote('CX-5', 2013),
            $this->cote('CX-5 4WD', 2016),
            $this->cote('CX-5 (SIL)', 2016),
            // Ligne héritée : l'année est lue comme un millésime, comme à l'import.
            $this->cote('CX-5', 2014, ['year_kind' => 'unknown', 'reference_status' => 'legacy', 'market' => null]),
        ];
        $cx50 = [
            $this->cote('CX-50 4WD', 2023),
            $this->cote('CX-50 Hybrid 4WD', 2025),
            $this->cote('CX-50 Turbo 4WD', 2026),
        ];
        $dejaKf = $this->cote('CX-5 4WD', 2017, ['vehicle_model_id' => $kf->id]);
        $dejaCx30 = $this->cote('CX-30', 2020, ['vehicle_model_id' => $cx30->id]);
        $orphelineKf = $this->cote('CX-5', 2017);
        $verifiee = $this->cote('CX-5', 2015, ['verified_at' => now()]);
        $europe = $this->cote('CX-5', 2015, ['source' => 'eea', 'year_kind' => 'registration_year', 'market' => 'EU']);
        $mazda3 = $this->cote('Mazda3', 2006);
        $avant = DB::table('motorisations')->where('id', $ke[0]->id)->value('updated_at');

        $this->migrer();
        $this->migrer();

        $this->assertSame(1, VehicleModel::where('slug', 'cx-5-ke')->count());
        $this->assertSame(1, VehicleModel::where('slug', 'cx-50-va')->count());
        $idKe = $this->fiche('cx-5-ke')->id;
        $idCx50 = $this->fiche('cx-50-va')->id;

        foreach ($ke as $cote) {
            $this->assertSame($idKe, $cote->refresh()->vehicle_model_id, $cote->model_raw.' '.$cote->model_year);
        }
        foreach ($cx50 as $cote) {
            $this->assertSame($idCx50, $cote->refresh()->vehicle_model_id, $cote->model_raw);
        }
        $this->assertSame($kf->id, $dejaKf->refresh()->vehicle_model_id);
        $this->assertSame($cx30->id, $dejaCx30->refresh()->vehicle_model_id);
        // Le millésime 2017 n'est jamais donné à la KE.
        $this->assertNull($orphelineKf->refresh()->vehicle_model_id);
        $this->assertNull($verifiee->refresh()->vehicle_model_id);
        // Une année d'immatriculation ne choisit pas entre KE et KF.
        $this->assertNull($europe->refresh()->vehicle_model_id);
        // Hors du périmètre de ce lot.
        $this->assertNull($mazda3->refresh()->vehicle_model_id);
        $this->assertEquals($avant, DB::table('motorisations')->where('id', $ke[0]->id)->value('updated_at'));
        $this->assertSame(4, Motorisation::where('vehicle_model_id', $idKe)->count());
        $this->assertSame(3, Motorisation::where('vehicle_model_id', $idCx50)->count());
    }

    public function test_un_prochain_import_rattache_ke_kf_et_cx50_et_les_mazda3_au_nom_colle(): void
    {
        $this->artisan('catalog:import-consommations', [
            '--fichier' => [base_path('tests/Fixtures/nrcan-mazda-cx.csv')],
        ])->assertSuccessful();

        $modele = fn (string $nom, int $annee) => Motorisation::where('model_raw', $nom)
            ->where('model_year', $annee)->sole()->vehicle_model_id;

        $this->assertSame($this->fiche('cx-5-ke')->id, $modele('CX-5', 2015));
        $this->assertSame($this->fiche('cx-5-ke')->id, $modele('CX-5 4WD', 2015));
        $this->assertSame($this->fiche('cx-5-ke')->id, $modele('CX-5 (SIL)', 2016));
        $this->assertSame($this->fiche('cx-5-kf')->id, $modele('CX-5', 2017));
        $this->assertSame($this->fiche('cx-5-kf')->id, $modele('CX-5 4WD', 2017));
        $this->assertSame($this->fiche('cx-50-va')->id, $modele('CX-50 Turbo 4WD', 2025));
        $this->assertSame($this->fiche('cx-50-va')->id, $modele('CX-50 Hybrid 4WD', 2025));

        // « Mazda3 » (source) contre « Mazda 3 » (catalogue) : sans la forme
        // collée, ces trois cotes restaient sans modèle à chaque import.
        $this->assertSame($this->fiche('mazda3-bk')->id, $modele('Mazda3', 2006));
        $this->assertSame($this->fiche('mazda3-bm')->id, $modele('Mazda3 4-Door', 2015));
        $this->assertSame($this->fiche('mazda3-bp')->id, $modele('Mazda3 4-Door', 2019));
    }
}
