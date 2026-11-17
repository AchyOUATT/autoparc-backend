<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tous les vehicules routiers exposent un statut de dedouanement.
 *
 * Sans dossier d'import, l'API rend `customs_cleared: true` : on n'invente
 * ni pays, ni compteur, ni ligne dans `vehicle_import_details`. Un dossier
 * existant continue de porter sa propre valeur.
 */
class DedouanementAfficheParDefautTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);
    }

    public function test_un_vehicule_sans_dossier_d_import_est_expose_comme_dedouane(): void
    {
        $immatricule = Vehicle::factory()->registered()->create();
        $importe = Vehicle::factory()->imported()->create();

        $this->assertNull($immatricule->importDetail);
        $this->assertNull($importe->importDetail);

        $this->getJson('/api/catalog/vehicles/'.$immatricule->id)
            ->assertOk()
            ->assertJsonPath('data.customs_cleared', true)
            ->assertJsonPath('data.import', null);

        $this->getJson('/api/catalog/vehicles/'.$importe->id)
            ->assertOk()
            ->assertJsonPath('data.customs_cleared', true)
            ->assertJsonPath('data.import', null);

        $this->assertSame(0, DB::table('vehicle_import_details')->whereIn('vehicle_id', [$immatricule->id, $importe->id])->count());
    }

    public function test_un_dossier_d_import_conserve_sa_valeur(): void
    {
        $dedouane = Vehicle::factory()->imported()->create();
        $nonDedouane = Vehicle::factory()->imported()->create();

        DB::table('vehicle_import_details')->insert([
            ['vehicle_id' => $dedouane->id, 'customs_cleared' => true, 'created_at' => now(), 'updated_at' => now()],
            ['vehicle_id' => $nonDedouane->id, 'customs_cleared' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson('/api/catalog/vehicles/'.$dedouane->id)
            ->assertOk()
            ->assertJsonPath('data.customs_cleared', true)
            ->assertJsonPath('data.import.customs_cleared', true);

        $this->getJson('/api/catalog/vehicles/'.$nonDedouane->id)
            ->assertOk()
            ->assertJsonPath('data.customs_cleared', false)
            ->assertJsonPath('data.import.customs_cleared', false);
    }

    public function test_un_engin_n_a_pas_de_statut_de_dedouanement(): void
    {
        $engin = Vehicle::factory()->create([
            'asset_kind' => 'equipment',
            'equipment_type' => 'agricultural_tractor',
            'vehicle_type' => null,
            'registration_status' => 'unregistered',
        ]);

        $this->getJson('/api/catalog/vehicles/'.$engin->id)
            ->assertOk()
            ->assertJsonPath('data.customs_cleared', null);
    }
}
