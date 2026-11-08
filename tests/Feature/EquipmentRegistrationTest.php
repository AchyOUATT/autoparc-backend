<?php

namespace Tests\Feature;

use App\Enums\EquipmentType;
use App\Enums\FitmentSource;
use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\CustomerNeed;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipmentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;
    private VehicleModel $model;
    private EngineType $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::create(['name' => 'JCB']);
        $this->model = VehicleModel::create([
            'brand_id' => $this->brand->id, 'name' => '3CX',
            'equipment_category' => 'backhoe_loader',
        ]);
        $this->engine = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function freeEquipment(array $changes = []): array
    {
        return array_replace([
            'asset_kind' => 'equipment', 'equipment_type' => 'backhoe_loader',
            'equipment_details' => ['brand_label' => 'Marque locale', 'model_label' => 'Chargeuse 90'],
            'availability' => 'sale', 'sale_price' => 8_000_000,
        ], $changes);
    }

    private function referencedEquipment(array $changes = []): array
    {
        return array_replace($this->freeEquipment(), [
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'equipment_details' => null,
        ], $changes);
    }

    private function roadVehicle(array $changes = []): array
    {
        return array_replace([
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->engine->id, 'manufacturing_year' => 2024,
            'availability' => 'sale', 'sale_price' => 8_000_000,
        ], $changes);
    }

    private function createEquipment(array $changes = []): Vehicle
    {
        $response = $this->postJson('/api/vehicles', $this->freeEquipment($changes))->assertCreated();

        return Vehicle::findOrFail($response->json('data.id'));
    }

    private function compatiblePart(array $fitment = []): Part
    {
        $category = PartCategory::firstOrCreate(['slug' => 'equipment-test'], ['name' => 'Pièces de test']);
        $part = Part::create([
            'name' => 'Pièce fictive', 'part_category_id' => $category->id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000,
        ]);
        $part->fitments()->create([
            'vehicle_model_id' => $this->model->id,
            'source' => FitmentSource::Declared,
            ...$fitment,
        ]);

        return $part;
    }

    public function test_free_equipment_identity_does_not_claim_compatibility_without_a_reference_model(): void
    {
        $this->compatiblePart();
        $vehicle = $this->createEquipment();
        foreach (['', '?include_candidates=1'] as $query) {
            $this->getJson("/api/vehicles/{$vehicle->id}/compatible-parts{$query}")
                ->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_unknown_equipment_year_keeps_bounded_fitments_as_candidates_only(): void
    {
        $general = $this->compatiblePart();
        $bounded = $this->compatiblePart(['year_from' => 2020, 'year_to' => 2025]);
        $response = $this->postJson('/api/vehicles', $this->referencedEquipment())->assertCreated();
        $id = $response->json('data.id');

        $this->getJson("/api/vehicles/{$id}/compatible-parts")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $general->id)
            ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');
        $candidates = $this->getJson("/api/vehicles/{$id}/compatible-parts?include_candidates=1")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $candidate = collect($candidates)->firstWhere('id', $bounded->id);
        $this->assertSame('candidate', $candidate['compatibility_assessment']['status']);
        $this->assertFalse($candidate['compatibility_assessment']['compatible']);
        $this->assertSame(['year'], $candidate['compatibility_assessment']['missing_criteria']);
    }

    public function test_known_year_still_filters_parts_for_equipment_and_road_vehicles(): void
    {
        $matching = $this->compatiblePart(['year_from' => 2020, 'year_to' => 2025]);
        $this->compatiblePart(['year_from' => 1990, 'year_to' => 2000]);
        foreach ([$this->referencedEquipment(['manufacturing_year' => 2024]), $this->roadVehicle()] as $payload) {
            $id = $this->postJson('/api/vehicles', $payload)->assertCreated()->json('data.id');
            $this->getJson("/api/vehicles/{$id}/compatible-parts")->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id)
                ->assertJsonPath('data.0.compatibility_assessment.status', 'confirmed');
        }
    }

    public function test_publishing_an_equipment_with_unknown_year_skips_year_constrained_needs(): void
    {
        foreach ([[], ['year_min' => 2020], ['year_max' => 2025]] as $index => $criteria) {
            CustomerNeed::create([
                'type' => 'vehicle', 'description' => 'Besoin fictif',
                'status' => 'pending', 'firebase_uid' => 'equipment-need-'.$index,
                ...$criteria,
            ]);
        }
        $vehicle = $this->createEquipment();
        $this->postJson("/api/vehicles/{$vehicle->id}/publish")->assertOk();
        $this->assertNotNull($vehicle->fresh()->published_at);
        $notifications = AppNotification::where('type', 'vehicle_match')->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('equipment-need-0', $notifications->first()->recipient_id);
        $this->postJson("/api/vehicles/{$vehicle->id}/publish")->assertOk();
        $this->assertSame(1, AppNotification::where('type', 'vehicle_match')->count());
    }

    public function test_publishing_a_known_year_still_matches_only_the_requested_range(): void
    {
        foreach ([['year_min' => 2020], ['year_max' => 2025], ['year_min' => 2026], ['year_max' => 2023]] as $index => $criteria) {
            CustomerNeed::create([
                'type' => 'vehicle', 'description' => 'Besoin fictif',
                'status' => 'pending', 'firebase_uid' => 'known-year-'.$index,
                ...$criteria,
            ]);
        }
        foreach ([$this->referencedEquipment(['manufacturing_year' => 2024]), $this->roadVehicle()] as $payload) {
            AppNotification::query()->delete();
            $id = $this->postJson('/api/vehicles', $payload)->assertCreated()->json('data.id');
            $this->postJson("/api/vehicles/{$id}/publish")->assertOk();
            $this->assertSame(['known-year-0', 'known-year-1'], AppNotification::where('type', 'vehicle_match')
                ->orderBy('recipient_id')->pluck('recipient_id')->all());
        }
    }

    public function test_minimal_free_identity_creates_equipment_without_inventing_references_year_or_fuel(): void
    {
        $vehicle = $this->createEquipment(['equipment_details' => [
            'brand_label' => '  Marque locale  ', 'model_label' => '  Chargeuse 90  ',
        ]]);

        $this->assertSame('equipment', $vehicle->asset_kind);
        $this->assertSame('backhoe_loader', $vehicle->equipment_type);
        $this->assertNull($vehicle->brand_id);
        $this->assertNull($vehicle->vehicle_model_id);
        $this->assertNull($vehicle->engine_type_id);
        $this->assertNull($vehicle->manufacturing_year);
        $this->assertNull($vehicle->vehicle_type);
        $this->assertNull($vehicle->body_style);
        $this->assertNull($vehicle->trim_id);
        $this->assertSame('Marque locale Chargeuse 90', $vehicle->designation);
        $this->assertSame(['brand_label' => 'Marque locale', 'model_label' => 'Chargeuse 90'], $vehicle->equipment_details);
        $this->assertDatabaseCount('brands', 1);
        $this->assertDatabaseCount('vehicle_models', 1);
    }

    public function test_reference_identity_and_rental_tariffs_work_with_optional_year_and_fuel(): void
    {
        $trim = Trim::create(['vehicle_model_id' => $this->model->id, 'name' => 'Ancienne sélection']);
        $response = $this->postJson('/api/vehicles', $this->referencedEquipment([
            'availability' => 'rent', 'sale_price' => null, 'rental_weekly_rate' => 250_000,
            'manufacturing_year' => null, 'engine_type_id' => null,
            'vehicle_type' => 'passenger', 'body_style' => 'sedan', 'trim_id' => $trim->id,
        ]))->assertCreated();
        $vehicle = Vehicle::findOrFail($response->json('data.id'));

        $this->assertSame($this->brand->id, $vehicle->brand_id);
        $this->assertSame($this->model->id, $vehicle->vehicle_model_id);
        $this->assertNull($vehicle->manufacturing_year);
        $this->assertNull($vehicle->engine_type_id);
        $this->assertNull($vehicle->vehicle_type);
        $this->assertNull($vehicle->body_style);
        $this->assertNull($vehicle->trim_id);
        $this->assertNull($vehicle->equipment_details);
        $this->assertSame('JCB 3CX', $vehicle->designation);

        $this->patchJson('/api/vehicles/'.$vehicle->id, ['rental_weekly_rate' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('rental_weekly_rate');
        $this->patchJson('/api/vehicles/'.$vehicle->id, [
            'rental_weekly_rate' => null, 'rental_daily_rate' => 50_000,
        ])->assertOk();
        $this->assertNull($vehicle->fresh()->rental_weekly_rate);
        $this->assertEquals(50_000, $vehicle->fresh()->rental_daily_rate);
    }

    public function test_declared_measurements_round_trip_and_partial_changes_merge_with_explicit_clears(): void
    {
        $details = [
            'brand_label' => 'JCB', 'model_label' => '3CX', 'serial_number' => '  SERIAL-123 ',
            'registration_number' => 'BF-45', 'park_number' => 'PARC-8',
            'operating_hours' => '1250.5', 'hour_reading_date' => now()->toDateString(),
            'odometer_km' => '50.5', 'power_kw' => '75.5', 'transmission' => 'powershift',
            'rated_load_kg' => '1500.25', 'working_height_m' => '4.5', 'outreach_m' => '2.25',
            'lift_height_m' => '3.75', 'operating_weight_kg' => '8500.5',
            'bucket_capacity_m3' => '0.75', 'pto_rpm' => '540', 'cabin' => 'air_conditioned',
            'quick_coupler' => true, 'attachments' => 'Godet, fourches',
            'engine_serial_number' => 'MOTEUR-123',
        ];
        $vehicle = $this->createEquipment(['equipment_details' => $details]);
        $stored = $vehicle->equipment_details;
        $this->assertSame('SERIAL-123', $stored['serial_number']);
        $this->assertSame(1250.5, $stored['operating_hours']);
        $this->assertSame(0.75, $stored['bucket_capacity_m3']);
        $this->assertSame(540, $stored['pto_rpm']);
        $this->assertTrue($stored['quick_coupler']);
        foreach ($details as $key => $value) {
            $this->assertEquals(is_string($value) ? trim($value) : $value, $stored[$key], $key);
        }

        $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk();
        $this->patchJson('/api/vehicles/'.$vehicle->id, ['description' => 'Disponible immédiatement'])->assertOk();
        $this->assertSame($stored, $vehicle->fresh()->equipment_details);
        $this->patchJson('/api/vehicles/'.$vehicle->id, ['equipment_details' => [
            'serial_number' => null, 'operating_hours' => 0, 'quick_coupler' => false,
        ]])->assertOk();
        $after = $vehicle->fresh()->equipment_details;
        $this->assertArrayNotHasKey('serial_number', $after);
        $this->assertEquals(0, $after['operating_hours']);
        $this->assertFalse($after['quick_coupler']);
        $this->assertSame('3CX', $after['model_label']);
        $this->assertSame(0.75, $after['bucket_capacity_m3']);

        $this->patchJson('/api/vehicles/'.$vehicle->id, ['equipment_details' => []])->assertOk();
        $this->assertSame($after, $vehicle->fresh()->equipment_details);
    }

    public function test_clearing_details_validates_the_final_identity_and_references_allow_a_full_clear(): void
    {
        $vehicle = $this->createEquipment();
        $this->patchJson('/api/vehicles/'.$vehicle->id, ['equipment_details' => ['brand_label' => null]])
            ->assertUnprocessable()->assertJsonValidationErrors('equipment_details.brand_label');
        $this->patchJson('/api/vehicles/'.$vehicle->id, ['equipment_details' => null])
            ->assertUnprocessable()->assertJsonValidationErrors([
                'equipment_details.brand_label', 'equipment_details.model_label',
            ]);
        $this->assertSame('Marque locale', $vehicle->fresh()->equipment_details['brand_label']);

        $this->patchJson('/api/vehicles/'.$vehicle->id, [
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'equipment_details' => null,
        ])->assertOk();
        $this->assertNull($vehicle->fresh()->equipment_details);
        $this->assertSame('JCB 3CX', $vehicle->fresh()->designation);
    }

    public static function invalidDetails(): array
    {
        return [
            'raw JSON' => ['{"operating_hours":1}', 'equipment_details'],
            'list' => [['non objet'], 'equipment_details'],
            'unknown key' => [['verified' => true], 'equipment_details'],
            'unknown null key' => [['manufacturer_verified' => null], 'equipment_details'],
            'array serial' => [['serial_number' => ['123']], 'equipment_details.serial_number'],
            'negative hours' => [['operating_hours' => -1], 'equipment_details.operating_hours'],
            'negative power' => [['power_kw' => -0.1], 'equipment_details.power_kw'],
            'negative height' => [['working_height_m' => -1], 'equipment_details.working_height_m'],
            'negative load' => [['rated_load_kg' => -1], 'equipment_details.rated_load_kg'],
            'negative bucket' => [['bucket_capacity_m3' => -1], 'equipment_details.bucket_capacity_m3'],
            'fraction PTO' => [['pto_rpm' => 540.5], 'equipment_details.pto_rpm'],
            'invalid transmission' => [['transmission' => 'automatic'], 'equipment_details.transmission'],
            'invalid cabin' => [['cabin' => 'panoramic'], 'equipment_details.cabin'],
            'invalid coupler' => [['quick_coupler' => 'yes'], 'equipment_details.quick_coupler'],
            'array attachments' => [['attachments' => ['Godet']], 'equipment_details.attachments'],
            'future hour reading' => [['hour_reading_date' => '2999-01-01'], 'equipment_details.hour_reading_date'],
            'overlong label' => [['brand_label' => str_repeat('a', 256)], 'equipment_details.brand_label'],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_equipment_details_reject_unknown_fields_and_invalid_measurements(mixed $details, string $field): void
    {
        if (is_array($details) && ! array_is_list($details)) {
            $details = array_replace($this->freeEquipment()['equipment_details'], $details);
        }
        $this->postJson('/api/vehicles', $this->freeEquipment(['equipment_details' => $details]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_equipment_requires_a_known_type_and_both_parts_of_its_identity(): void
    {
        foreach ([null, 'truck'] as $type) {
            $this->postJson('/api/vehicles', $this->freeEquipment(['equipment_type' => $type]))
                ->assertUnprocessable()->assertJsonValidationErrors('equipment_type');
        }
        foreach (['brand_label', 'model_label'] as $field) {
            $details = $this->freeEquipment()['equipment_details'];
            unset($details[$field]);
            $this->postJson('/api/vehicles', $this->freeEquipment(['equipment_details' => $details]))
                ->assertUnprocessable()->assertJsonValidationErrors('equipment_details.'.$field);
        }
        $this->assertCount(9, EquipmentType::cases());
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_reference_models_must_match_the_brand_and_equipment_type_on_create_and_patch(): void
    {
        $foreignBrand = Brand::create(['name' => 'Autre constructeur']);
        $roadModel = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Modèle routier']);
        foreach ([
            ['brand_id' => $foreignBrand->id],
            ['equipment_type' => 'forklift'],
            ['vehicle_model_id' => $roadModel->id],
        ] as $change) {
            $this->postJson('/api/vehicles', $this->referencedEquipment($change))
                ->assertUnprocessable()->assertJsonValidationErrors('vehicle_model_id');
        }
        $response = $this->postJson('/api/vehicles', $this->referencedEquipment())->assertCreated();
        $id = $response->json('data.id');
        $this->patchJson('/api/vehicles/'.$id, ['equipment_type' => 'forklift'])
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_model_id');
        $this->patchJson('/api/vehicles/'.$id, ['brand_id' => $foreignBrand->id])
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_model_id');
        $this->assertSame('backhoe_loader', Vehicle::findOrFail($id)->equipment_type);
    }

    public function test_asset_kind_cannot_be_changed_in_either_direction(): void
    {
        $equipment = $this->createEquipment();
        $this->patchJson('/api/vehicles/'.$equipment->id, $this->roadVehicle(['asset_kind' => 'road_vehicle']))
            ->assertUnprocessable()->assertJsonValidationErrors('asset_kind');

        $response = $this->postJson('/api/vehicles', $this->roadVehicle())->assertCreated();
        $road = Vehicle::findOrFail($response->json('data.id'));
        $this->assertSame('road_vehicle', $road->asset_kind);
        $this->patchJson('/api/vehicles/'.$road->id, array_replace($this->roadVehicle(), $this->freeEquipment()))
            ->assertUnprocessable()->assertJsonValidationErrors('asset_kind');
        $this->assertSame('road_vehicle', $road->fresh()->asset_kind);
        $this->assertSame('equipment', $equipment->fresh()->asset_kind);
    }

    public function test_road_registration_keeps_required_identity_year_and_fuel_and_rejects_equipment_metadata(): void
    {
        foreach (['brand_id', 'vehicle_model_id', 'engine_type_id', 'manufacturing_year'] as $field) {
            $payload = $this->roadVehicle();
            unset($payload[$field]);
            $this->postJson('/api/vehicles', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
            $payload[$field] = null;
            $this->postJson('/api/vehicles', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['equipment_type' => 'loader'], ['equipment_details' => ['operating_hours' => 0]]] as $change) {
            $this->postJson('/api/vehicles', $this->roadVehicle($change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('vehicles', 0);
        $response = $this->postJson('/api/vehicles', $this->roadVehicle())->assertCreated();
        $road = Vehicle::findOrFail($response->json('data.id'));
        $this->patchJson('/api/vehicles/'.$road->id, $this->roadVehicle(['manufacturing_year' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('manufacturing_year');
        $this->patchJson('/api/vehicles/'.$road->id, $this->roadVehicle([
            'equipment_details' => ['serial_number' => 'BAD-ROAD-METADATA'],
        ]))->assertUnprocessable();
        $this->assertNull($road->fresh()->equipment_details);
    }

    public function test_catalog_roles_and_partner_ownership_apply_to_equipment(): void
    {
        $foreign = $this->createEquipment();
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $this->postJson('/api/vehicles', $this->freeEquipment())->assertForbidden();
        $this->patchJson('/api/vehicles/'.$foreign->id, ['equipment_details' => ['operating_hours' => 10]])->assertForbidden();

        $user = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Location matériel', 'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($user->id), 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/partner/vehicles', $this->freeEquipment([
            'availability' => 'rent', 'sale_price' => null, 'rental_daily_rate' => 50_000,
        ]))->assertCreated();
        $owned = Vehicle::findOrFail($response->json('data.id'));
        $this->assertSame($account->id, $owned->owner_partner_account_id);
        $this->patchJson('/api/partner/vehicles/'.$owned->id, [
            'equipment_details' => ['operating_hours' => 600.5],
        ])->assertOk();
        $this->assertSame(600.5, $owned->fresh()->equipment_details['operating_hours']);
        $this->assertSame('Chargeuse 90', $owned->fresh()->equipment_details['model_label']);
        $this->patchJson('/api/partner/vehicles/'.$foreign->id, [
            'equipment_details' => ['operating_hours' => 999],
        ])->assertNotFound();
        $this->assertArrayNotHasKey('operating_hours', $foreign->fresh()->equipment_details);
    }

    public function test_search_finds_free_labels_and_serial_without_reference_records(): void
    {
        $vehicle = $this->createEquipment(['equipment_details' => [
            'brand_label' => 'Atelier Burkina', 'model_label' => 'Chargeuse locale 90',
            'serial_number' => 'SERIAL-UNIQUE-472',
        ]]);
        foreach (['Burkina', 'locale 90', 'UNIQUE-472'] as $term) {
            $this->assertSame([$vehicle->id], Vehicle::search($term)->pluck('id')->all());
        }
    }
}
