<?php

namespace Tests\Feature;

use App\Http\Resources\VehicleResource;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Partner;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\PartnerListingPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerListingContactTest extends TestCase
{
    use RefreshDatabase;

    private function modele(): VehicleModel
    {
        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);

        return VehicleModel::firstOrCreate([
            'brand_id' => $marque->id,
            'slug' => 'corolla-contact',
        ], ['name' => 'Corolla', 'production_start' => 2010]);
    }

    private function vehicule(array $attributs = []): Vehicle
    {
        $modele = $this->modele();

        return Vehicle::create(array_merge([
            'reference' => 'VEH-'.Str::random(10),
            'vin' => 'JTDBR32E63'.str_pad((string) (Vehicle::withTrashed()->count() + 1), 7, '0', STR_PAD_LEFT),
            'brand_id' => $modele->brand_id,
            'vehicle_model_id' => $modele->id,
            'engine_type_id' => EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence'])->id,
            'manufacturing_year' => 2020,
            'registration_status' => 'registered',
            'availability' => 'sale',
            'condition' => 'used',
            'status' => 'in_stock',
            'sale_price' => 4_500_000,
        ], $attributs));
    }

    private function compte(array $attributs = []): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner']);

        return PartnerAccount::create(array_merge([
            'user_id' => $user->id,
            'company_name' => 'Garage du partenaire '.$user->id,
            'contact_name' => 'Contact partenaire',
            'phone' => $this->numeroDePartenaire($user->id),
            'whatsapp' => '+22671002244',
            'status' => 'approved',
        ], $attributs));
    }

    public function test_un_vehicule_rattache_par_le_personnel_garde_le_numero_de_la_plateforme(): void
    {
        config(['boutique.telephone' => '+22675570484', 'boutique.whatsapp' => '22675570484']);

        $fournisseur = Partner::create([
            'company_name' => 'Garage historique',
            'contact_name' => 'Contact interne',
            'phone' => '70003344',
            'whatsapp' => '71003344',
            'is_active' => true,
        ]);
        $vehicule = $this->vehicule(['partner_id' => $fournisseur->id]);

        $this->getJson('/api/catalog/vehicles/'.$vehicule->id)
            ->assertOk()
            ->assertJsonMissingPath('data.seller');

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();
        $this->assertStringContainsString('href="tel:+22675570484"', $html);
        $this->assertStringContainsString('https://wa.me/22675570484?text=', $html);
        $this->assertStringNotContainsString('Garage historique', $html);
        $this->assertStringNotContainsString('70003344', $html);
        $this->assertStringNotContainsString('71003344', $html);
    }

    public function test_un_vehicule_de_la_boutique_garde_le_numero_de_la_plateforme(): void
    {
        config(['boutique.telephone' => '+22675570484', 'boutique.whatsapp' => '22675570484']);
        $vehicule = $this->vehicule();

        $this->getJson('/api/catalog/vehicles/'.$vehicule->id)
            ->assertOk()
            ->assertJsonMissingPath('data.seller');

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();
        $this->assertStringContainsString('href="tel:+22675570484"', $html);
        $this->assertStringContainsString('https://wa.me/22675570484?text=', $html);
    }

    public function test_le_compte_partenaire_approuve_reste_le_contact_public_et_normalise_son_numero(): void
    {
        $compte = $this->compte([
            'phone' => '70005566',
            'whatsapp' => '71 00 55 66',
        ]);
        $vehicule = $this->vehicule([
            'owner_partner_account_id' => $compte->id,
            'partner_published_at' => now(),
        ]);

        $data = $this->getJson('/api/catalog/vehicles/'.$vehicule->id)->assertOk()->json('data');

        $this->assertSame($compte->id, $data['seller']['id']);
        $this->assertSame($compte->company_name, $data['seller']['company_name']);
        $this->assertSame('+22670005566', $data['seller']['phone']);
        $this->assertSame('+22671005566', $data['seller']['whatsapp']);
        $this->assertArrayNotHasKey('partner', $data['commercial'] ?? []);
    }

    public function test_un_compte_partenaire_sans_numero_composable_se_rabatte_sur_la_boutique(): void
    {
        config(['boutique.telephone' => '+22675570484', 'boutique.whatsapp' => '22675570484']);
        $compte = $this->compte(['phone' => '123', 'whatsapp' => null]);
        $vehicule = $this->vehicule([
            'owner_partner_account_id' => $compte->id,
            'partner_published_at' => now(),
        ]);

        $data = $this->getJson('/api/catalog/vehicles/'.$vehicule->id)->assertOk()->json('data');
        $this->assertNull($data['seller']);

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();
        $this->assertStringContainsString('href="tel:+22675570484"', $html);
    }

    public function test_un_compte_partenaire_non_approuve_n_expose_pas_son_numero(): void
    {
        $compte = $this->compte(['status' => 'pending']);
        $vehicule = $this->vehicule([
            'owner_partner_account_id' => $compte->id,
            'partner_published_at' => now(),
        ]);

        // L'annonce elle-meme n'est pas au catalogue public. Le bloc vendeur
        // est aussi refuse si on resout la ressource hors catalogue : le
        // numero de la boutique reste le repli.
        $this->getJson('/api/catalog/vehicles/'.$vehicule->id)->assertNotFound();

        $ressource = (new VehicleResource($vehicule->load('sellerAccount')))
            ->resolve(request());
        $this->assertNull($ressource['seller']);
        $this->assertNull(PartnerListingPresentation::seller($vehicule));
    }
}
