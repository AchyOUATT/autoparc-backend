<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Part;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Database\Seeders\ManufacturersSeeder;
use Database\Seeders\PartCategoriesSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Toyota10001ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PartCategoriesSeeder::class,
            ManufacturersSeeder::class,
            BrandsSeeder::class,
            VehicleModelsSeeder::class,
            FiltresHuileBoutiqueSeeder::class,
        ]);
    }

    public function test_unverified_candidates_do_not_create_confirmed_compatibilities(): void
    {
        $part = Part::where('sku', '90915-10001')->firstOrFail();

        $this->assertSame(0, $part->fitments()->count());

        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $this->assertSame(0, $part->fitments()->count());
    }

    public function test_reseeding_preserves_existing_shop_declarations_and_commercial_values(): void
    {
        $this->freezeTime();

        $part = Part::where('sku', '90915-10001')->firstOrFail();
        $model = VehicleModel::where('slug', 'corolla-e140')->firstOrFail();
        $part->update(['selling_price' => 4750, 'stock_quantity' => 8]);
        $part->fitments()->create([
            'vehicle_model_id' => $model->id,
            'engine_code' => '1ZZ-FE',
            'year_from' => 2006,
            'year_to' => 2008,
            'notes' => 'Déclaration atelier de test, indépendante du lot documentaire.',
            'source' => FitmentSource::Declared,
        ]);

        $before = $this->fitmentsSnapshot($part);
        $this->travel(2)->days();

        $this->seed(FiltresHuileBoutiqueSeeder::class);

        // Keep the declaration itself, including its ID, author note and dates;
        // deleting and recreating a similar row would lose that history.
        $this->assertSame($before, $this->fitmentsSnapshot($part));
        $this->assertSame(4750.0, (float) $part->fresh()->selling_price);
        $this->assertSame(8, $part->fresh()->stock_quantity);
    }

    public function test_other_filter_references_still_refresh_their_catalogue_compatibilities(): void
    {
        $part = Part::where('sku', '90915-YZZN1')->firstOrFail();
        $fitment = $part->fitments()->firstOrFail();
        $baselineCount = $part->fitments()->count();
        $baselineNote = $fitment->notes;
        $fitment->update(['notes' => 'Ancienne note à remplacer par le catalogue.']);
        $part->fitments()->create([
            'vehicle_model_id' => $fitment->vehicle_model_id,
            'engine_code' => 'TEST-ATELIER',
            'year_from' => 2000,
            'year_to' => 2001,
            'notes' => 'Ligne de test hors du catalogue de cette référence.',
            'source' => FitmentSource::Declared,
        ]);

        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $this->assertSame($baselineCount, $part->fitments()->count());
        $this->assertFalse($part->fitments()->where('engine_code', 'TEST-ATELIER')->exists());
        $this->assertTrue($part->fitments()->where('notes', $baselineNote)->exists());
        $this->assertFalse($part->fitments()->where('notes', 'Ancienne note à remplacer par le catalogue.')->exists());
    }

    private function fitmentsSnapshot(Part $part): array
    {
        return DB::table('part_fitments')->where('part_id', $part->id)
            ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
