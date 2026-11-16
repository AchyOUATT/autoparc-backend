<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreeDesAnnoncesPartenaires;
use Tests\TestCase;

class EmplacementParDefautPartenaireTest extends TestCase
{
    use RefreshDatabase, CreeDesAnnoncesPartenaires;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compte = $this->compteApprouve();
    }

    public function test_le_profil_rend_un_emplacement_vide_par_defaut_et_un_compte_en_attente_peut_le_renseigner(): void
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        $this->getJson('/api/partner/me')
            ->assertOk()
            ->assertJsonPath('partner_account.city', null)
            ->assertJsonPath('partner_account.address', null);

        $this->patchJson('/api/partner/me', [
            'city' => 'Ouagadougou',
            'address' => 'Secteur 15, route de Kaya',
        ])->assertOk()
            ->assertJsonPath('partner_account.city', 'Ouagadougou')
            ->assertJsonPath('partner_account.address', 'Secteur 15, route de Kaya');

        $pending = $this->compteEnAttente();
        Sanctum::actingAs($pending->user, ['partner']);
        $this->patchJson('/api/partner/me', ['city' => 'Bobo-Dioulasso'])
            ->assertOk()
            ->assertJsonPath('partner_account.city', 'Bobo-Dioulasso')
            ->assertJsonPath('partner_account.address', null);
        $this->assertSame('Bobo-Dioulasso', $pending->fresh()->city);
    }

    public function test_un_champ_omis_est_conserve_et_un_null_explicite_efface_le_defaut(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Zone 1']);
        Sanctum::actingAs($this->compte->user, ['partner']);

        $this->patchJson('/api/partner/me', ['address' => 'Zone 2'])
            ->assertOk()
            ->assertJsonPath('partner_account.city', 'Ouagadougou')
            ->assertJsonPath('partner_account.address', 'Zone 2');

        $this->patchJson('/api/partner/me', ['address' => null])
            ->assertOk()
            ->assertJsonPath('partner_account.city', 'Ouagadougou')
            ->assertJsonPath('partner_account.address', null);
    }

    public function test_le_personnel_voit_l_emplacement_sur_le_dossier_et_les_autres_roles_ne_peuvent_pas_le_modifier(): void
    {
        $this->compte->update(['city' => 'Koudougou', 'address' => 'Marché']);
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/partner-applications/'.$this->compte->id)
            ->assertOk()
            ->assertJsonPath('data.city', 'Koudougou')
            ->assertJsonPath('data.address', 'Marché');

        $this->patchJson('/api/partner/me', ['city' => 'Banfora'])->assertForbidden();

        $client = User::factory()->create(['role' => 'client', 'is_active' => true]);
        Sanctum::actingAs($client);
        $this->patchJson('/api/partner/me', ['city' => 'Banfora'])->assertForbidden();
    }

    public function test_une_ville_trop_longue_est_refusee(): void
    {
        Sanctum::actingAs($this->compte->user, ['partner']);
        $this->patchJson('/api/partner/me', ['city' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('city');
        $this->patchJson('/api/partner/me', ['address' => str_repeat('b', 121)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');
    }

    public function test_une_ancienne_app_qui_omet_la_ville_recoit_le_defaut_a_la_creation(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Patte d’Oie']);

        foreach ($this->creerLesTroisInventaires() as $type => $id) {
            $modele = $this->modeleDu($type, $id);
            $this->assertSame('Ouagadougou', $modele->city, $type);
            $this->assertSame('Patte d’Oie', $modele->site, $type);
        }
    }

    public function test_une_ville_envoyee_explicitement_remplace_le_defaut(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Patte d’Oie']);

        $vehicule = $this->creerVehicule([
            'sale_price' => 4_500_000,
            'city' => 'Koudougou',
            'site' => 'Gare routière',
        ])->assertCreated()->json('data.id');

        $this->assertSame('Koudougou', Vehicle::findOrFail($vehicule)->city);
        $this->assertSame('Gare routière', Vehicle::findOrFail($vehicule)->site);
    }

    public function test_sans_defaut_une_creation_sans_ville_reste_sans_ville(): void
    {
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated()->json('data.id');
        $vehicule = Vehicle::findOrFail($id);
        $this->assertNull($vehicule->city);
        $this->assertNull($vehicule->site);
    }

    public function test_une_modification_sans_la_ville_conserve_la_valeur_enregistree(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Zone 1']);
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated()->json('data.id');

        $this->compte->update(['city' => 'Bobo-Dioulasso', 'address' => 'Accart-Ville']);

        $this->modifierVehicule($id, [
            'sale_price' => 4_500_000,
            'description' => 'Mise a jour sans toucher a la ville',
        ])->assertOk();

        $vehicule = Vehicle::findOrFail($id);
        $this->assertSame('Ouagadougou', $vehicule->city);
        $this->assertSame('Zone 1', $vehicule->site);
        $this->assertSame('Mise a jour sans toucher a la ville', $vehicule->description);
    }

    public function test_un_null_explicite_efface_la_ville_d_une_annonce(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Zone 1']);
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated()->json('data.id');

        $this->modifierVehicule($id, [
            'sale_price' => 4_500_000,
            'city' => null,
            'site' => null,
        ])->assertOk();

        $vehicule = Vehicle::findOrFail($id);
        $this->assertNull($vehicule->city);
        $this->assertNull($vehicule->site);
    }

    public function test_changer_le_defaut_ne_reecrit_pas_les_annonces_existantes(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Zone 1']);
        $deja = $this->creerLesTroisInventaires();

        Sanctum::actingAs($this->compte->user, ['partner']);
        $this->patchJson('/api/partner/me', [
            'city' => 'Banfora',
            'address' => 'Centre',
        ])->assertOk();

        foreach ($deja as $type => $id) {
            $modele = $this->modeleDu($type, $id);
            $this->assertSame('Ouagadougou', $modele->city, $type);
            $this->assertSame('Zone 1', $modele->site, $type);
        }

        $nouveaux = $this->creerLesTroisInventaires();
        foreach ($nouveaux as $type => $id) {
            $modele = $this->modeleDu($type, $id);
            $this->assertSame('Banfora', $modele->city, $type);
            $this->assertSame('Centre', $modele->site, $type);
        }
    }

    public function test_un_partenaire_ne_peut_pas_choisir_une_agence_autoparc(): void
    {
        $agence = Location::create(['name' => 'Showroom Paspanga', 'city' => 'Ouagadougou', 'is_active' => true]);

        $this->creerVehicule([
            'sale_price' => 4_500_000,
            'location_id' => $agence->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');

        $this->creerPiece([
            'selling_price' => 5_000,
            'location_id' => $agence->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');

        $this->creerAccessoire([
            'selling_price' => 5_000,
            'location_id' => $agence->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    public function test_le_filtre_ville_du_catalogue_inclut_la_ville_de_l_annonce_et_celle_de_l_agence(): void
    {
        $this->compte->update(['city' => 'Koudougou']);
        $ids = $this->creerLesTroisInventaires();
        $this->publier($ids);

        $agence = Location::create(['name' => 'Agence Bobo', 'city' => 'Bobo-Dioulasso', 'is_active' => true]);
        $stock = Vehicle::create([
            'reference' => 'VEH-STOCK-001',
            'brand_id' => $this->modele()->brand_id,
            'vehicle_model_id' => $this->modele()->id,
            'engine_type_id' => \App\Models\EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence'])->id,
            'manufacturing_year' => 2019,
            'registration_status' => 'registered',
            'availability' => 'sale',
            'condition' => 'used',
            'status' => 'in_stock',
            'sale_price' => 3_000_000,
            'location_id' => $agence->id,
            'published_at' => now(),
        ]);

        $this->oublierLaSession();

        $idsKoudougou = $this->idsDuCatalogue($this->getJson('/api/catalog/vehicle-listings?city=Koudougou')->assertOk()->json('data'), 'vehicles');
        $this->assertContains((int) $ids['vehicles'], $idsKoudougou);
        $this->assertNotContains($stock->id, $idsKoudougou);

        $listeHistorique = collect($this->getJson('/api/catalog/vehicles?city=Koudougou')->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $ids['vehicles'], $listeHistorique);
        $this->assertNotContains($stock->id, $listeHistorique);

        $idsBobo = $this->idsDuCatalogue($this->getJson('/api/catalog/vehicle-listings?city=Bobo-Dioulasso')->assertOk()->json('data'), 'vehicles');
        $this->assertContains($stock->id, $idsBobo);
        $this->assertNotContains((int) $ids['vehicles'], $idsBobo);

        $this->assertContains((int) $ids['parts'], $this->idsDuCatalogue(
            $this->getJson('/api/catalog/parts?city=Koudougou')->assertOk()->json('data'),
            'parts',
        ));
        $this->assertContains((int) $ids['accessories'], $this->idsDuCatalogue(
            $this->getJson('/api/catalog/accessories?city=Koudougou')->assertOk()->json('data'),
            'accessories',
        ));
    }

    public function test_la_fiche_publique_montre_la_ville_et_le_lieu_sans_exposer_l_agence(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Patte d’Oie']);
        $ids = $this->creerLesTroisInventaires();
        $this->publier($ids);
        $this->oublierLaSession();

        $vehicule = $this->getJson('/api/catalog/vehicles/'.$ids['vehicles'])->assertOk()->json('data');
        $this->assertSame('Ouagadougou', $vehicule['commercial']['city']);
        $this->assertSame('Patte d’Oie', $vehicule['commercial']['site']);
        $this->assertArrayNotHasKey('location', $vehicule['commercial']);
        $this->assertArrayNotHasKey('location_id', $vehicule['commercial']);

        $piece = $this->getJson('/api/catalog/parts/'.$ids['parts'])->assertOk()->json('data');
        $this->assertSame('Ouagadougou', $piece['city']);
        $this->assertSame('Patte d’Oie', $piece['site']);
        $this->assertSame(['is_available' => true], $piece['stock']);

        $accessoire = $this->getJson('/api/catalog/accessories/'.$ids['accessories'])->assertOk()->json('data');
        $this->assertSame('Ouagadougou', $accessoire['city']);
        $this->assertSame('Patte d’Oie', $accessoire['site']);
        $this->assertSame(['is_available' => true], $accessoire['stock']);
    }

    public function test_une_modification_de_piece_sans_la_ville_conserve_la_valeur(): void
    {
        $this->compte->update(['city' => 'Ouagadougou', 'address' => 'Zone 1']);
        $id = $this->creerPiece(['selling_price' => 5_000])->assertCreated()->json('data.id');

        $this->compte->update(['city' => 'Banfora']);
        Sanctum::actingAs($this->compte->user, ['partner']);
        $this->putJson('/api/partner/parts/'.$id, [
            'name' => 'Filtre a huile',
            'part_category_id' => \App\Models\PartCategory::firstOrCreate(['slug' => 'filtres'], ['name' => 'Filtres'])->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'stock_quantity' => 4,
            'selling_price' => 5_000,
            'description' => 'Sans changer la ville',
        ])->assertOk();

        $piece = Part::findOrFail($id);
        $this->assertSame('Ouagadougou', $piece->city);
        $this->assertSame('Zone 1', $piece->site);
        $this->assertSame('Sans changer la ville', $piece->description);
    }

    /** @return array{vehicles: int, parts: int, accessories: int} */
    private function creerLesTroisInventaires(): array
    {
        return [
            'vehicles' => $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated()->json('data.id'),
            'parts' => $this->creerPiece(['selling_price' => 5_000])->assertCreated()->json('data.id'),
            'accessories' => $this->creerAccessoire(['selling_price' => 5_000])->assertCreated()->json('data.id'),
        ];
    }

    private function modeleDu(string $type, int $id): Vehicle|Part|Accessory
    {
        return match ($type) {
            'vehicles' => Vehicle::findOrFail($id),
            'parts' => Part::findOrFail($id),
            'accessories' => Accessory::findOrFail($id),
        };
    }

    /** @param  array{vehicles: int, parts: int, accessories: int}  $ids */
    private function publier(array $ids): void
    {
        Vehicle::findOrFail($ids['vehicles'])->update(['partner_published_at' => now(), 'published_at' => now()]);
        Part::findOrFail($ids['parts'])->update(['partner_published_at' => now()]);
        Accessory::findOrFail($ids['accessories'])->update(['partner_published_at' => now()]);
    }

    private function compteEnAttente(): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => true]);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'En attente',
            'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($user->id + 9000),
            'status' => 'pending',
        ]);
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function idsDuCatalogue(array $lignes, string $type): array
    {
        return collect($lignes)->map(function (array $ligne) use ($type) {
            if ($type === 'vehicles') {
                return ($ligne['source'] ?? null) === 'vehicle' ? (int) ($ligne['source_id'] ?? 0) : 0;
            }

            return (int) ($ligne['id'] ?? 0);
        })->filter()->values()->all();
    }
}
