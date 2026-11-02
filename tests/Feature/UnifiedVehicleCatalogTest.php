<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\PrivateVehicleListing;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnifiedVehicleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;
    private VehicleModel $model;
    private EngineType $engine;
    private Trim $trim;
    private Drivetrain $drivetrain;
    private Color $color;
    private const ENDPOINT = '/api/catalog/vehicle-listings';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        config()->set('private_sales.enabled', true);
        Storage::fake('public');
        $this->brand = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->model = VehicleModel::create(['brand_id' => $this->brand->id, 'name' => 'Corolla', 'slug' => 'corolla']);
        $this->engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->trim = Trim::create(['vehicle_model_id' => $this->model->id, 'name' => 'LE']);
        $this->drivetrain = Drivetrain::create(['code' => 'fwd', 'label' => 'Traction']);
        $this->color = Color::create(['name' => 'Blanc', 'hex_code' => '#ffffff']);
    }

    private function vehicle(array $extra = []): Vehicle
    {
        $created = $extra['created_at'] ?? now();
        unset($extra['created_at']);
        $vehicle = Vehicle::create(array_replace([
            'reference' => 'CAT-'.(Vehicle::withTrashed()->count() + 1),
            'registration_status' => 'registered',
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'engine_type_id' => $this->engine->id, 'trim_id' => $this->trim->id,
            'drivetrain_id' => $this->drivetrain->id, 'color_id' => $this->color->id,
            'manufacturing_year' => 2020, 'condition' => 'used', 'status' => 'in_stock',
            'availability' => 'sale', 'sale_price' => 2_000_000, 'currency' => 'XOF',
            'published_at' => now(),
        ], $extra));
        $vehicle->forceFill(['created_at' => $created])->save();

        return $vehicle;
    }

    private function listing(array $extra = [], ?User $seller = null): PrivateVehicleListing
    {
        $seller ??= User::factory()->create(['role' => 'client']);
        $listing = PrivateVehicleListing::create(array_replace([
            'user_id' => $seller->id, 'active_user_id' => $seller->id,
            'status' => 'published', 'published_at' => now(),
            'title' => 'Toyota Corolla', 'brand_id' => $this->brand->id,
            'vehicle_model_id' => $this->model->id, 'trim_id' => $this->trim->id,
            'engine_type_id' => $this->engine->id, 'drivetrain_id' => $this->drivetrain->id,
            'color_id' => $this->color->id,
            'vehicle_identity' => ['brand' => 'Toyota approuvée', 'model' => 'Corolla approuvée'],
            'year' => 2020, 'mileage_km' => 50_000, 'price' => 3_000_000,
            'city' => 'Ouagadougou', 'description' => 'Description publique de cette voiture.',
            'seller_name' => 'Vendeur particulier', 'phone' => '+22670000001',
            'verified_phone' => '+22670000001', 'phone_verified_at' => now(),
            'whatsapp_enabled' => true, 'review_reason' => 'MODERATION-SECRET',
        ], $extra));
        $listing->photos()->create(['disk' => 'public', 'path' => 'private-listings/'.$listing->id.'/photo.jpg']);

        return $listing;
    }

    private function endpoint(array $query = []): string
    {
        return self::ENDPOINT.($query ? '?'.http_build_query($query) : '');
    }

    public function test_native_numeric_identifiers_and_payloads_are_kept_in_a_typed_envelope(): void
    {
        $vehicle = $this->vehicle();
        $listing = $this->listing();
        $this->assertSame($vehicle->id, $listing->id, 'The sources deliberately have colliding native IDs.');
        $this->brand->update(['name' => 'Renamed brand']);
        $response = $this->getJson($this->endpoint(['sort_by' => 'sale_price', 'sort_dir' => 'asc']))->assertOk();
        $response->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
            ->assertJsonPath('data.0.key', 'vehicle:'.$vehicle->id)
            ->assertJsonPath('data.0.source', 'vehicle')
            ->assertJsonPath('data.0.source_id', $vehicle->id)
            ->assertJsonPath('data.0.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.0.vehicle.commercial.sale_price', 2_000_000)
            ->assertJsonMissingPath('data.0.private_listing')
            ->assertJsonPath('data.1.key', 'private_listing:'.$listing->id)
            ->assertJsonPath('data.1.source_id', $listing->id)
            ->assertJsonPath('data.1.private_listing.id', $listing->id)
            ->assertJsonPath('data.1.private_listing.vehicle_identity.brand', 'Toyota approuvée')
            ->assertJsonPath('data.1.private_listing.photos.0.id', $listing->photos->first()->id)
            ->assertJsonMissingPath('data.1.private_listing.registration_status')
            ->assertJsonMissingPath('data.1.vehicle')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_a_recent_private_listing_is_sorted_before_older_stock(): void
    {
        $vehicle = $this->vehicle(['created_at' => now()->subDays(2)]);
        $listing = $this->listing(['published_at' => now()->subDay()]);
        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('data.0.key', 'private_listing:'.$listing->id)
            ->assertJsonPath('data.1.key', 'vehicle:'.$vehicle->id);
    }

    public function test_effective_prices_drive_both_global_sorting_and_bounds(): void
    {
        $vehicle = $this->vehicle([
            'sale_price' => 4_000_000, 'deal_price' => 2_500_000,
            'deal_starts_at' => now()->subDay(), 'deal_ends_at' => now()->addDay(),
        ]);
        $listing = $this->listing();
        $this->getJson($this->endpoint(['sort_by' => 'sale_price', 'sort_dir' => 'asc']))->assertOk()
            ->assertJsonPath('data.0.key', 'vehicle:'.$vehicle->id)
            ->assertJsonPath('data.0.vehicle.commercial.sale_price', 2_500_000)
            ->assertJsonPath('data.1.key', 'private_listing:'.$listing->id);
        $this->getJson($this->endpoint(['price_max' => 2_600_000]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'vehicle:'.$vehicle->id);
        $this->getJson($this->endpoint(['price_min' => 2_600_000]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'private_listing:'.$listing->id);
        $this->travel(2)->days();
        $this->getJson($this->endpoint(['sort_by' => 'sale_price', 'sort_dir' => 'asc']))->assertOk()
            ->assertJsonPath('data.0.key', 'private_listing:'.$listing->id)
            ->assertJsonPath('data.1.vehicle.commercial.sale_price', 4_000_000);
    }

    public function test_global_pages_are_deterministic_and_only_the_current_page_is_hydrated(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $this->vehicle(['sale_price' => 1000 + $index * 2000]);
            $this->listing(['price' => 2000 + $index * 2000]);
        }
        $retrieved = 0;
        Vehicle::retrieved(function () use (&$retrieved) { $retrieved++; });
        PrivateVehicleListing::retrieved(function () use (&$retrieved) { $retrieved++; });
        $query = ['per_page' => 3, 'sort_by' => 'sale_price', 'sort_dir' => 'asc'];
        $first = $this->getJson($this->endpoint($query))->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.last_page', 4);
        $this->assertSame(3, $retrieved, 'Only the three selected models may be hydrated.');
        $this->assertStringContainsString('per_page=3', $first->json('links.next'));
        $keys = array_column($first->json('data'), 'key');
        for ($page = 2; $page <= 4; $page++) {
            $response = $this->getJson($this->endpoint($query + ['page' => $page]))->assertOk()
                ->assertJsonCount(3, 'data')->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.total', 12);
            array_push($keys, ...array_column($response->json('data'), 'key'));
        }
        $this->assertCount(12, array_unique($keys));
        $this->assertSame($first->json('data'), $this->getJson($this->endpoint($query))->assertOk()->json('data'));
        $this->getJson($this->endpoint($query + ['page' => 5]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('links.next', null);
    }

    public function test_private_filters_cover_known_snapshot_fields_including_minimum_price_and_mileage(): void
    {
        $listing = $this->listing();
        $this->getJson($this->endpoint([
            'brand_id' => $this->brand->id, 'vehicle_model_id' => $this->model->id,
            'trim_id' => $this->trim->id, 'engine_type_id' => $this->engine->id,
            'drivetrain_id' => $this->drivetrain->id, 'color_id' => $this->color->id,
            'q' => 'Toyota', 'city' => 'Ouagadougou', 'year_min' => 2020, 'year_max' => 2020,
            'price_min' => 3_000_000, 'price_max' => 3_000_000, 'mileage_max' => 50_000, 'availability' => 'sale',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_id', $listing->id);
        foreach ([
            ['price_min' => 3_000_001], ['price_max' => 2_999_999], ['mileage_max' => 49_999],
            ['year_min' => 2021], ['year_max' => 2019], ['city' => 'Bobo-Dioulasso'], ['q' => 'Absent'],
            ['brand_id' => 999], ['vehicle_model_id' => 999], ['trim_id' => 999],
            ['engine_type_id' => 999], ['drivetrain_id' => 999], ['color_id' => 999],
        ] as $query) {
            $this->getJson($this->endpoint($query))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_unknown_private_facts_never_satisfy_strict_stock_filters(): void
    {
        $this->listing();
        foreach ([
            ['registration_status' => 'registered'], ['registration_status' => 'unregistered'],
            ['status' => 'in_stock'], ['condition' => 'used'], ['consumption_max' => 10],
            ['origin_country_id' => 1], ['fault_category' => 'engine'], ['vehicle_type' => 'passenger'],
            ['body_style' => 'sedan'], ['location_id' => 1], ['deal_type' => 'flash_sale'],
            ['customs_cleared' => 1], ['without_faults' => 1], ['available_for_rent' => 1], ['deal' => 1],
            ['availability' => 'rent'], ['availability' => 'both'],
        ] as $query) {
            $this->getJson($this->endpoint($query))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson($this->endpoint(['deal' => 0, 'available_for_rent' => 'false']))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_server_and_client_switches_exclude_private_rows_without_closing_stock(): void
    {
        $vehicle = $this->vehicle();
        $this->listing();
        foreach ([0, 'false'] as $include) {
            $this->getJson($this->endpoint(['include_private' => $include]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'vehicle:'.$vehicle->id)->assertJsonPath('meta.total', 1);
        }
        config()->set('private_sales.enabled', false);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    }

    public function test_only_published_listings_of_active_client_sellers_enter_the_union(): void
    {
        $published = $this->listing();
        $this->listing(['status' => 'draft', 'published_at' => null]);
        $this->listing(['status' => 'pending', 'published_at' => null]);
        $this->listing(['status' => 'rejected']);
        $this->listing(['status' => 'sold']);
        $this->listing(['status' => 'withdrawn']);
        $this->listing(['published_at' => null]);
        $this->listing(seller: User::factory()->create(['role' => 'client', 'is_active' => false]));
        $this->listing(seller: User::factory()->create(['role' => 'partner']));
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_id', $published->id)->assertJsonPath('meta.total', 1);
    }

    public function test_the_public_envelope_never_exposes_owner_review_fields_even_to_owner_or_staff(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $this->listing(seller: $owner);
        foreach ([$owner, User::factory()->create(['role' => 'manager'])] as $actor) {
            Sanctum::actingAs($actor);
            $response = $this->getJson(self::ENDPOINT)->assertOk();
            foreach (['phone', 'phone_claimed', 'status', 'revision', 'review_reason', 'owned_vehicle_id', 'user_id', 'reviewed_by'] as $field) {
                $response->assertJsonMissingPath('data.0.private_listing.'.$field);
            }
            $this->assertStringNotContainsString('MODERATION-SECRET', $response->getContent());
            $response->assertJsonPath('data.0.private_listing.phone_verified', true);
        }
    }

    public function test_partner_drafts_stay_hidden_and_connected_owners_receive_the_public_promotion_price(): void
    {
        $owner = User::factory()->create(['role' => 'partner']);
        $account = PartnerAccount::create([
            'user_id' => $owner->id, 'company_name' => 'Concession', 'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($owner->id), 'status' => 'approved',
        ]);
        $published = $this->vehicle([
            'owner_partner_account_id' => $account->id, 'partner_published_at' => now(),
            'sale_price' => 4_000_000, 'deal_price' => 3_000_000,
            'deal_starts_at' => now()->subDay(), 'deal_ends_at' => now()->addDay(),
            'partner_pricing' => ['taux' => 10, 'prix_proposes' => ['sale_price' => 3_600_000]],
        ]);
        $this->vehicle(['owner_partner_account_id' => $account->id, 'partner_published_at' => null]);
        foreach ([$owner, User::factory()->create(['role' => 'manager'])] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.source_id', $published->id)
                ->assertJsonPath('data.0.vehicle.commercial.sale_price', 3_000_000)
                ->assertJsonMissingPath('data.0.vehicle.owner_partner_account_id')
                ->assertJsonMissingPath('data.0.vehicle.partner_pricing');
        }
    }

    public function test_legacy_public_and_staff_vehicle_lists_keep_their_original_contract(): void
    {
        $vehicle = $this->vehicle();
        $this->listing();
        foreach ([null, User::factory()->create(['role' => 'manager'])] as $staff) {
            if ($staff) {
                Sanctum::actingAs($staff);
            }
            $url = $staff ? '/api/vehicles' : '/api/catalog/vehicles';
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $vehicle->id)->assertJsonPath('meta.total', 1)
                ->assertJsonMissingPath('data.0.source')->assertJsonMissingPath('data.0.vehicle')
                ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
        }
    }

    public function test_new_endpoint_validates_query_shapes_and_keeps_unknown_sorts_safe(): void
    {
        foreach (['q[]=x', 'brand_id[]=1', 'per_page=0', 'per_page=101', 'page=-1', 'include_private[]=1', 'price_min[]=1', 'sort_by[]=created_at'] as $query) {
            $this->getJson(self::ENDPOINT.'?'.$query)->assertUnprocessable();
        }
        $this->getJson($this->endpoint(['sort_by' => 'purchase_price', 'sort_dir' => 'invalid']))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.last_page', 1)->assertJsonPath('links.next', null);
    }
}
