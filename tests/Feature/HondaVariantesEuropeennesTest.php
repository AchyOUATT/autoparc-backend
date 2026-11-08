<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\VehicleModel;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Variantes europeennes Honda ajoutees a cote des lignes existantes :
 * presentes, idempotentes, et sans toucher aux autres Honda ni a leurs liens.
 */
class HondaVariantesEuropeennesTest extends TestCase
{
    use RefreshDatabase;

    private const ATTENDUES = [
        // slug => [name, generation, body_type, segment, debut, fin]
        'civic-e-hev-fl4' => ['Civic e:HEV', 'FL4', 'compacte', 'C', 2022, null],
        'civic-fn' => ['Civic', 'FN', 'compacte', 'C', 2007, 2011],
        'civic-hybrid-fd3' => ['Civic Hybrid', 'FD3', 'berline', 'C', 2006, 2011],
        'zr-v-e-hev-rz4' => ['ZR-V e:HEV', 'RZ4', 'suv', 'C', 2023, null],
    ];

    private function semer(): void
    {
        Artisan::call('db:seed', ['--class' => VehicleModelsSeeder::class, '--force' => true]);
    }

    private function honda(): Brand
    {
        return Brand::where('slug', 'honda')->firstOrFail();
    }

    public function test_les_variantes_europeennes_sont_presentes(): void
    {
        $this->seed(BrandsSeeder::class);
        $this->semer();

        foreach (self::ATTENDUES as $slug => [$nom, $gen, $carrosserie, $segment, $debut, $fin]) {
            $m = VehicleModel::where('brand_id', $this->honda()->id)->where('slug', $slug)->first();
            $this->assertNotNull($m, "{$slug} absent");
            $this->assertSame($nom, $m->name);
            $this->assertSame($gen, $m->generation);
            $this->assertSame($carrosserie, $m->body_type);
            $this->assertSame($segment, $m->segment);
            $this->assertSame($debut, (int) $m->production_start);
            $this->assertSame($fin, $m->production_end === null ? null : (int) $m->production_end);
            $this->assertTrue((bool) $m->is_active);
        }
    }

    public function test_rejouer_est_idempotent_et_preserve_les_honda_existantes_et_leurs_liens(): void
    {
        $this->seed(BrandsSeeder::class);
        $this->semer();

        $honda = $this->honda();
        $avant = VehicleModel::where('brand_id', $honda->id)
            ->whereNotIn('slug', array_keys(self::ATTENDUES))
            ->orderBy('id')->get(['id', 'slug', 'name', 'generation', 'body_type', 'production_start', 'production_end'])
            ->toArray();
        $this->assertNotEmpty($avant);

        $civicFc = VehicleModel::where('brand_id', $honda->id)->where('slug', 'civic-fc')->firstOrFail();
        $piece = Part::factory()->create();
        $fitment = $piece->fitments()->create(['vehicle_model_id' => $civicFc->id, 'year_from' => 2016, 'year_to' => 2020]);

        $total = VehicleModel::count();
        $this->semer();
        $this->semer();

        $this->assertSame($total, VehicleModel::count(), 'Rejouer le seeder ne doit rien ajouter.');
        $this->assertSame($avant, VehicleModel::where('brand_id', $honda->id)
            ->whereNotIn('slug', array_keys(self::ATTENDUES))
            ->orderBy('id')->get(['id', 'slug', 'name', 'generation', 'body_type', 'production_start', 'production_end'])
            ->toArray(), 'Les Honda existantes ne doivent pas changer.');
        $this->assertDatabaseHas('part_fitments', ['id' => $fitment->id, 'vehicle_model_id' => $civicFc->id]);
    }
}
