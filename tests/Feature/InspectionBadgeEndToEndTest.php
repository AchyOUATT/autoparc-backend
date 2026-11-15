<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PrivateListingFault;
use App\Models\PrivateVehicleListing;
use App\Models\StaffVehicleInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFault;
use App\Models\VehicleModel;
use App\Services\StaffVehicleInspections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InspectionBadgeEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $seller;

    private Brand $brand;

    private VehicleModel $model;

    private EngineType $engine;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('private_sales.enabled', true);
        config(['equipment.documents_disk' => 'local', 'media.disk' => 'public']);
        Storage::fake('local');
        Storage::fake('public');
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->seller = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota-badge-e2e']);
        $this->model = VehicleModel::create([
            'brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla-badge-e2e',
        ]);
        $this->engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
    }

    public function test_le_badge_reste_valide_sur_la_fiche_catalogue_d_un_vehicule_avec_defauts(): void
    {
        $vehicle = $this->publishedVehicle();
        $fault = $this->declareFault($vehicle, [
            'repair_necessity' => 'necessary',
            'repair_cost_min' => 45000,
            'repair_cost_max' => 65000,
            'repair_estimate_source' => 'workshop',
            'repair_estimated_at' => now()->toDateString(),
        ]);
        $this->publishQuote($vehicle, $fault);

        $inspectionId = $this->createInspectionViaStaffRoute($vehicle);
        $inspection = StaffVehicleInspection::findOrFail($inspectionId);
        $stored = $inspection->technical_fingerprint;
        $this->assertNotSame('', $stored);
        $this->assertFingerprintIncludesDeclaredFaults($vehicle, $stored);

        $this->asPublic();
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspectionId);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspectionId)->assertOk();

        $fault->update([
            'repair_cost_min' => 50000,
            'repair_cost_max' => 80000,
            'repair_estimate_source' => 'seller',
            'repair_estimated_at' => now()->subDay()->toDateString(),
        ]);
        $this->publishQuote($vehicle, $fault, 'devis-masque-2.jpg');
        $inspection->refresh();
        $this->assertSame('active', $inspection->status);
        $this->assertSame($stored, $inspection->technical_fingerprint);
        $this->asPublic();
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspectionId);

        $fault->update(['description' => 'Le constat technique a changé.']);
        $this->assertSame('withdrawn', $inspection->fresh()->status);
        $this->asPublic();
        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()
            ->assertJsonPath('data.inspection', null);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspectionId)->assertNotFound();
    }

    public function test_le_badge_reste_valide_sur_la_fiche_publique_d_une_annonce_particuliere(): void
    {
        $listing = $this->publishedListing();
        $fault = $this->declareFault($listing, [
            'repair_necessity' => 'necessary',
            'repair_cost_min' => 35000,
            'repair_cost_max' => 50000,
            'repair_estimate_source' => 'workshop',
            'repair_estimated_at' => now()->toDateString(),
        ]);
        $this->publishQuote($listing, $fault);

        $inspectionId = $this->createInspectionViaStaffRoute($listing);
        $inspection = StaffVehicleInspection::findOrFail($inspectionId);
        $stored = $inspection->technical_fingerprint;
        $this->assertNotSame('', $stored);
        $this->assertFingerprintIncludesDeclaredFaults($listing, $stored);

        $this->asPublic();
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspectionId);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspectionId)->assertOk();

        $fault->update([
            'repair_cost_min' => 40000,
            'repair_cost_max' => 60000,
            'repair_estimate_source' => 'seller',
            'repair_estimated_at' => now()->subDay()->toDateString(),
        ]);
        $this->publishQuote($listing, $fault, 'devis-masque-2.jpg');
        $inspection->refresh();
        $this->assertSame('active', $inspection->status);
        $this->assertSame($stored, $inspection->technical_fingerprint);
        $this->asPublic();
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()
            ->assertJsonPath('data.inspection.id', $inspectionId);

        $listing->update(['mileage_km' => 60000]);
        $this->assertSame('withdrawn', $inspection->fresh()->status);
        $this->asPublic();
        $this->getJson('/api/private-listings/'.$listing->id)->assertOk()
            ->assertJsonPath('data.inspection', null);
        $this->getJson('/api/catalog/vehicle-inspections/'.$inspectionId)->assertNotFound();
    }

    private function publishedVehicle(): Vehicle
    {
        return Vehicle::create([
            'reference' => 'BADGE-E2E-'.(Vehicle::withTrashed()->count() + 1),
            'registration_status' => 'registered',
            'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->engine->id,
            'manufacturing_year' => 2020,
            'condition' => 'used',
            'status' => 'in_stock',
            'availability' => 'sale',
            'sale_price' => 2_000_000,
            'published_at' => now(),
        ]);
    }

    private function publishedListing(): PrivateVehicleListing
    {
        return PrivateVehicleListing::create([
            'user_id' => $this->seller->id,
            'active_user_id' => $this->seller->id,
            'status' => 'published',
            'published_at' => now(),
            'title' => 'Toyota Corolla',
            'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->engine->id,
            'vehicle_identity' => ['brand' => 'Toyota', 'model' => 'Corolla'],
            'year' => 2020,
            'mileage_km' => 50000,
            'price' => 3_000_000,
            'city' => 'Ouagadougou',
            'description' => 'Voiture de particulier.',
            'seller_name' => 'Vendeur',
            'phone' => '+22670000001',
            'verified_phone' => '+22670000001',
            'phone_verified_at' => now(),
        ]);
    }

    private function declareFault(Vehicle|PrivateVehicleListing $target, array $extra): VehicleFault|PrivateListingFault
    {
        return $target->faults()->create(array_replace([
            'category' => 'braking',
            'title' => 'Freins à réparer',
            'description' => 'Usure avancée des plaquettes.',
            'severity' => 'moderate',
            'status' => 'declared',
            'disclosed_to_buyer' => true,
        ], $extra));
    }

    private function publishQuote(
        Vehicle|PrivateVehicleListing $target,
        VehicleFault|PrivateListingFault $fault,
        string $filename = 'devis-masque.jpg',
    ): void {
        Sanctum::actingAs($this->manager);
        $path = $target instanceof Vehicle
            ? '/api/vehicles/'.$target->id.'/faults/'.$fault->id.'/repair-quote/publish'
            : '/api/private-listing-reviews/'.$target->id.'/faults/'.$fault->id.'/repair-quote/publish';
        $this->post($path, [
            'document' => UploadedFile::fake()->image($filename, 400, 400),
        ])->assertCreated();
        $this->asPublic();
    }

    private function createInspectionViaStaffRoute(Vehicle|PrivateVehicleListing $target): int
    {
        Sanctum::actingAs($this->manager);
        $source = $target instanceof Vehicle ? 'vehicle' : 'private_listing';
        $id = $this->postJson('/api/vehicle-inspections/'.$source.'/'.$target->id, [
            'inspected_at' => now()->subHour()->toIso8601String(),
            'mileage_km' => 50100,
            'summary' => 'Constat limité aux points contrôlés.',
            'confirmed' => true,
            'items' => [
                ['label' => 'Freinage', 'status' => 'ok', 'note' => null],
                ['label' => 'Climatisation', 'status' => 'not_checked', 'note' => null],
            ],
        ])->assertCreated()->json('data.id');
        $this->asPublic();

        return (int) $id;
    }

    private function assertFingerprintIncludesDeclaredFaults(
        Vehicle|PrivateVehicleListing $target,
        string $stored,
    ): void {
        $without = $target instanceof Vehicle
            ? Vehicle::query()->without(['faults', 'faults.publishedQuote'])->findOrFail($target->id)
            : PrivateVehicleListing::query()->without(['faults', 'faults.publishedQuote'])->findOrFail($target->id);
        $this->assertFalse($without->relationLoaded('faults'));
        $hashWithout = StaffVehicleInspections::fingerprint($without);
        $hashWith = StaffVehicleInspections::fingerprint($without->load('faults'));
        $this->assertNotSame($hashWithout, $hashWith);
        $this->assertSame($hashWith, $stored);
    }

    private function asPublic(): void
    {
        Auth::forgetGuards();
        $this->defaultHeaders = [];
    }
}
