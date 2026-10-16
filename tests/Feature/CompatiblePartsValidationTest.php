<?php

namespace Tests\Feature;

use App\Enums\PartCondition;
use App\Http\Requests\CompatiblePartsRequest;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\OwnedVehicle;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parametres de requete des routes « pieces compatibles ».
 *
 * Ces routes acceptaient n'importe quoi. Trois defauts, tous silencieux :
 *
 * `condition` partait tel quel dans un where(). « neuve » au lieu de « new »
 * ne declenchait aucune erreur : la requete remontait zero ligne et l'ecran
 * affichait « aucune piece compatible ». Un filtre casse etait indiscernable
 * d'un vehicule reellement non couvert — et c'est le pire des deux, parce que
 * personne ne va chercher un defaut derriere une liste vide.
 *
 * `category_id` avait le meme profil : un identifiant inexistant produisait une
 * liste de descendantes vide, donc un whereIn vide, donc zero resultat, sans
 * signal. Le controle avant voyage renvoie precisement vers ces categories.
 *
 * `per_page` n'avait pas de plafond. L'application demande 500 pieces d'un coup
 * pour badger les tuiles sans une requete par piece ; rien n'empechait un
 * client d'en demander cent mille et de les faire hydrater par le serveur.
 *
 * Les tests « reste accepte » ne couvrent pas un defaut : ils gardent les appels
 * que l'application mobile passe deja — per_page=500 et category_id — de tomber
 * en 422 le jour ou quelqu'un resserre les regles.
 */
class CompatiblePartsValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $proprietaire;
    private OwnedVehicle $vehicule;
    private ?Vehicle $vehiculeDuParc = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proprietaire = User::factory()->create(['role' => 'client', 'firebase_uid' => 'proprio']);
        $this->vehicule     = OwnedVehicle::factory()->create(['user_id' => $this->proprietaire->id]);
    }

    /** Appel du garage, authentifie comme l'application Flutter. */
    private function garage(array $params = []): TestResponse
    {
        $url = "/api/my/vehicles/{$this->vehicule->id}/compatible-parts";

        if ($params !== []) {
            $url .= '?'.http_build_query($params);
        }

        return $this->actingAsClient($this->proprietaire)->getJson($url);
    }

    /** Appel du catalogue public, par criteres vehicule. */
    private function criteres(array $params = []): TestResponse
    {
        return $this->getJson('/api/catalog/compatible-parts?'.http_build_query($params));
    }

    /**
     * Appel de la fiche vehicule du back-office, en `viewer` : le role le plus
     * limite du personnel, et celui qui prouve que la lecture reste ouverte a
     * tous — la route ne porte aucune capacite.
     */
    private function fiche(array $params = []): TestResponse
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));

        $url = "/api/vehicles/{$this->vehiculeDuParc()->id}/compatible-parts";

        if ($params !== []) {
            $url .= '?'.http_build_query($params);
        }

        return $this->getJson($url);
    }

    /**
     * Un vehicule du parc, cree une seule fois par test.
     *
     * Le referentiel est monte a la main plutot que par ReferenceDataSeeder :
     * le seeder insere une marque Toyota, que OwnedVehicleFactory a deja creee
     * dans setUp(), et brands.name est unique. VehicleFactory ne reclame que
     * ces trois tables — marque, modele, motorisation ; le reste de ce qu'elle
     * pioche accepte null.
     */
    private function vehiculeDuParc(): Vehicle
    {
        if ($this->vehiculeDuParc === null) {
            $this->modele();

            EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence']);

            $this->vehiculeDuParc = Vehicle::factory()->create();
        }

        return $this->vehiculeDuParc;
    }

    private function categorie(): PartCategory
    {
        return PartCategory::create(['name' => 'Freinage', 'slug' => 'freinage', 'parent_id' => null]);
    }

    /**
     * firstOrCreate plutot que create : le slug est unique, et un test peut
     * avoir besoin du modele a la fois pour la recherche par criteres et pour
     * le vehicule du parc.
     */
    private function modele(): VehicleModel
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'peugeot'],
            ['name' => 'Peugeot', 'is_active' => true],
        );

        return VehicleModel::firstOrCreate(
            ['slug' => 'peugeot-208'],
            ['brand_id' => $marque->id, 'name' => '208', 'is_active' => true],
        );
    }

    // ── condition ────────────────────────────────────────────────────────────

    public function test_un_etat_de_piece_hors_referentiel_est_refuse(): void
    {
        $this->garage(['condition' => 'neuve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('condition');
    }

    public function test_les_trois_etats_du_referentiel_restent_acceptes(): void
    {
        foreach (PartCondition::values() as $etat) {
            $this->garage(['condition' => $etat])
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    /**
     * Le cas qui rend le defaut invisible : une valeur plausible pour un humain,
     * inconnue de la base. Sans regle, la reponse etait un 200 vide.
     */
    public function test_un_etat_plausible_mais_inconnu_ne_passe_plus_pour_une_absence_de_piece(): void
    {
        $reponse = $this->garage(['condition' => 'occasion'])->assertStatus(422);

        $this->assertArrayNotHasKey('data', $reponse->json());
    }

    // ── category_id ──────────────────────────────────────────────────────────

    public function test_une_categorie_inexistante_est_refusee(): void
    {
        $this->garage(['category_id' => 987654])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_une_categorie_non_numerique_est_refusee(): void
    {
        $this->garage(['category_id' => 'freinage'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_une_categorie_existante_reste_acceptee(): void
    {
        $this->garage(['category_id' => $this->categorie()->id])->assertOk();
    }

    // ── per_page ─────────────────────────────────────────────────────────────

    public function test_une_taille_de_page_au_dela_du_plafond_est_refusee(): void
    {
        $this->garage(['per_page' => 100000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_la_taille_de_page_demandee_par_l_application_reste_acceptee(): void
    {
        $this->garage(['per_page' => 500])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 500);
    }

    public function test_le_plafond_lui_meme_est_accepte_et_le_suivant_refuse(): void
    {
        $plafond = CompatiblePartsRequest::MAX_PER_PAGE;

        $this->garage(['per_page' => $plafond])->assertOk();
        $this->garage(['per_page' => $plafond + 1])->assertStatus(422);
    }

    public function test_une_taille_de_page_nulle_ou_negative_est_refusee(): void
    {
        $this->garage(['per_page' => 0])->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->garage(['per_page' => -5])->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_sans_taille_de_page_la_pagination_reste_a_vingt(): void
    {
        $this->garage()->assertOk()->assertJsonPath('meta.per_page', 20);
    }

    public function test_un_numero_de_page_inferieur_a_un_est_refuse(): void
    {
        $this->garage(['page' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('page');
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /**
     * Les messages remontent tels quels dans l'application : ils sont ecrits en
     * francais, accents compris.
     */
    public function test_les_messages_de_validation_sont_en_francais(): void
    {
        $etat = $this->garage(['condition' => 'neuve'])->json('errors.condition.0');
        $this->assertStringContainsString('reconditionnée', $etat);

        $page = $this->garage(['per_page' => 100000])->json('errors.per_page.0');
        $this->assertStringContainsString('dépasser', $page);
        $this->assertStringContainsString((string) CompatiblePartsRequest::MAX_PER_PAGE, $page);
    }

    // ── Appels reels de l'application mobile ─────────────────────────────────

    /**
     * GarageRepository.compatibleParts() envoie category_id, et
     * .compatiblePartIds() envoie per_page=500. Les deux doivent passer.
     */
    public function test_les_appels_du_depot_mobile_restent_acceptes(): void
    {
        $categorie = $this->categorie();

        $this->garage(['category_id' => $categorie->id])->assertOk();
        $this->garage(['per_page' => 500])->assertOk();
        $this->garage(['category_id' => $categorie->id, 'per_page' => 500, 'in_stock' => '1'])->assertOk();
    }

    // ── La validation ne prend pas le pas sur la policy ──────────────────────

    /**
     * Le FormRequest s'execute avant la methode du controleur : un vehicule qui
     * n'est pas le sien doit toujours repondre 403, pas 422.
     */
    public function test_un_autre_client_reste_refuse_par_la_policy(): void
    {
        $this->actingAsClient(uid: 'quelqu-un-dautre')
            ->getJson("/api/my/vehicles/{$this->vehicule->id}/compatible-parts")
            ->assertForbidden();
    }

    // ── Fiche vehicule du back-office ────────────────────────────────────────

    /**
     * Meme code que la route du garage, aux noms pres, donc memes defauts. La
     * consultation est ouverte a tout le personnel : le role le plus limite
     * suffit a la couvrir.
     */
    public function test_la_fiche_vehicule_du_back_office_refuse_un_etat_hors_referentiel(): void
    {
        $this->fiche(['condition' => 'neuve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('condition');
    }

    public function test_la_fiche_vehicule_du_back_office_refuse_une_categorie_inexistante(): void
    {
        $this->fiche(['category_id' => 987654])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_la_fiche_vehicule_du_back_office_plafonne_la_taille_de_page(): void
    {
        $this->fiche(['per_page' => 100000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_la_fiche_vehicule_du_back_office_accepte_les_filtres_valides(): void
    {
        $this->fiche([
            'condition'   => PartCondition::Used->value,
            'category_id' => $this->categorie()->id,
            'in_stock'    => '1',
            'per_page'    => CompatiblePartsRequest::MAX_PER_PAGE,
        ])
            ->assertOk()
            ->assertJsonPath('meta.per_page', CompatiblePartsRequest::MAX_PER_PAGE);
    }

    /**
     * Le FormRequest s'execute apres la chaine de middleware : un appel sans
     * jeton doit repondre 401, pas 422 — sinon la route decrirait ses
     * parametres a qui n'a pas le droit de la lire.
     */
    public function test_la_fiche_vehicule_du_back_office_reste_fermee_sans_jeton(): void
    {
        $this->getJson("/api/vehicles/{$this->vehiculeDuParc()->id}/compatible-parts?per_page=100000")
            ->assertUnauthorized();
    }

    // ── Recherche par criteres ───────────────────────────────────────────────

    public function test_la_recherche_par_criteres_plafonne_aussi_la_taille_de_page(): void
    {
        $this->criteres(['vehicle_model_id' => $this->modele()->id, 'per_page' => 100000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_la_recherche_par_criteres_accepte_le_plafond(): void
    {
        $this->criteres(['vehicle_model_id' => $this->modele()->id, 'per_page' => 500])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 500);
    }

    public function test_la_recherche_par_criteres_refuse_un_numero_de_page_inferieur_a_un(): void
    {
        $this->criteres(['vehicle_model_id' => $this->modele()->id, 'page' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('page');
    }

    /** Les regles deja en place ne doivent pas avoir bouge en passant au FormRequest. */
    public function test_la_recherche_par_criteres_exige_toujours_un_modele_existant(): void
    {
        $this->criteres([])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_model_id');

        $this->criteres(['vehicle_model_id' => 987654])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_model_id');

        $this->criteres(['vehicle_model_id' => $this->modele()->id, 'year' => 1800])
            ->assertStatus(422)
            ->assertJsonValidationErrors('year');
    }

    /**
     * CatalogRepository.getParts() bascule sur cette route des qu'un modele est
     * choisi, et lui passe toute sa barre de filtres. Aucun de ces parametres ne
     * doit faire echouer l'appel.
     */
    public function test_l_appel_du_catalogue_mobile_reste_accepte(): void
    {
        $this->criteres([
            'vehicle_model_id' => $this->modele()->id,
            'year'             => 2019,
            'page'             => 1,
            'per_page'         => 20,
            'q'                => 'plaquettes',
            'category_id'      => $this->categorie()->id,
            'in_stock'         => '1',
            'city'             => 'Dakar',
        ])->assertOk();
    }
}
