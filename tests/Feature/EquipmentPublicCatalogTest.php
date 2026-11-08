<?php

namespace Tests\Feature;

use App\Http\Resources\VehicleResource;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\EquipmentDocument;
use App\Models\Motorisation;
use App\Models\PartnerAccount;
use App\Models\PrivateVehicleListing;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\CotesOfficielles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EquipmentPublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function equipment(array $attributes = []): Vehicle
    {
        return Vehicle::create(array_replace([
            'reference' => 'EQ-'.(Vehicle::withTrashed()->count() + 1),
            'asset_kind' => 'equipment',
            'equipment_type' => 'aerial_platform',
            'equipment_details' => [
                'brand_label' => 'Haulotte', 'model_label' => 'Compact 12',
                'operating_hours' => 1234.5, 'working_height_m' => 12.5,
                'power_kw' => 35, 'quick_coupler' => false,
            ],
            'registration_status' => 'unregistered',
            'brand_id' => null, 'vehicle_model_id' => null,
            'engine_type_id' => null, 'manufacturing_year' => null,
            'condition' => 'used', 'status' => 'in_stock',
            'availability' => 'both', 'sale_price' => 7_500_000,
            'rental_daily_rate' => 25_000, 'rental_weekly_rate' => 150_000,
            'rental_monthly_rate' => 500_000, 'currency' => 'XOF',
        ], $attributes));
    }

    private function roadVehicle(): Vehicle
    {
        $brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla', 'slug' => 'corolla']);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);

        return Vehicle::create([
            'reference' => 'ROAD-1', 'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id, 'engine_type_id' => $engine->id,
            'manufacturing_year' => 2020, 'registration_status' => 'registered',
            'condition' => 'used', 'status' => 'in_stock', 'availability' => 'sale',
        ]);
    }

    private function privateCar(): PrivateVehicleListing
    {
        $seller = User::factory()->create(['role' => 'client']);

        return PrivateVehicleListing::create([
            'user_id' => $seller->id, 'active_user_id' => $seller->id,
            'status' => 'published', 'published_at' => now(),
            'title' => 'Voiture du particulier', 'vehicle_identity' => ['brand' => 'Toyota', 'model' => 'Corolla'],
            'year' => 2020, 'mileage_km' => 50_000, 'price' => 3_000_000,
            'city' => 'Ouagadougou', 'description' => 'Voiture du vendeur particulier.',
            'seller_name' => 'Particulier', 'phone' => '+22670000001',
            'verified_phone' => '+22670000001', 'phone_verified_at' => now(),
        ]);
    }

    public function test_public_equipment_uses_declared_identity_without_inventing_a_road_type_or_year(): void
    {
        $equipment = $this->equipment();

        foreach (['/api/catalog/vehicles/'.$equipment->id, '/api/catalog/vehicles?asset_kind=equipment'] as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk();
            $data = str_contains($endpoint, '?') ? $response->json('data.0') : $response->json('data');
            $this->assertSame('equipment', $data['asset_kind']);
            $this->assertSame('aerial_platform', $data['equipment_type']);
            $this->assertSame('declared', $data['equipment_details_status']);
            $this->assertSame('Haulotte', $data['identity']['brand']);
            $this->assertSame('Compact 12', $data['identity']['model']);
            $this->assertNull($data['identity']['year']);
            $this->assertNull($data['identity']['engine_type']);
            $this->assertNull($data['vehicle_type']);
            $this->assertSame('Haulotte Compact 12', $data['designation']);
            $this->assertSame(1234.5, $data['equipment_details']['operating_hours']);
            $this->assertFalse($data['equipment_details']['quick_coupler']);
        }
    }

    public function test_asset_and_equipment_filters_keep_private_cars_out_of_equipment_catalogues(): void
    {
        config()->set('private_sales.enabled', true);
        $equipment = $this->equipment();
        $forklift = $this->equipment(['equipment_type' => 'forklift']);
        $road = $this->roadVehicle();
        $private = $this->privateCar();

        $this->getJson('/api/catalog/vehicles?asset_kind=equipment')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/catalog/vehicles?equipment_type=aerial_platform')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $equipment->id);
        $this->getJson('/api/catalog/vehicle-listings?asset_kind=equipment')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/catalog/vehicle-listings?equipment_type=forklift')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'vehicle:'.$forklift->id);
        $roadRows = $this->getJson('/api/catalog/vehicle-listings?asset_kind=road_vehicle')->assertOk()
            ->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing(['vehicle:'.$road->id, 'private_listing:'.$private->id], array_column($roadRows, 'key'));
        $this->getJson('/api/catalog/vehicle-listings?asset_kind=road_vehicle&equipment_type=forklift')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partner_public_payload_preserves_equipment_and_tariffs_but_never_private_document_metadata(): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Levage du Faso', 'contact_name' => 'Loueur',
            'phone' => '+22670001122', 'status' => 'approved',
        ]);
        $equipment = $this->equipment([
            'owner_partner_account_id' => $account->id, 'partner_published_at' => now(),
            'equipment_details' => [
                'brand_label' => 'Haulotte', 'model_label' => 'Compact 12', 'working_height_m' => 12.5,
                'documents' => ['url' => 'https://private.example/SECRET-DOCUMENT.pdf'],
                'document_path' => 'SECRET-DOCUMENT.pdf',
            ],
        ]);
        $document = EquipmentDocument::create([
            'vehicle_id' => $equipment->id, 'document_type' => 'inspection',
            'original_name' => 'SECRET-DOCUMENT.pdf', 'mime_type' => 'application/pdf',
            'size' => 98765, 'path' => 'equipment/SECRET-DOCUMENT.pdf',
            'expires_on' => '2027-01-01', 'status' => 'provided', 'uploaded_by' => $user->id,
        ]);

        $response = $this->getJson('/api/catalog/vehicles/'.$equipment->id)->assertOk()
            ->assertJsonPath('data.asset_kind', 'equipment')
            ->assertJsonPath('data.equipment_type', 'aerial_platform')
            ->assertJsonPath('data.equipment_details.working_height_m', 12.5)
            ->assertJsonPath('data.equipment_details_status', 'declared')
            ->assertJsonPath('data.commercial.rental_weekly_rate', '150000.00')
            ->assertJsonMissingPath('data.equipment_documents');
        $this->assertStringNotContainsString('SECRET-DOCUMENT', $response->getContent());

        $equipment->load(['brand', 'vehicleModel', 'sellerAccount']);
        $equipment->setRelation('equipmentDocuments', collect([$document]));
        $resolved = (new VehicleResource($equipment))->resolve(Request::create('/api/catalog/vehicles/'.$equipment->id));
        $this->assertArrayNotHasKey('equipment_documents', $resolved);
        $this->assertStringNotContainsString('SECRET-DOCUMENT', json_encode($resolved));
    }

    public function test_equipment_never_receives_road_consumption_references_even_with_a_catalogue_model(): void
    {
        $road = $this->roadVehicle();
        $equipment = $this->equipment(['brand_id' => $road->brand_id, 'vehicle_model_id' => $road->vehicle_model_id]);
        $reference = Motorisation::create([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2020,
            'make_raw' => 'Toyota', 'model_raw' => 'Corolla', 'engine_l' => 2,
            'cylinders' => 4, 'transmission_code' => 'AS6', 'fuel_code' => 'X',
            'hybrid_type' => 'none', 'consumption_combined' => 7.5,
            'vehicle_model_id' => $road->vehicle_model_id, 'market' => 'CA',
            'year_kind' => 'model_year', 'method' => 'test', 'reference_status' => 'documented',
            'cle_source' => 'equipment-public-test',
        ]);
        $this->assertCount(1, CotesOfficielles::pourVehicule($road));
        $this->assertSame([], CotesOfficielles::pourVehicule($equipment));
        // A stale or mistakenly populated slot must also be rejected at presentation.
        $equipment->cotesOfficielles = [$reference];
        $this->assertSame([], (new VehicleResource($equipment))->resolve(request())['consumption']['references']);
        $this->getJson('/api/catalog/vehicles/'.$equipment->id)->assertOk()
            ->assertJsonPath('data.consumption.official', null)->assertJsonCount(0, 'data.consumption.references');
    }

    public function test_equipment_share_page_shows_hours_and_measurements_without_false_road_fields(): void
    {
        $equipment = $this->equipment(['manufacturing_year' => 0, 'vin' => 'SECRET-ROAD-VIN']);
        $equipment->registrationDetail()->create(['mileage_km' => 999999, 'plate_number' => 'SECRET-ROAD-PLATE']);
        $html = $this->get('/v/'.$equipment->id)->assertOk()->getContent();

        $this->assertStringContainsString('Haulotte Compact 12', $html);
        $this->assertStringContainsString('Nacelle', $html);
        $this->assertStringContainsString('Horamètre', $html);
        $this->assertStringContainsString('1'."\u{A0}".'234,5'."\u{A0}".'h', $html);
        $this->assertStringContainsString('Hauteur de travail', $html);
        $this->assertStringContainsString('12,5'."\u{A0}".'m', $html);
        $this->assertStringContainsString('35'."\u{A0}".'kW', $html);
        $this->assertStringContainsString('150'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'semaine', $html);
        $this->assertStringNotContainsString('Haulotte Compact 12 0', $html);
        $this->assertStringNotContainsString('Kilométrage', $html);
        $this->assertStringNotContainsString('999'."\u{A0}".'999'."\u{A0}".'km', $html);
        $this->assertStringNotContainsString('SECRET-ROAD', $html);
    }
}
