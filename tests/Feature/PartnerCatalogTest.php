<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\CustomerNeed;
use App\Models\EngineType;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerCatalogTest extends TestCase
{
    use RefreshDatabase;

    private PartnerAccount $account;
    private PartnerAccount $other;
    private array $vehicleIdentity;
    private PartCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->account('Mon entreprise');
        $this->other = $this->account('Autre entreprise');
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->vehicleIdentity = [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2020,
        ];
        $this->category = PartCategory::create(['name' => 'Filtres', 'slug' => 'filtres']);
        Sanctum::actingAs($this->account->user);
    }

    private function account(string $company): PartnerAccount
    {
        return PartnerAccount::create([
            'user_id' => User::factory()->create(['role' => 'partner'])->id,
            'company_name' => $company, 'contact_name' => 'Responsable',
            'phone' => '+22670000000', 'status' => 'approved',
        ]);
    }

    private function payload(string $type): array
    {
        return match ($type) {
            'vehicles' => $this->vehicleIdentity + ['availability' => 'rent', 'rental_monthly_rate' => 500_000],
            'parts' => [
                'name' => 'Filtre', 'part_category_id' => $this->category->id,
                'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 5000,
            ],
            'accessories' => ['name' => 'Support téléphone', 'category' => 'confort', 'selling_price' => 5000],
        };
    }

    private function product(string $type, ?PartnerAccount $owner, array $extra = []): Model
    {
        $class = match ($type) {
            'vehicles' => Vehicle::class,
            'parts' => Part::class,
            'accessories' => Accessory::class,
        };
        $data = array_replace($this->payload($type), [
            'owner_partner_account_id' => $owner?->id,
        ], $extra);
        if ($type === 'vehicles') {
            $data['reference'] = fake()->unique()->bothify('PARTNER-########');
        }

        return $class::create($data);
    }

    public static function productTypes(): array
    {
        return ['véhicules' => ['vehicles'], 'pièces' => ['parts'], 'accessoires' => ['accessories']];
    }

    #[DataProvider('productTypes')]
    public function test_an_approved_partner_creates_an_owned_private_draft(string $type): void
    {
        $response = $this->postJson('/api/partner/'.$type, $this->payload($type))->assertCreated();
        $id = $response->json('data.id');

        $this->assertDatabaseHas($type, [
            'id' => $id, 'owner_partner_account_id' => $this->account->id,
            'partner_published_at' => null,
        ]);
        $response->assertJsonPath('data.owner_partner_account_id', $this->account->id)
            ->assertJsonPath('data.partner_published_at', null);
    }

    #[DataProvider('productTypes')]
    public function test_lists_only_include_the_current_partners_products(string $type): void
    {
        $own = $this->product($type, $this->account);
        $this->product($type, $this->other, ['partner_published_at' => now()]);
        $this->product($type, null);

        $this->getJson('/api/partner/'.$type)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.total', 1);
    }

    #[DataProvider('productTypes')]
    public function test_partners_can_read_update_and_archive_their_own_products(string $type): void
    {
        $own = $this->product($type, $this->account);
        $path = '/api/partner/'.$type.'/'.$own->id;
        $this->getJson($path)->assertOk()->assertJsonPath('data.id', $own->id);
        $this->patchJson($path, $this->payload($type) + ['description' => 'Mon annonce mise à jour'])->assertOk();
        $this->assertDatabaseHas($type, ['id' => $own->id, 'description' => 'Mon annonce mise à jour']);
        $this->deleteJson($path)->assertOk();
        $this->assertSoftDeleted($type, ['id' => $own->id]);
    }

    #[DataProvider('productTypes')]
    public function test_foreign_and_internal_products_cannot_be_read_or_mutated(string $type): void
    {
        foreach ([$this->other, null] as $owner) {
            $product = $this->product($type, $owner);
            $before = $product->fresh()->getAttributes();
            $path = '/api/partner/'.$type.'/'.$product->id;

            $this->getJson($path)->assertNotFound();
            $this->patchJson($path, [])->assertNotFound();
            $this->deleteJson($path)->assertNotFound();
            $this->postJson($path.'/publish')->assertNotFound();
            $this->postJson($path.'/unpublish')->assertNotFound();
            $this->assertSame($before, $product->fresh()->getAttributes());
        }
    }

    #[DataProvider('productTypes')]
    public function test_publication_and_unpublication_are_explicit_owner_actions(string $type): void
    {
        $product = $this->product($type, $this->account);
        $path = '/api/partner/'.$type.'/'.$product->id;

        $this->postJson($path.'/publish')->assertOk();
        $this->assertNotNull($product->fresh()->partner_published_at);
        $this->postJson($path.'/unpublish')->assertOk();
        $this->assertNull($product->fresh()->partner_published_at);
    }

    #[DataProvider('productTypes')]
    public function test_ownership_and_staff_fields_cannot_be_injected(string $type): void
    {
        foreach (['owner_partner_account_id', 'partner_published_at', 'published_at', 'created_by', 'user_id', 'location_id', 'partner_id'] as $field) {
            $this->postJson('/api/partner/'.$type, $this->payload($type) + [$field => null])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount($type, 0);

        $own = $this->product($type, $this->account);
        $this->patchJson('/api/partner/'.$type.'/'.$own->id, $this->payload($type) + [
            'owner_partner_account_id' => $this->other->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_partner_account_id');
        $this->assertSame($this->account->id, $own->fresh()->owner_partner_account_id);
    }

    public function test_used_parts_can_only_reference_a_donor_vehicle_owned_by_the_partner(): void
    {
        $own = $this->product('vehicles', $this->account);
        $foreign = $this->product('vehicles', $this->other);
        $internal = $this->product('vehicles', null);
        $payload = array_replace($this->payload('parts'), ['condition' => 'used']);

        foreach ([$foreign, $internal] as $donor) {
            $this->postJson('/api/partner/parts', $payload + ['donor_vehicle_id' => $donor->id])
                ->assertUnprocessable()->assertJsonValidationErrors('donor_vehicle_id');
        }
        $this->postJson('/api/partner/parts', $payload + ['donor_vehicle_id' => $own->id])->assertCreated();
        $this->assertDatabaseHas('parts', ['donor_vehicle_id' => $own->id, 'owner_partner_account_id' => $this->account->id]);
    }

    public function test_partner_drafts_do_not_notify_the_platforms_client_needs(): void
    {
        foreach (['parts' => 'part', 'accessories' => 'accessory'] as $type => $needType) {
            CustomerNeed::create([
                'type' => $needType, 'description' => 'Recherche correspondante', 'firebase_uid' => 'client-global',
                'part_category_id' => $this->category->id, 'accessory_category' => 'confort',
            ]);
            $this->postJson('/api/partner/'.$type, $this->payload($type))->assertCreated();
        }
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_a_partner_can_attach_an_existing_normalized_oem_reference_without_creating_one(): void
    {
        $oem = OemNumber::create(['number' => '90915-YZZD4', 'brand_id' => $this->vehicleIdentity['brand_id']]);

        $response = $this->postJson('/api/partner/parts', $this->payload('parts') + [
            'oem_numbers' => [['number' => '90915yzzd4', 'brand_id' => $this->vehicleIdentity['brand_id']]],
        ])->assertCreated();

        $this->assertDatabaseCount('oem_numbers', 1);
        $this->assertDatabaseHas('oem_number_part', ['part_id' => $response->json('data.id'), 'oem_number_id' => $oem->id]);
    }

    public function test_new_or_wrongly_branded_oem_references_are_rejected_without_global_mutation(): void
    {
        $oem = OemNumber::create(['number' => '90915-YZZD4', 'brand_id' => $this->vehicleIdentity['brand_id']]);
        $before = $oem->fresh()->getAttributes();

        foreach ([['number' => 'NOUVELLE-REFERENCE'], ['number' => '90915-YZZD4']] as $reference) {
            $this->postJson('/api/partner/parts', $this->payload('parts') + ['oem_numbers' => [$reference]])
                ->assertUnprocessable()->assertJsonValidationErrors('oem_numbers.0.number');
        }

        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('oem_numbers', 1);
        $this->assertSame($before, $oem->fresh()->getAttributes());
    }

    #[DataProvider('productTypes')]
    public function test_inventory_requests_validate_pagination_instead_of_loading_unbounded_data(string $type): void
    {
        $this->getJson('/api/partner/'.$type.'?per_page=101')->assertUnprocessable();
        $this->getJson('/api/partner/'.$type.'?per_page[]=1')->assertUnprocessable();
    }

    #[DataProvider('productTypes')]
    public function test_product_responses_only_expose_the_products_public_image_gallery(string $type): void
    {
        $product = $this->product($type, $this->account);
        $image = $product->media()->create([
            'collection' => 'gallery', 'mime_type' => 'image/jpeg', 'disk' => 'public',
            'path' => $type.'/'.$product->id.'/photo.jpg',
        ]);
        foreach ([
            ['collection' => 'documents'],
            ['disk' => 'local'],
            ['path' => 'another-product/private.jpg'],
        ] as $extra) {
            $product->media()->create(array_replace([
                'collection' => 'gallery', 'mime_type' => 'image/jpeg', 'disk' => 'public',
                'path' => $type.'/'.$product->id.'/private.jpg',
            ], $extra));
        }

        $this->getJson('/api/partner/'.$type.'/'.$product->id)->assertOk()
            ->assertJsonCount(1, 'data.media')->assertJsonPath('data.media.0.id', $image->id);
        $this->getJson('/api/partner/'.$type)->assertOk()
            ->assertJsonCount(1, 'data.0.media')->assertJsonPath('data.0.media.0.id', $image->id);
    }
}
