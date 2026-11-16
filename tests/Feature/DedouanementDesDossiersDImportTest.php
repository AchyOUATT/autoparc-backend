<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tous les dossiers d'import existants passent a dedouane.
 *
 * La migration ne cree aucune ligne : un vehicule sans dossier d'import
 * reste sans statut de dedouanement. Elle est idempotente, et le down
 * ne remet rien a false.
 */
class DedouanementDesDossiersDImportTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_16_222800_marquer_les_dossiers_d_import_comme_dedouanes.php');
    }

    public function test_tous_les_dossiers_d_import_existants_passent_a_dedouane(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $aCorriger = Vehicle::factory()->imported()->create();
        $dejaDedouane = Vehicle::factory()->imported()->create();
        $sansDossier = Vehicle::factory()->imported()->create();

        DB::table('vehicle_import_details')->insert([
            [
                'vehicle_id' => $aCorriger->id,
                'customs_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'vehicle_id' => $dejaDedouane->id,
                'customs_cleared' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->migration()->up();

        $this->assertTrue($this->dedouane($aCorriger->id));
        $this->assertTrue($this->dedouane($dejaDedouane->id));
        $this->assertSame(2, DB::table('vehicle_import_details')->count());
        $this->assertDatabaseMissing('vehicle_import_details', ['vehicle_id' => $sansDossier->id]);

        $this->migration()->up();

        $this->assertSame(2, DB::table('vehicle_import_details')->where('customs_cleared', true)->count());
        $this->assertSame(0, DB::table('vehicle_import_details')->where('customs_cleared', false)->count());
        $this->assertDatabaseMissing('vehicle_import_details', ['vehicle_id' => $sansDossier->id]);

        $this->migration()->down();

        $this->assertTrue($this->dedouane($aCorriger->id));
        $this->assertTrue($this->dedouane($dejaDedouane->id));

        $nouveau = Vehicle::factory()->imported()->create();
        $id = DB::table('vehicle_import_details')->insertGetId([
            'vehicle_id' => $nouveau->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue((bool) DB::table('vehicle_import_details')->where('id', $id)->value('customs_cleared'));
    }

    private function dedouane(int $vehicleId): bool
    {
        return (bool) DB::table('vehicle_import_details')->where('vehicle_id', $vehicleId)->value('customs_cleared');
    }
}
