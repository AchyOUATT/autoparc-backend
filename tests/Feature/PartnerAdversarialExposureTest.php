<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accessory;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerAdversarialExposureTest extends TestCase
{
    use RefreshDatabase;

    private function seller(string $name): PartnerAccount
    {
        return PartnerAccount::create([
            'user_id' => User::factory()->create(['role' => UserRole::Partner])->id,
            'company_name' => $name,
            'contact_name' => 'Contact fictif',
            'phone' => '0000000000',
            'status' => 'approved',
        ]);
    }

    private function products(PartnerAccount $seller, bool $published): array
    {
        $brand = Brand::create(['name' => 'Marque fictive', 'slug' => 'marque-fictive']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Modèle fictif']);
        $engine = EngineType::create(['code' => 'test-petrol', 'label' => 'Essence fictive']);
        $category = PartCategory::create(['name' => 'Catégorie fictive', 'slug' => 'categorie-fictive']);
        $ownership = [
            'owner_partner_account_id' => $seller->id,
            'partner_published_at' => $published ? now() : null,
        ];

        return [
            'vehicles' => Vehicle::create([
                'reference' => 'PRIVATE-VEHICLE',
                'vin' => 'PRIVATEVIN0000001',
                'brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'engine_type_id' => $engine->id,
                'manufacturing_year' => 2019,
                'sale_price' => 1000,
                ...$ownership,
            ]),
            'parts' => Part::create([
                'name' => 'Pièce fictive',
                'sku' => 'PRIVATE-PART',
                'part_category_id' => $category->id,
                'type' => 'aftermarket',
                'condition' => 'new',
                'selling_price' => 1000,
                'stock_quantity' => 41,
                'stock_alert_threshold' => 13,
                'storage_location' => 'PRIVATE-BIN',
                'is_available' => true,
                ...$ownership,
            ]),
            'accessories' => Accessory::create([
                'name' => 'Accessoire fictif',
                'sku' => 'PRIVATE-ACCESSORY',
                'category' => 'confort',
                'selling_price' => 1000,
                'stock_quantity' => 31,
                'stock_alert_threshold' => 12,
                'storage_location' => 'PRIVATE-BIN',
                'is_available' => true,
                ...$ownership,
            ]),
        ];
    }

    public function test_les_filtres_publics_ne_contournent_pas_les_brouillons_et_suspensions(): void
    {
        $seller = $this->seller('Vendeur fictif');
        $products = $this->products($seller, true);
        // These flags can relax catalogue filters but must never relax the
        // independent account/publication boundary.
        $flags = '?active_only=0&in_stock=1&include_candidates=1&per_page=100';

        foreach ($products as $kind => $product) {
            $this->getJson('/api/catalog/'.$kind.$flags)->assertOk()->assertJsonCount(1, 'data');
            $product->update(['partner_published_at' => null]);
            $this->getJson('/api/catalog/'.$kind.$flags)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/catalog/'.$kind.'/'.$product->id)->assertNotFound();
            $product->update(['partner_published_at' => now()]);
        }

        foreach (['pending', 'rejected', 'suspended'] as $status) {
            $seller->update(['status' => $status]);

            foreach ($products as $kind => $product) {
                $this->getJson('/api/catalog/'.$kind.$flags)->assertOk()->assertJsonCount(0, 'data');
                $this->getJson('/api/catalog/'.$kind.'/'.$product->id)->assertNotFound();
            }
        }

        $seller->update(['status' => 'approved']);
        $seller->user->update(['is_active' => false]);

        foreach ($products as $kind => $product) {
            $this->getJson('/api/catalog/'.$kind.$flags)->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/catalog/'.$kind.'/'.$product->id)->assertNotFound();
        }
    }

    public function test_un_autre_partenaire_lit_la_fiche_publique_sans_les_donnees_privees(): void
    {
        $owner = $this->seller('Entreprise publique fictive');
        $other = $this->seller('Autre entreprise fictive');
        $products = $this->products($owner, true);
        Sanctum::actingAs($other->user, ['partner']);

        foreach ($products as $kind => $product) {
            $response = $this->getJson('/api/catalog/'.$kind.'/'.$product->id)
                ->assertOk()
                ->assertJsonPath('data.seller.company_name', $owner->company_name)
                ->assertJsonMissingPath('data.owner_partner_account_id')
                ->assertJsonMissingPath('data.partner_published_at')
                ->assertJsonMissingPath('data.sku')
                ->assertJsonMissingPath('data.reference')
                ->assertJsonMissingPath('data.vin')
                ->assertJsonMissingPath('data.import')
                ->assertJsonMissingPath('data.registration')
                ->assertJsonMissingPath('data.stock.quantity')
                ->assertJsonMissingPath('data.stock.alert_threshold')
                ->assertJsonMissingPath('data.stock.storage_location');

            $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
            $this->getJson('/api/partner/'.$kind.'/'.$product->id)->assertNotFound();
        }

        // Relaxing stock/active filters must not return the private shape.
        $this->getJson('/api/catalog/parts?active_only=0&in_stock=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.stock.quantity')
            ->assertJsonMissingPath('data.0.stock.storage_location');
    }

    public function test_les_filtres_ne_republient_pas_les_produits_desactives_ou_les_vehicules_vendus(): void
    {
        $seller = $this->seller('Vendeur fictif');
        $products = $this->products($seller, true);
        $products['parts']->update(['is_active' => false]);
        $products['accessories']->update(['is_active' => false]);

        foreach (['sold', 'archived'] as $status) {
            $products['vehicles']->update(['status' => $status]);
            foreach ($products as $kind => $product) {
                $this->getJson('/api/catalog/'.$kind.'?active_only=0&status='.$status)
                    ->assertOk()->assertJsonCount(0, 'data');
                $this->getJson('/api/catalog/'.$kind.'/'.$product->id)->assertNotFound();
                $path = ['vehicles' => 'v', 'parts' => 'p', 'accessories' => 'a'][$kind];
                $this->get('/'.$path.'/'.$product->id)->assertNotFound();
            }
        }
    }

    public function test_les_categories_ne_revelent_pas_les_annonces_non_publiques(): void
    {
        $seller = $this->seller('Vendeur fictif');
        $products = $this->products($seller, false);
        $partCategories = '/api/catalog/part-categories?non_empty=1';
        $accessoryCategories = '/api/catalog/accessory-categories';

        $this->getJson($partCategories)->assertOk()->assertExactJson([]);
        $this->getJson($accessoryCategories)->assertOk()->assertExactJson([]);

        foreach ($products as $product) {
            $product->update(['partner_published_at' => now()]);
        }

        $this->getJson($partCategories)->assertOk()->assertJsonCount(1);
        $this->getJson($accessoryCategories)->assertOk()->assertExactJson(['confort']);
        $seller->update(['status' => 'suspended']);

        $this->getJson($partCategories)->assertOk()->assertExactJson([]);
        $this->getJson($accessoryCategories)->assertOk()->assertExactJson([]);

        $seller->update(['status' => 'approved']);
        $seller->user->update(['is_active' => false]);
        $this->getJson($partCategories)->assertOk()->assertExactJson([]);
        $this->getJson($accessoryCategories)->assertOk()->assertExactJson([]);
    }
}
