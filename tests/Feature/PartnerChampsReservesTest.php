<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les champs de la boutique, qu'un partenaire ne choisit pas.
 *
 * Le garde d'appartenance posait `missing` sur sept champs. Il en manquait
 * deux, et l'un des deux permettait a n'importe quel partenaire approuve de
 * BLOQUER la creation de vehicules du parc, sans mauvaise intention et pour le
 * reste de l'annee.
 *
 * ── LE BLOCAGE, ET POURQUOI IL EST MECANIQUE ──────────────────────────────
 *
 * `Partner\VehicleController::store()` delegue au controleur du PERSONNEL, qui
 * ecrit `$data['reference'] ?? $this->references->next('VEH')` : la valeur
 * fournie par le client gagne. Et `ReferenceGenerator::next()` lit le maximum
 * par `orderByDesc` sur une colonne TEXTE, puis incremente.
 *
 * Qu'un partenaire enregistre `VEH-2026-999999` et la suite se deroule seule :
 * la creation suivante donne `VEH-2026-1000000` et passe ; celle d'apres
 * retrouve `VEH-2026-999999` comme maximum — '9' > '1' en comparaison de
 * chaines — reproduit `VEH-2026-1000000`, et heurte la contrainte unique.
 *
 * Le test central de ce banc rejoue cette sequence ENTIERE. C'est la seule
 * facon de prouver que le garde la ferme : verifier le 422 sur le champ
 * montrerait que la validation refuse, pas que le parc tient.
 *
 * ── CE QUI RESTE OUVERT, ET QUE CE BANC PROTEGE AUSSI ─────────────────────
 *
 * `vin` et `sku` restent au partenaire : le numero de serie de son vehicule et
 * la reference d'equipementier de sa piece sont son travail. Deux tests les
 * tiennent ouverts, pour qu'un elargissement du garde ne les ferme pas par
 * ricochet — ce serait une regression, pas un durcissement.
 */
class PartnerChampsReservesTest extends TestCase
{
    use RefreshDatabase;

    private PartnerAccount $compte;
    private array $identite;

    protected function setUp(): void
    {
        parent::setUp();

        $marque = Brand::create(['name' => 'Toyota']);
        $modele = VehicleModel::create(['brand_id' => $marque->id, 'name' => 'Corolla']);
        $moteur = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);

        $this->identite = [
            'brand_id' => $marque->id,
            'vehicle_model_id' => $modele->id,
            'engine_type_id' => $moteur->id,
            'manufacturing_year' => 2020,
            'condition' => 'used',
            'availability' => 'sale',
            'sale_price' => 4_500_000,
        ];

        $this->compte = PartnerAccount::create([
            'user_id' => User::factory()->create(['role' => 'partner', 'is_active' => true])->id,
            'company_name' => 'Garage Kossodo',
            'contact_name' => 'Responsable',
            'phone' => '+22670000000',
            'status' => 'approved',
        ]);
    }

    private function enPartenaire(): void
    {
        Sanctum::actingAs($this->compte->user);
    }

    private function enPersonnel(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    // ── Les deux champs fermes ───────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function champsFermes(): array
    {
        return [
            'reference' => ['reference', 'VEH-2026-999999'],
            'deal_type' => ['deal_type', 'flash_sale'],
        ];
    }

    #[DataProvider('champsFermes')]
    public function test_un_partenaire_ne_peut_pas_choisir_ce_champ(string $champ, mixed $valeur): void
    {
        $this->enPartenaire();

        $this->postJson('/api/partner/vehicles', $this->identite + [$champ => $valeur])
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);
    }

    /**
     * `missing` refuse meme un null explicite : on ne contourne pas en vidant
     * le champ.
     *
     * `$valeur` n'est pas utilise — ce test envoie null — mais il est DECLARE,
     * et il doit l'etre : `champsFermes()` fournit deux arguments par jeu, et
     * une methode qui n'en accepte qu'un declenche un avertissement PHPUnit.
     * Cet avertissement a fait sortir la suite en 1 avec 931 tests verts, et
     * il a ete invisible ici pendant deux executions de CI parce que le
     * resume JSON du poste n'affiche pas les avertissements.
     */
    #[DataProvider('champsFermes')]
    public function test_un_null_explicite_est_refuse_aussi(string $champ, mixed $valeur): void
    {
        $this->enPartenaire();

        $this->postJson('/api/partner/vehicles', $this->identite + [$champ => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);
    }

    #[DataProvider('champsFermes')]
    public function test_la_modification_est_fermee_comme_la_creation(string $champ, mixed $valeur): void
    {
        // Sans quoi il suffirait de creer puis de modifier.
        $vehicule = Vehicle::create($this->identite + [
            'reference' => 'VEH-2026-000001',
            'status' => 'in_stock',
            'owner_partner_account_id' => $this->compte->id,
        ]);

        $this->enPartenaire();

        $this->patchJson('/api/partner/vehicles/'.$vehicule->id, [$champ => $valeur])
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);
    }

    // ── LE TEST CENTRAL : le parc tient ──────────────────────────────────────

    public function test_un_partenaire_ne_peut_plus_bloquer_la_creation_de_vehicules(): void
    {
        // 1. La tentative : un partenaire pose une reference qui epuiserait la
        //    suite. Refusee.
        $this->enPartenaire();
        $this->postJson('/api/partner/vehicles', $this->identite + ['reference' => 'VEH-2026-999999'])
            ->assertStatus(422);

        // 2. Son vehicule, cree sans reference, en recoit une du serveur.
        $sienne = $this->postJson('/api/partner/vehicles', $this->identite)
            ->assertCreated()
            ->json('data.reference');

        $this->assertNotNull($sienne, 'Le serveur doit attribuer une reference.');
        $this->assertStringStartsWith('VEH-', (string) $sienne);

        // 3. LA PREUVE : le personnel cree DEUX vehicules d'affilee. C'est le
        //    second qui tombait, la suite butant sur une reference deja prise.
        $this->enPersonnel();

        foreach ([1, 2] as $rang) {
            $this->postJson('/api/vehicles', $this->identite)
                ->assertCreated("La creation {$rang} du personnel a echoue : la suite des references est cassee.");
        }

        // Et toutes les references sont distinctes.
        $toutes = Vehicle::pluck('reference');
        $this->assertSame(
            $toutes->count(),
            $toutes->unique()->count(),
            'Deux vehicules portent la meme reference.'
        );
    }

    // ── Pas de regression sur le personnel ───────────────────────────────────

    #[DataProvider('champsFermes')]
    public function test_le_personnel_garde_la_main_sur_ces_champs(string $champ, mixed $valeur): void
    {
        // Le garde vit dans les requetes `Partner\`, pas dans celles du
        // personnel. Si cette distinction se perdait, on aurait ferme la
        // boutique a elle-meme.
        $this->enPersonnel();

        $reponse = $this->postJson('/api/vehicles', $this->identite + [$champ => $valeur]);

        $reponse->assertCreated();
        $this->assertDatabaseHas('vehicles', [$champ => $valeur]);
    }

    public function test_une_mise_en_avant_de_partenaire_n_entre_pas_dans_la_vitrine(): void
    {
        // La consequence visible de `deal_type` : `?deal=1` alimente les mises
        // en avant du catalogue public. Ce test mesure la VITRINE, pas la
        // validation — c'est elle qui compte.
        $this->enPartenaire();
        $this->postJson('/api/partner/vehicles', $this->identite + ['deal_type' => 'flash_sale'])
            ->assertStatus(422);

        $this->postJson('/api/partner/vehicles', $this->identite)->assertCreated();

        $this->assertSame(
            0,
            count($this->getJson('/api/catalog/vehicles?deal=1')->assertOk()->json('data')),
            'Une annonce de partenaire a atteint les promotions de la boutique.'
        );
    }

    // ── Ce qui reste au partenaire ───────────────────────────────────────────

    public function test_le_partenaire_garde_le_vin_de_son_vehicule(): void
    {
        // Decision assumee : le numero de serie de SON vehicule est son
        // travail. Ce test existe pour qu'un elargissement du garde ne le
        // ferme pas par ricochet.
        $this->enPartenaire();

        $this->postJson('/api/partner/vehicles', $this->identite + ['vin' => 'JTDBR32E870123456'])
            ->assertCreated();

        $this->assertDatabaseHas('vehicles', ['vin' => 'JTDBR32E870123456']);
    }

    public function test_le_partenaire_garde_le_sku_de_sa_piece(): void
    {
        // Idem : « 462-1171 » est une reference d'equipementier, qu'un vendeur
        // de pieces doit pouvoir declarer. Aucun generateur ne s'en nourrit,
        // donc aucun blocage de suite n'est possible par la.
        $categorie = \App\Models\PartCategory::create(['name' => 'Filtres', 'slug' => 'filtres']);

        $this->enPartenaire();

        $this->postJson('/api/partner/parts', [
            'name' => 'Filtre a huile',
            'part_category_id' => $categorie->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 5000,
            'sku' => '462-1171',
        ])->assertCreated();

        $this->assertDatabaseHas('parts', ['sku' => '462-1171']);
    }
}
