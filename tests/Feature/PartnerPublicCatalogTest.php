<?php

namespace Tests\Feature;

use App\Http\Resources\AccessoryResource;
use App\Http\Resources\PartResource;
use App\Http\Resources\VehicleResource;
use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Country;
use App\Models\EngineType;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Partner;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerPublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $status = 'approved', bool $active = true): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => $active]);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Garage du partenaire '.$user->id,
            'contact_name' => 'Contact prive',
            'phone' => $this->numeroDePartenaire($user->id),
            'whatsapp' => '+226 71 00 22 44',
            'status' => $status,
            'review_reason' => 'NOTE-MODERATION-PRIVEE',
        ]);
    }

    private function vehicleModel(): VehicleModel
    {
        $brand = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);

        return VehicleModel::firstOrCreate([
            'brand_id' => $brand->id,
            'slug' => 'corolla-test',
        ], ['name' => 'Corolla', 'production_start' => 2010]);
    }

    private function listing(string $type, ?PartnerAccount $account = null, bool $published = true): Model
    {
        $ownership = [
            'owner_partner_account_id' => $account?->id,
            'partner_published_at' => $account && $published ? now() : null,
        ];

        return match ($type) {
            'vehicles' => Vehicle::create(array_merge([
                'reference' => 'VEH-'.Str::random(10),
                'vin' => 'JTDBR32E63'.str_pad((string) (Vehicle::withTrashed()->count() + 1), 7, '0', STR_PAD_LEFT),
                'brand_id' => $this->vehicleModel()->brand_id,
                'vehicle_model_id' => $this->vehicleModel()->id,
                'engine_type_id' => EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence'])->id,
                'manufacturing_year' => 2020,
                'registration_status' => 'registered',
                'availability' => 'sale',
                'condition' => 'used',
                'status' => 'in_stock',
                'purchase_price' => 3_333_111,
                'sale_price' => 4_500_000,
            ], $ownership)),
            'parts' => Part::create(array_merge([
                'sku' => 'PRT-'.Str::random(10),
                'name' => 'Filtre du vendeur',
                'part_category_id' => PartCategory::firstOrCreate(['slug' => 'filtres'], ['name' => 'Filtres'])->id,
                'type' => 'aftermarket',
                'condition' => 'new',
                'cost_price' => 1_793,
                'selling_price' => 3_000,
                'stock_quantity' => 43,
                'stock_alert_threshold' => 29,
                'storage_location' => 'RESERVE-PRIVEE-43',
                'is_active' => true,
                'is_available' => true,
            ], $ownership)),
            'accessories' => Accessory::create(array_merge([
                'sku' => 'ACC-'.Str::random(10),
                'name' => 'Camera du vendeur',
                'category' => 'securite',
                'cost_price' => 7_919,
                'selling_price' => 12_500,
                'stock_quantity' => 43,
                'stock_alert_threshold' => 29,
                'storage_location' => 'RESERVE-PRIVEE-43',
                'is_active' => true,
                'is_available' => true,
            ], $ownership)),
        };
    }

    private function sharePath(string $type, Model $listing): string
    {
        return '/'.['vehicles' => 'v', 'parts' => 'p', 'accessories' => 'a'][$type].'/'.$listing->id;
    }

    public function test_le_public_ne_voit_que_les_annonces_publiees_des_comptes_valides_et_le_catalogue_historique(): void
    {
        $approved = $this->account();
        $blocked = [$this->account('pending'), $this->account('rejected'), $this->account('suspended'), $this->account(active: false)];

        foreach (['vehicles', 'parts', 'accessories'] as $type) {
            $legacy = $this->listing($type);
            $visible = $this->listing($type, $approved);
            $hidden = [$this->listing($type, $approved, published: false)];

            foreach ($blocked as $account) {
                $hidden[] = $this->listing($type, $account);
            }

            $response = $this->getJson('/api/catalog/'.$type)->assertOk();
            $this->assertEqualsCanonicalizing([$legacy->id, $visible->id], array_column($response->json('data'), 'id'));
            $this->getJson('/api/catalog/'.$type.'/'.$visible->id)->assertOk();
            $this->get($this->sharePath($type, $visible))->assertOk();
            $this->get($this->sharePath($type, $legacy))->assertOk();

            foreach ($hidden as $listing) {
                $this->getJson('/api/catalog/'.$type.'/'.$listing->id)->assertNotFound();
                $this->get($this->sharePath($type, $listing))->assertNotFound();
            }
        }
    }

    public function test_la_fiche_publique_du_vehicule_masque_identifiants_documents_et_fournisseur(): void
    {
        $account = $this->account();
        $vehicle = $this->listing('vehicles', $account);
        $country = Country::firstOrCreate(['iso2' => 'BF'], ['iso3' => 'BFA', 'name' => 'Burkina Faso']);
        $vehicle->registrationDetail()->create([
            'plate_number' => 'PLAQUE-PRIVEE',
            'registration_country_id' => $country->id,
            'registration_certificate_no' => 'CERTIFICAT-PRIVE',
        ]);
        $vehicle->importDetail()->create([
            'origin_country_id' => $country->id,
            'supplier_name' => 'FOURNISSEUR-PRIVE',
            'customs_declaration_no' => 'DOUANE-PRIVEE',
        ]);
        $vehicle->media()->create(['collection' => 'documents', 'path' => 'CERTIFICAT-PRIVE.pdf', 'disk' => 'public']);
        $vehicle->media()->create(['collection' => 'gallery', 'path' => 'vehicles/'.$vehicle->id.'/photo-publique.jpg', 'disk' => 'public', 'mime_type' => 'image/jpeg']);

        $data = $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->json('data');

        foreach (['vin', 'reference', 'registration', 'import', 'faults', 'faults_count', 'fault_score', 'owner_partner_account_id', 'partner_published_at'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }

        foreach (['location', 'location_id', 'partner', 'partner_id', 'site'] as $field) {
            $this->assertArrayNotHasKey($field, $data['commercial']);
        }

        $this->assertSame(['id', 'company_name', 'phone', 'whatsapp'], array_keys($data['seller']));
        $this->assertSame($account->company_name, $data['seller']['company_name']);
        $this->assertCount(1, $data['media']);
        $this->assertStringContainsString('photo-publique.jpg', $data['media'][0]['url']);
        $this->assertStringNotContainsString('PRIVE', json_encode($data));
    }

    public function test_le_stock_exact_et_les_notes_fournisseur_ne_sortent_pas_des_annonces_partenaires(): void
    {
        $account = $this->account();
        $supplier = Partner::create(['company_name' => 'FOURNISSEUR-PRIVE', 'phone' => '+22670009900', 'notes' => 'NOTE-FOURNISSEUR-PRIVEE']);
        $request = Request::create('/api/catalog/parts');

        foreach (['parts' => PartResource::class, 'accessories' => AccessoryResource::class] as $type => $resource) {
            $listing = $this->listing($type, $account);
            $listing->partners()->attach($supplier->id, ['role' => 'fournisseur', 'notes' => 'NOTE-PIVOT-PRIVEE']);
            $listing->media()->create(['collection' => 'documents', 'path' => 'facture-privee.pdf', 'disk' => 'public']);
            $listing->media()->create(['collection' => 'gallery', 'path' => $type.'/'.$listing->id.'/photo-publique.jpg', 'disk' => 'public', 'mime_type' => 'image/jpeg']);
            $listing->load(['partners', 'media']);

            $data = (new $resource($listing))->resolve($request);

            $this->assertSame(['is_available' => true], $data['stock']);
            $this->assertArrayNotHasKey('partners', $data);
            $this->assertArrayNotHasKey('donor_vehicle', $data);
            $this->assertArrayNotHasKey('sku', $data);
            $this->assertCount(1, $data['media']);
            $this->assertStringNotContainsString('PRIVE', json_encode($data));
            $this->assertStringNotContainsString('facture-privee', json_encode($data));
        }
    }

    public function test_seuls_le_proprietaire_et_le_personnel_gardent_les_donnees_de_gestion(): void
    {
        $account = $this->account();
        $owner = $account->user;
        $other = $this->account()->user;
        $staff = User::factory()->create(['role' => 'viewer']);

        foreach (['vehicles' => VehicleResource::class, 'parts' => PartResource::class, 'accessories' => AccessoryResource::class] as $type => $resource) {
            $listing = $this->listing($type, $account);

            foreach ([$owner, $staff, $other] as $user) {
                $request = Request::create('/api/partner/'.$type);
                $request->setUserResolver(fn () => $user);
                $data = (new $resource($listing))->resolve($request);
                $private = $user->id !== $other->id;

                $this->assertSame($private, array_key_exists('owner_partner_account_id', $data));

                if ($type === 'vehicles') {
                    $this->assertSame($private, array_key_exists('vin', $data));
                } else {
                    $this->assertSame($private, array_key_exists('quantity', $data['stock']));
                }
            }
        }
    }

    public function test_le_contrat_historique_n_est_pas_reduit_par_la_frontiere_partenaire(): void
    {
        $vehicle = $this->listing('vehicles');
        $part = $this->listing('parts');

        $this->getJson('/api/catalog/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.vin', $vehicle->vin);
        $this->getJson('/api/catalog/parts/'.$part->id)->assertOk()->assertJsonPath('data.stock.quantity', 43);
    }

    public function test_les_pages_de_partage_contactent_le_vendeur_et_ne_prennent_pas_un_document_pour_photo(): void
    {
        config(['boutique.telephone' => '+22670009988', 'boutique.whatsapp' => '22670009988']);
        $account = $this->account();

        foreach (['vehicles', 'parts', 'accessories'] as $type) {
            $listing = $this->listing($type, $account);
            $listing->media()->create(['collection' => 'documents', 'path' => 'facture-privee.pdf', 'disk' => 'public', 'is_cover' => true]);
            $listing->media()->create(['collection' => 'gallery', 'path' => $type.'/'.$listing->id.'/photo-publique.jpg', 'disk' => 'public', 'mime_type' => 'image/jpeg']);

            $html = $this->get($this->sharePath($type, $listing))->assertOk()->getContent();

            $this->assertStringContainsString($account->company_name, $html);
            $this->assertStringContainsString('https://wa.me/22671002244?text=', $html);
            // Le numero vient du compte et non d'une constante : chaque compte
            // partenaire a desormais le sien, `phone_key` etant unique.
            $this->assertStringContainsString('href="tel:'.$account->phone.'"', $html);
            $this->assertStringContainsString('photo-publique.jpg', $html);
            $this->assertStringNotContainsString('facture-privee.pdf', $html);
            $this->assertStringNotContainsString('22670009988', $html);
            $this->assertStringNotContainsString('NOTE-MODERATION-PRIVEE', $html);
        }
    }

    public function test_la_recherche_de_pieces_compatibles_exclut_les_annonces_cachees(): void
    {
        $account = $this->account();
        $legacy = $this->listing('parts');
        $visible = $this->listing('parts', $account);
        $draft = $this->listing('parts', $account, published: false);

        foreach ([$legacy, $visible, $draft] as $part) {
            $part->fitments()->create(['vehicle_model_id' => $this->vehicleModel()->id, 'source' => 'declared']);
        }

        $data = $this->getJson('/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $this->vehicleModel()->id,
            'year' => 2020,
        ]))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$legacy->id, $visible->id], array_column($data, 'id'));
    }

    public function test_la_recherche_oem_ne_revele_pas_un_vehicule_non_publie(): void
    {
        $account = $this->account();
        $legacy = $this->listing('vehicles');
        $visible = $this->listing('vehicles', $account);
        $this->listing('vehicles', $account, published: false);
        $oem = OemNumber::create(['number' => '90915-TEST', 'normalized_number' => '90915TEST']);
        $oem->fitments()->create(['vehicle_model_id' => $this->vehicleModel()->id]);

        $data = $this->getJson('/api/oem/90915-TEST/vehicles')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$legacy->id, $visible->id], array_column($data, 'id'));
    }

    public function test_les_categories_publiques_n_annoncent_pas_les_brouillons_partenaires(): void
    {
        $account = $this->account();
        $visiblePart = $this->listing('parts');
        $hiddenCategory = PartCategory::create(['name' => 'Categorie privee', 'slug' => 'categorie-privee']);
        $draftPart = $this->listing('parts', $account, published: false);
        $draftPart->update(['part_category_id' => $hiddenCategory->id]);
        $this->listing('accessories');
        $draftAccessory = $this->listing('accessories', $account, published: false);
        $draftAccessory->update(['category' => 'utilitaire']);

        $parts = $this->getJson('/api/catalog/part-categories?non_empty=1')->assertOk()->json();
        $accessories = $this->getJson('/api/catalog/accessory-categories')->assertOk()->json();

        $this->assertSame([$visiblePart->part_category_id], array_column($parts, 'id'));
        $this->assertSame(['securite'], $accessories);
    }

    public function test_un_client_ne_peut_pas_tester_la_compatibilite_d_une_piece_non_publiee(): void
    {
        $account = $this->account();
        $visible = $this->listing('parts', $account);
        $hidden = $this->listing('parts', $account, published: false);

        $this->actingAsClient();
        $this->getJson('/api/my/parts/'.$visible->id.'/garage-compatibility')->assertOk();
        $this->getJson('/api/my/parts/'.$hidden->id.'/garage-compatibility')->assertNotFound();
    }
}
