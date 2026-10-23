<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VehicleClassificationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private array $references;

    protected function setUp(): void
    {
        parent::setUp();

        $this->references = [
            'brand' => Brand::create(['name' => 'Toyota']),
            'other_brand' => Brand::create(['name' => 'Honda']),
            'engine' => EngineType::create(['code' => 'petrol', 'label' => 'Essence']),
        ];

        foreach (['first' => 'brand', 'second' => 'brand', 'foreign' => 'other_brand'] as $key => $brandKey) {
            $model = VehicleModel::create([
                'brand_id' => $this->references[$brandKey]->id,
                'name' => $key,
            ]);
            $this->references[$key] = $model;
            $this->references[$key.'_trim'] = Trim::create([
                'vehicle_model_id' => $model->id,
                'name' => 'LE',
            ]);
            $this->references[$key.'_motor'] = Motorisation::create([
                'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2018,
                'make_raw' => $brandKey, 'model_raw' => $key,
                'brand_id' => $model->brand_id, 'vehicle_model_id' => $model->id,
            ]);
        }
    }

    private function payload(array $fields): array
    {
        return array_map(fn ($value) => is_string($value) && str_starts_with($value, '@')
            ? $this->references[substr($value, 1)]->id
            : $value, $fields);
    }

    private function garageVehicle(): OwnedVehicle
    {
        $owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'classification-owner']);
        $this->actingAsClient($owner);

        return OwnedVehicle::create($this->payload([
            'user_id' => $owner->id, 'brand_id' => '@brand', 'vehicle_model_id' => '@first',
            'trim_id' => '@first_trim', 'motorisation_id' => '@first_motor',
            'manufacturing_year' => 2018,
        ]));
    }

    public static function inconsistentGarageUpdates(): array
    {
        return [
            'marque seule' => [['brand_id' => '@other_brand'], ['vehicle_model_id']],
            'modele seul conserve les dependances' => [['vehicle_model_id' => '@second'], ['trim_id', 'motorisation_id']],
            'modele d une autre marque' => [['vehicle_model_id' => '@foreign'], ['vehicle_model_id']],
            'finition seule' => [['trim_id' => '@second_trim'], ['trim_id']],
            'motorisation seule' => [['motorisation_id' => '@second_motor'], ['motorisation_id']],
            'saisie libre conserve les dependances' => [
                ['vehicle_model_id' => null, 'model_libre' => 'Modele absent'], ['trim_id', 'motorisation_id'],
            ],
            'saisie libre conserve la motorisation' => [
                ['vehicle_model_id' => null, 'model_libre' => 'Modele absent', 'trim_id' => null], ['motorisation_id'],
            ],
            'saisie libre conserve la finition' => [
                ['vehicle_model_id' => null, 'model_libre' => 'Modele absent', 'motorisation_id' => null], ['trim_id'],
            ],
            'finition corrigee mais ancien moteur' => [
                ['vehicle_model_id' => '@second', 'trim_id' => '@second_trim'], ['motorisation_id'],
            ],
            'moteur corrige mais ancienne finition' => [
                ['vehicle_model_id' => '@second', 'motorisation_id' => '@second_motor'], ['trim_id'],
            ],
        ];
    }

    #[DataProvider('inconsistentGarageUpdates')]
    public function test_partial_garage_updates_validate_the_resulting_classification(array $fields, array $errors): void
    {
        $vehicle = $this->garageVehicle();
        $before = $vehicle->fresh()->getAttributes();

        $this->patchJson('/api/my/vehicles/'.$vehicle->id, $this->payload($fields))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertSame($before, $vehicle->fresh()->getAttributes());
    }

    public static function coherentGarageUpdates(): array
    {
        return [
            'autre champ preserve l identite' => [['nickname' => 'Ma voiture']],
            'retrait explicite des dependances' => [['trim_id' => null, 'motorisation_id' => null]],
            'nouveau modele sans dependances' => [
                ['vehicle_model_id' => '@second', 'trim_id' => null, 'motorisation_id' => null],
            ],
            'nouvelle classification complete' => [[
                'brand_id' => '@other_brand', 'vehicle_model_id' => '@foreign',
                'trim_id' => '@foreign_trim', 'motorisation_id' => '@foreign_motor',
            ]],
            'saisie libre avec retrait explicite' => [[
                'vehicle_model_id' => null, 'model_libre' => 'Modele absent',
                'trim_id' => null, 'motorisation_id' => null,
            ]],
        ];
    }

    #[DataProvider('coherentGarageUpdates')]
    public function test_coherent_changes_and_explicit_clears_are_saved(array $fields): void
    {
        $vehicle = $this->garageVehicle();
        $data = $this->payload($fields);

        $this->patchJson('/api/my/vehicles/'.$vehicle->id, $data)->assertOk();

        $this->assertDatabaseHas('owned_vehicles', ['id' => $vehicle->id] + $data);
        if (! array_key_exists('vehicle_model_id', $fields)) {
            $this->assertSame($this->references['first']->id, $vehicle->fresh()->vehicle_model_id);
        }
    }

    public static function invalidGarageCreations(): array
    {
        return [
            'modele d une autre marque' => [['vehicle_model_id' => '@foreign'], ['vehicle_model_id']],
            'finition d un autre modele' => [['trim_id' => '@second_trim'], ['trim_id']],
            'motorisation d un autre modele' => [['motorisation_id' => '@second_motor'], ['motorisation_id']],
            'modele libre et finition' => [
                ['vehicle_model_id' => null, 'model_libre' => 'Modele absent', 'trim_id' => '@first_trim'], ['trim_id'],
            ],
            'modele libre et motorisation' => [
                ['vehicle_model_id' => null, 'model_libre' => 'Modele absent', 'motorisation_id' => '@first_motor'], ['motorisation_id'],
            ],
            'identifiant tableau' => [['vehicle_model_id' => [1]], ['vehicle_model_id']],
            'modele libre tableau' => [['model_libre' => ['incorrect']], ['model_libre']],
        ];
    }

    #[DataProvider('invalidGarageCreations')]
    public function test_invalid_garage_classifications_are_rejected_at_creation(array $fields, array $errors): void
    {
        $this->actingAsClient(uid: 'classification-new-owner');

        $data = $this->payload(array_replace([
            'brand_id' => '@brand', 'vehicle_model_id' => '@first', 'manufacturing_year' => 2018,
        ], $fields));

        $this->postJson('/api/my/vehicles', $data)
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertDatabaseCount('owned_vehicles', 0);
    }

    private function parkPayload(array $fields = []): array
    {
        return $this->payload(array_replace([
            'brand_id' => '@brand', 'vehicle_model_id' => '@first',
            'engine_type_id' => '@engine', 'manufacturing_year' => 2018,
        ], $fields));
    }

    private function parkVehicle(): Vehicle
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        return Vehicle::create($this->parkPayload(['reference' => 'CLASSIFICATION', 'trim_id' => '@first_trim']));
    }

    public function test_park_update_cannot_retain_a_trim_from_the_previous_model(): void
    {
        $vehicle = $this->parkVehicle();

        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->parkPayload(['vehicle_model_id' => '@second']))
            ->assertUnprocessable()->assertJsonValidationErrors('trim_id');

        $this->assertSame($this->references['first']->id, $vehicle->fresh()->vehicle_model_id);
    }

    public function test_park_update_can_explicitly_clear_the_previous_trim(): void
    {
        $vehicle = $this->parkVehicle();

        $data = $this->parkPayload(['vehicle_model_id' => '@second', 'trim_id' => null]);
        $this->patchJson('/api/vehicles/'.$vehicle->id, $data)->assertOk()
            ->assertJsonPath('data.brand_id', $this->references['brand']->id)
            ->assertJsonPath('data.vehicle_model_id', $this->references['second']->id)
            ->assertJsonPath('data.trim_id', null)
            ->assertJsonPath('data.engine_type_id', $this->references['engine']->id);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'vehicle_model_id' => $this->references['second']->id, 'trim_id' => null]);
    }

    public function test_park_update_accepts_a_coherent_replacement(): void
    {
        $vehicle = $this->parkVehicle();

        $data = $this->parkPayload([
            'brand_id' => '@other_brand', 'vehicle_model_id' => '@foreign', 'trim_id' => '@foreign_trim',
        ]);
        $this->patchJson('/api/vehicles/'.$vehicle->id, $data)->assertOk();
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'trim_id' => $this->references['foreign_trim']->id]);
    }

    /**
     * Historical rows were accepted before classification validation existed.
     * Independent changes must not force users to repair those rows first.
     */
    public function test_legacy_garage_mileage_update_ignores_untouched_bad_classification(): void
    {
        $vehicle = $this->garageVehicle();
        $vehicle->forceFill([
            'trim_id' => $this->references['second_trim']->id,
            'motorisation_id' => $this->references['second_motor']->id,
        ])->save();

        $this->patchJson('/api/my/vehicles/'.$vehicle->id, ['mileage_km' => 149500])
            ->assertOk();

        $this->assertDatabaseHas('owned_vehicles', [
            'id' => $vehicle->id,
            'mileage_km' => 149500,
            'trim_id' => $this->references['second_trim']->id,
            'motorisation_id' => $this->references['second_motor']->id,
        ]);
    }

    public function test_legacy_park_price_update_does_not_require_classification_resubmission(): void
    {
        $vehicle = $this->parkVehicle();
        $vehicle->forceFill(['trim_id' => $this->references['second_trim']->id])->save();

        $this->patchJson('/api/vehicles/'.$vehicle->id, ['sale_price' => 5500000])
            ->assertOk();

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'sale_price' => 5500000,
            'trim_id' => $this->references['second_trim']->id,
        ]);
    }

    public function test_legacy_park_resubmitting_unchanged_identity_does_not_block_price(): void
    {
        $vehicle = $this->parkVehicle();
        $vehicle->forceFill(['trim_id' => $this->references['second_trim']->id])->save();

        $this->patchJson('/api/vehicles/'.$vehicle->id, $this->parkPayload([
            'trim_id' => '@second_trim',
            'sale_price' => 5600000,
        ]))->assertOk();

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'sale_price' => 5600000,
            'trim_id' => $this->references['second_trim']->id,
        ]);
    }

    public function test_legacy_garage_can_repair_one_dependency_without_fixing_another(): void
    {
        $vehicle = $this->garageVehicle();
        $vehicle->forceFill([
            'trim_id' => $this->references['second_trim']->id,
            'motorisation_id' => $this->references['second_motor']->id,
        ])->save();

        $this->patchJson('/api/my/vehicles/'.$vehicle->id, [
            'trim_id' => $this->references['first_trim']->id,
        ])->assertOk();

        $this->assertDatabaseHas('owned_vehicles', [
            'id' => $vehicle->id,
            'trim_id' => $this->references['first_trim']->id,
            'motorisation_id' => $this->references['second_motor']->id,
        ]);
    }

    public function test_legacy_garage_still_rejects_an_incoherent_model_change(): void
    {
        $vehicle = $this->garageVehicle();
        $vehicle->forceFill(['trim_id' => $this->references['second_trim']->id])->save();
        $before = $vehicle->fresh()->getAttributes();

        $this->patchJson('/api/my/vehicles/'.$vehicle->id, [
            'vehicle_model_id' => $this->references['foreign']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_model_id']);

        $this->assertSame($before, $vehicle->fresh()->getAttributes());
    }

    public function test_park_creation_rejects_a_trim_from_another_model(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/vehicles', $this->parkPayload(['trim_id' => '@second_trim']))
            ->assertUnprocessable()->assertJsonValidationErrors('trim_id');
        $this->assertDatabaseCount('vehicles', 0);
    }
}
