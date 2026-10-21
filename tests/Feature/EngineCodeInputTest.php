<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EngineCodeInputTest extends TestCase
{
    use RefreshDatabase;

    public static function inputs(): array
    {
        $cases = [];

        foreach (['garage', 'park'] as $scope) {
            foreach (['create', 'update'] as $operation) {
                foreach ([
                    'normalized' => [' 2ar-fe ', '2AR-FE', true],
                    'cleared' => ['', null, true],
                    'array rejected' => [['2AR-FE'], null, false],
                    'integer rejected' => [123, null, false],
                ] as $name => [$input, $expected, $valid]) {
                    $cases[$scope.' '.$operation.' '.$name] = [$scope, $operation, $input, $expected, $valid];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('inputs')]
    public function test_engine_codes_are_normalized_or_rejected_without_partial_writes(
        string $scope,
        string $operation,
        mixed $input,
        ?string $expected,
        bool $valid,
    ): void {
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'Corolla',
            'generation' => 'E180',
            'production_start' => 2013,
            'production_end' => 2019,
        ]);
        $engineType = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $payload = [
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'engine_type_id' => $engineType->id,
            'manufacturing_year' => 2018,
        ];

        if ($scope === 'garage') {
            $owner = User::factory()->create(['role' => 'client', 'firebase_uid' => 'engine-code-owner']);
            $this->actingAsClient($owner);
            $table = 'owned_vehicles';
            $endpoint = '/api/my/vehicles';
            $record = $operation === 'update' ? OwnedVehicle::create($payload + [
                'user_id' => $owner->id,
                'engine_code' => '1NZ-FE',
                'nickname' => 'Avant modification',
            ]) : null;
            $changes = ['nickname' => 'Après modification'];
        } else {
            Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
            $table = 'vehicles';
            $endpoint = '/api/vehicles';
            $record = $operation === 'update' ? Vehicle::create($payload + [
                'reference' => 'ENGINE-CODE-TEST',
                'engine_code' => '1NZ-FE',
                'description' => 'Avant modification',
            ]) : null;
            $changes = ['description' => 'Après modification'];
        }

        $before = $record?->fresh()->getAttributes();
        // Le garage accepte une modification partielle ; le parc exige aussi
        // les champs d'identite a chaque mise a jour.
        $data = ($scope === 'garage' && $operation === 'update' ? [] : $payload)
            + $changes + ['engine_code' => $input];

        $response = $record === null
            ? $this->postJson($endpoint, $data)
            : $this->patchJson($endpoint.'/'.$record->id, $data);

        if (! $valid) {
            $response->assertUnprocessable()->assertJsonValidationErrors('engine_code');
            $this->assertDatabaseCount($table, $record === null ? 0 : 1);

            if ($record !== null) {
                $this->assertSame($before, $record->fresh()->getAttributes());
                $this->assertSame('1NZ-FE', $record->fresh()->engine_code);
            }

            return;
        }

        $response->assertStatus($operation === 'create' ? 201 : 200);
        $this->assertDatabaseCount($table, 1);
        $this->assertDatabaseHas($table, [
            'id' => $record?->id ?? $response->json('data.id'),
            'engine_code' => $expected,
        ] + $changes);
    }
}
