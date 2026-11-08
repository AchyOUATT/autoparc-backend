<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les compatibilites candidates du 90915-10001 restent des propositions :
 * la commande d'audit les lit sans rien ecrire, et le seeder ne les publie
 * pas tout en conservant les compatibilites deja en base.
 */
class AuditFitmentCandidatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
            FiltresHuileBoutiqueSeeder::class,
        ]);
    }

    private function audit(array $args = []): array
    {
        $code = Artisan::call('catalog:audit-fitment-candidates', $args + ['--json' => true]);
        $this->assertSame(0, $code, Artisan::output());

        return json_decode(Artisan::output(), true);
    }

    public function test_the_audit_is_read_only_and_publishes_nothing(): void
    {
        $fitmentsBefore = PartFitment::count();
        $partsBefore = Part::withTrashed()->count();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $results = $this->audit(['sku' => '90915-10001']);

        $this->assertSame([], $writes, 'La commande ne doit rien ecrire.');
        $this->assertSame($fitmentsBefore, PartFitment::count());
        $this->assertSame($partsBefore, Part::withTrashed()->count());
        $this->assertCount(0, Part::where('sku', '90915-10001')->firstOrFail()->fitments);

        $this->assertCount(1, $results);
        $r = $results[0];
        $this->assertSame('90915-10001', $r['sku']);
        $this->assertSame('a_verifier', $r['status']);
        $this->assertFalse($r['certified']);
        $this->assertTrue($r['part_exists']);
        $this->assertSame(0, $r['existing_fitments']);
        $this->assertCount(11, $r['candidates']);
        foreach ($r['candidates'] as $c) {
            $this->assertFalse($c['verified']);
        }

        // Trace lisible dans la CI : resultat de l'audit sur la base de test.
        fwrite(STDERR, "\n[audit 90915-10001] ".json_encode([
            'connus' => collect($r['candidates'])->where('model_exists', true)->pluck('slug')->unique()->values(),
            'absents' => $r['missing_models'],
            'ambigus' => collect($r['candidates'])->where('model_ambiguous', true)->pluck('slug')->unique()->values(),
        ], JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_slugs_are_resolved_within_the_declared_brand_only(): void
    {
        $other = \App\Models\Brand::create(['name' => 'Autre Marque Test', 'slug' => 'autre-marque-test']);
        VehicleModel::create(['brand_id' => $other->id, 'name' => 'Faux', 'slug' => 'camry-xv10', 'generation' => 'X']);
        $toyota = \App\Models\Brand::where('slug', 'toyota')->firstOrFail();
        $toyotaHas = VehicleModel::where('brand_id', $toyota->id)->where('slug', 'camry-xv10')->exists();

        $r = $this->audit(['sku' => '90915-10001'])[0];
        $c = collect($r['candidates'])->firstWhere('slug', 'camry-xv10');

        $this->assertSame($toyotaHas, $c['model_exists']);
        $this->assertFalse($c['model_ambiguous']);
    }

    public function test_an_unsafe_or_unknown_sku_is_rejected(): void
    {
        $this->assertSame(1, Artisan::call('catalog:audit-fitment-candidates', ['sku' => '../../.env']));
        $this->assertSame(1, Artisan::call('catalog:audit-fitment-candidates', ['sku' => 'INCONNU-000']));
    }

    public function test_the_seeder_keeps_existing_fitments_of_a_reference_awaiting_verification(): void
    {
        $piece = Part::where('sku', '90915-10001')->firstOrFail();
        $modele = VehicleModel::firstOrFail();
        $piece->fitments()->create(['vehicle_model_id' => $modele->id, 'year_from' => 2000, 'year_to' => 2001]);

        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $this->assertSame(1, $piece->fitments()->count(), 'Le seeder ne doit pas effacer les compatibilites existantes.');
        $this->assertDatabaseMissing('part_fitments', ['part_id' => $piece->id, 'engine_code' => '1ZZ-FE']);
    }
}
