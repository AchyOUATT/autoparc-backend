<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Partner;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
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

    public function test_le_catalogue_expose_le_numero_du_partenaire_rattache_par_le_personnel(): void
    {
        $partenaire = Partner::create([
            'company_name' => 'Garage historique',
            'contact_name' => 'Contact interne',
            'phone' => '70003344',
            'is_active' => true,
        ]);
        $vehicule = $this->vehicule(['partner_id' => $partenaire->id]);

        $fiche = $this->getJson('/api/catalog/vehicles/'.$vehicule->id)->assertOk()->json('data');
        $liste = $this->getJson('/api/catalog/vehicles')->assertOk()->json('data.0');

        foreach ([$fiche, $liste] as $data) {
            $this->assertSame($partenaire->id, $data['seller']['id']);
            $this->assertSame('Garage historique', $data['seller']['company_name']);
            $this->assertSame('+22670003344', $data['seller']['phone']);
            $this->assertNull($data['seller']['whatsapp']);
        }
    }

    public function test_la_page_de_partage_d_un_vehicule_partenaire_compose_son_numero(): void
    {
        config(['boutique.telephone' => '+22675570484', 'boutique.whatsapp' => '22675570484']);
        $partenaire = Partner::create([
            'company_name' => 'Garage historique',
            'phone' => '70003344',
            'whatsapp' => '71003344',
            'is_active' => true,
        ]);
        $vehicule = $this->vehicule(['partner_id' => $partenaire->id]);

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();

        $this->assertStringContainsString('Garage historique', $html);
        $this->assertStringContainsString('href="tel:+22670003344"', $html);
        $this->assertStringContainsString('https://wa.me/22671003344?text=', $html);
        $this->assertStringNotContainsString('75570484', $html);
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

    public function test_un_partenaire_inactif_ou_sans_numero_composable_se_rabatte_sur_la_boutique(): void
    {
        config(['boutique.telephone' => '+22675570484', 'boutique.whatsapp' => '22675570484']);

        $inactif = Partner::create([
            'company_name' => 'Garage ferme',
            'phone' => '70004455',
            'is_active' => false,
        ]);
        $illisible = Partner::create([
            'company_name' => 'Garage sans ligne',
            'phone' => 'abc',
            'is_active' => true,
        ]);

        foreach ([$this->vehicule(['partner_id' => $inactif->id]), $this->vehicule(['partner_id' => $illisible->id])] as $vehicule) {
            $this->getJson('/api/catalog/vehicles/'.$vehicule->id)
                ->assertOk()
                ->assertJsonMissingPath('data.seller');
            $this->get('/v/'.$vehicule->id)->assertOk()
                ->assertSee('href="tel:+22675570484"', false);
        }
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

        $ressource = (new \App\Http\Resources\VehicleResource($vehicule->load('sellerAccount')))
            ->resolve(request());
        $this->assertNull($ressource['seller']);
        $this->assertNull(\App\Support\PartnerListingPresentation::seller($vehicule));
    }
}
