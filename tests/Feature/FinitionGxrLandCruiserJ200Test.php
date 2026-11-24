<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Trim;
use App\Models\VehicleModel;
use App\Support\LandCruiserJ200GxrTrimImport;
use Database\Seeders\BrandsSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\DrivetrainsSeeder;
use Database\Seeders\EngineTypesSeeder;
use Database\Seeders\TrimsSeeder;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinitionGxrLandCruiserJ200Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{model: VehicleModel, gx: Trim, vx: Trim, vxr: Trim}
     */
    private function gammeInitiale(): array
    {
        $toyota = Brand::query()->firstOrCreate(
            ['slug' => 'toyota'],
            ['name' => 'Toyota', 'is_active' => true],
        );

        EngineType::query()->firstOrCreate(
            ['code' => 'petrol'],
            ['label' => 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
        );
        EngineType::query()->firstOrCreate(
            ['code' => 'diesel'],
            ['label' => 'Diesel', 'uses_fuel' => true, 'uses_battery' => false],
        );
        Drivetrain::query()->firstOrCreate(['code' => '4WD'], ['label' => '4×4 (4WD)']);

        $modele = VehicleModel::query()->firstOrCreate(
            ['brand_id' => $toyota->id, 'slug' => LandCruiserJ200GxrTrimImport::MODEL_SLUG],
            [
                'name' => 'Land Cruiser',
                'generation' => 'J200',
                'body_type' => 'suv',
                'is_active' => true,
            ],
        );

        $diesel = EngineType::query()->where('code', 'diesel')->value('id');
        $essence = EngineType::query()->where('code', 'petrol')->value('id');
        $quatreRoues = Drivetrain::query()->where('code', '4WD')->value('id');

        $gx = Trim::query()->create([
            'vehicle_model_id' => $modele->id,
            'name' => 'GX',
            'code' => 'GX',
            'rank' => 1,
            'default_engine_type_id' => $diesel,
            'default_drivetrain_id' => $quatreRoues,
        ]);
        $vx = Trim::query()->create([
            'vehicle_model_id' => $modele->id,
            'name' => 'VX',
            'code' => 'VX',
            'rank' => 2,
            'default_engine_type_id' => $diesel,
            'default_drivetrain_id' => $quatreRoues,
        ]);
        $vxr = Trim::query()->create([
            'vehicle_model_id' => $modele->id,
            'name' => 'VX-R',
            'code' => 'VXR',
            'rank' => 3,
            'default_engine_type_id' => $essence,
            'default_drivetrain_id' => $quatreRoues,
        ]);

        return ['model' => $modele, 'gx' => $gx, 'vx' => $vx, 'vxr' => $vxr];
    }

    /** @return list<string> */
    private function nomsParRang(int $modeleId): array
    {
        return Trim::query()
            ->where('vehicle_model_id', $modeleId)
            ->orderBy('rank')
            ->orderBy('id')
            ->pluck('name')
            ->all();
    }

    public function test_elle_est_inerte_sans_le_modele(): void
    {
        $rapport = LandCruiserJ200GxrTrimImport::run();

        $this->assertNull($rapport['model_id']);
        $this->assertNull($rapport['trim_id']);
        $this->assertFalse($rapport['created']);
        $this->assertFalse($rapport['ranks_shifted']);
        $this->assertSame(0, Trim::query()->count());
    }

    public function test_elle_cree_la_finition_gxr_avec_ses_defauts(): void
    {
        $this->gammeInitiale();

        $rapport = LandCruiserJ200GxrTrimImport::run();

        $this->assertTrue($rapport['created']);
        $this->assertTrue($rapport['ranks_shifted']);

        $gxr = Trim::query()->findOrFail($rapport['trim_id']);
        $this->assertSame('GX-R', $gxr->name);
        $this->assertSame('GXR', $gxr->code);
        $this->assertSame(2, $gxr->rank);
        $this->assertNull($gxr->default_engine_type_id);
        $this->assertSame(
            (int) Drivetrain::query()->where('code', '4WD')->value('id'),
            (int) $gxr->default_drivetrain_id,
        );
    }

    public function test_l_ordre_des_rangs_est_gx_gxr_vx_vxr(): void
    {
        $initiales = $this->gammeInitiale();

        LandCruiserJ200GxrTrimImport::run();

        $modeleId = $initiales['model']->id;
        $this->assertSame(['GX', 'GX-R', 'VX', 'VX-R'], $this->nomsParRang($modeleId));

        $rangs = Trim::query()
            ->where('vehicle_model_id', $modeleId)
            ->orderBy('rank')
            ->pluck('rank')
            ->map(fn ($r) => (int) $r)
            ->all();
        $this->assertSame([1, 2, 3, 4], $rangs);

        // Les véhicules déjà enregistrés sur VX ou VX-R gardent le même id.
        $this->assertSame(1, $initiales['gx']->fresh()->rank);
        $this->assertSame($initiales['gx']->id, $initiales['gx']->fresh()->id);
        $this->assertSame($initiales['vx']->id, Trim::query()->where('name', 'VX')->where('vehicle_model_id', $modeleId)->value('id'));
        $this->assertSame($initiales['vxr']->id, Trim::query()->where('name', 'VX-R')->where('vehicle_model_id', $modeleId)->value('id'));
        $this->assertSame(3, $initiales['vx']->fresh()->rank);
        $this->assertSame(4, $initiales['vxr']->fresh()->rank);

        // Les défauts déjà posés sur GX, VX et VX-R ne sont pas réécrits.
        $this->assertSame(
            (int) EngineType::query()->where('code', 'diesel')->value('id'),
            (int) $initiales['gx']->fresh()->default_engine_type_id,
        );
        $this->assertSame(
            (int) EngineType::query()->where('code', 'diesel')->value('id'),
            (int) $initiales['vx']->fresh()->default_engine_type_id,
        );
        $this->assertSame(
            (int) EngineType::query()->where('code', 'petrol')->value('id'),
            (int) $initiales['vxr']->fresh()->default_engine_type_id,
        );
    }

    public function test_le_rejeu_est_idempotent(): void
    {
        $this->gammeInitiale();

        $premier = LandCruiserJ200GxrTrimImport::run();
        $avant = Trim::query()->orderBy('id')->get(['id', 'name', 'code', 'rank', 'default_engine_type_id', 'default_drivetrain_id'])->toArray();

        $second = LandCruiserJ200GxrTrimImport::run();

        $this->assertFalse($second['created']);
        $this->assertFalse($second['ranks_shifted']);
        $this->assertSame($premier['trim_id'], $second['trim_id']);
        $this->assertSame(4, Trim::query()->count());
        $this->assertSame(
            $avant,
            Trim::query()->orderBy('id')->get(['id', 'name', 'code', 'rank', 'default_engine_type_id', 'default_drivetrain_id'])->toArray(),
        );
    }

    public function test_le_seeder_pose_la_meme_gamme_sur_une_installation_neuve(): void
    {
        Artisan::call('db:seed', ['--class' => CountriesSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => BrandsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => EngineTypesSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => DrivetrainsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => VehicleModelsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => TrimsSeeder::class, '--force' => true]);

        $modeleId = (int) DB::table('vehicle_models')
            ->where('slug', LandCruiserJ200GxrTrimImport::MODEL_SLUG)
            ->value('id');
        $this->assertNotSame(0, $modeleId);

        $this->assertSame(['GX', 'GX-R', 'VX', 'VX-R'], $this->nomsParRang($modeleId));

        $gxr = Trim::query()
            ->where('vehicle_model_id', $modeleId)
            ->where('name', 'GX-R')
            ->sole();
        $this->assertSame('GXR', $gxr->code);
        $this->assertSame(2, $gxr->rank);
        $this->assertNull($gxr->default_engine_type_id);
        $this->assertSame(
            (int) Drivetrain::query()->where('code', '4WD')->value('id'),
            (int) $gxr->default_drivetrain_id,
        );

        $avant = Trim::query()->where('vehicle_model_id', $modeleId)->orderBy('id')->get(['id', 'name', 'code', 'rank', 'default_engine_type_id', 'default_drivetrain_id'])->toArray();
        Artisan::call('db:seed', ['--class' => TrimsSeeder::class, '--force' => true]);
        $this->assertSame(
            $avant,
            Trim::query()->where('vehicle_model_id', $modeleId)->orderBy('id')->get(['id', 'name', 'code', 'rank', 'default_engine_type_id', 'default_drivetrain_id'])->toArray(),
            'Le rejeu du seeder ne doit ni dupliquer le GX-R ni réécrire les rangs déjà posés.',
        );
    }
}
