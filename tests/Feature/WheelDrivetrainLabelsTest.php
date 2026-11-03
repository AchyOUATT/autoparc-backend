<?php

namespace Tests\Feature;

use App\Models\Drivetrain;
use App\Models\Vehicle;
use Database\Seeders\DrivetrainsSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WheelDrivetrainLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_03_000001_clarifier_les_roues_motrices.php');
    }

    public static function oldLabels(): array
    {
        return [
            ['RWD', 'Propulsion', 'Propulsion arrière'],
            ['AWD', 'Transmission intégrale permanente', 'Intégrale (AWD)'],
            ['AWD', 'Intégrale permanente', 'Intégrale (AWD)'],
            ['4WD', '4x4 débrayable', '4×4 (4WD)'],
            ['4WD', '4x4 enclenchable', '4×4 (4WD)'],
        ];
    }

    #[DataProvider('oldLabels')]
    public function test_la_migration_corrige_le_libelle_sans_changer_son_identite_et_l_expose_dans_le_delta(string $code, string $oldLabel, string $newLabel): void
    {
        $this->travelTo(now()->startOfSecond());
        $drive = Drivetrain::create(['code' => $code, 'label' => $oldLabel]);
        $createdAt = $drive->created_at->toDateTimeString();
        $cursor = $this->getJson('/api/catalog/sync')->assertOk()->json('server_time');
        $this->travel(3)->seconds();

        $this->migration()->up();

        $this->assertDatabaseHas('drivetrains', [
            'id' => $drive->id, 'code' => $code, 'label' => $newLabel, 'created_at' => $createdAt,
        ]);
        $this->getJson('/api/catalog/references')->assertOk()
            ->assertJsonPath('drivetrains.0.id', $drive->id)
            ->assertJsonPath('drivetrains.0.code', $code)
            ->assertJsonPath('drivetrains.0.label', $newLabel);
        $this->getJson('/api/catalog/sync?'.http_build_query(['since' => $cursor]))->assertOk()
            ->assertJsonCount(1, 'drivetrains')
            ->assertJsonPath('drivetrains.0.id', $drive->id)
            ->assertJsonPath('drivetrains.0.label', $newLabel);
    }

    public function test_migration_et_reseed_preservent_les_relations_et_les_libelles_personnalises(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $awd = Drivetrain::where('code', 'AWD')->firstOrFail();
        $fourWd = Drivetrain::where('code', '4WD')->firstOrFail();
        $awd->update(['label' => 'Transmission intégrale permanente']);
        $fourWd->update(['label' => 'Mon 4x4 documenté']);
        $vehicle = Vehicle::factory()->create(['drivetrain_id' => $awd->id]);
        DB::table('trims')->where('id', $vehicle->trim_id)->update(['default_drivetrain_id' => $awd->id]);
        $customUpdatedAt = $fourWd->fresh()->updated_at->toDateTimeString();

        $this->migration()->up();
        $this->seed(DrivetrainsSeeder::class);
        $this->migration()->down();

        $this->assertSame($awd->id, $vehicle->fresh()->drivetrain_id);
        $this->assertEquals($awd->id, DB::table('trims')->where('id', $vehicle->trim_id)->value('default_drivetrain_id'));
        $this->assertDatabaseHas('drivetrains', ['id' => $awd->id, 'label' => 'Intégrale (AWD)']);
        $this->assertDatabaseHas('drivetrains', [
            'id' => $fourWd->id, 'label' => 'Mon 4x4 documenté', 'updated_at' => $customUpdatedAt,
        ]);
        $this->assertDatabaseCount('drivetrains', 4);
    }

    public function test_le_seeder_commun_expose_quatre_choix_et_ne_republie_pas_des_references_identiques(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->seed(DrivetrainsSeeder::class);
        $this->assertSame([
            '4WD' => '4×4 (4WD)', 'AWD' => 'Intégrale (AWD)',
            'FWD' => 'Traction avant', 'RWD' => 'Propulsion arrière',
        ], Drivetrain::orderBy('code')->pluck('label', 'code')->all());
        $this->assertSame(0, DB::table('drivetrains')->whereNull('updated_at')->count());
        $this->travel(3)->seconds();
        $this->seed(DrivetrainsSeeder::class);
        $this->getJson('/api/catalog/sync?'.http_build_query(['since' => now()->subSecond()->toIso8601String()]))
            ->assertOk()->assertJsonCount(0, 'drivetrains');
    }

    public function test_la_migration_repare_l_horodatage_standard_absent_sans_creer_de_references(): void
    {
        $id = DB::table('drivetrains')->insertGetId(['code' => 'FWD', 'label' => 'Traction avant']);
        $this->migration()->up();
        $this->assertNotNull(DB::table('drivetrains')->where('id', $id)->value('updated_at'));
        $this->assertDatabaseCount('drivetrains', 1);
    }

    public function test_un_libelle_personnalise_n_est_pas_assimile_a_un_ancien_standard_par_la_collation(): void
    {
        DB::table('drivetrains')->insert([
            ['code' => 'AWD', 'label' => 'INTÉGRALE PERMANENTE'],
            ['code' => '4WD', 'label' => '4X4 DÉBRAYABLE'],
        ]);
        $before = DB::table('drivetrains')->orderBy('id')->get()->toArray();
        $this->migration()->up();
        $this->seed(DrivetrainsSeeder::class);

        $this->assertEquals($before, DB::table('drivetrains')->whereIn('code', ['AWD', '4WD'])->orderBy('id')->get()->toArray());
    }
}
