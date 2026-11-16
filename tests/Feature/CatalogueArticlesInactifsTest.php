<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Part;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les fiches inactives restent reservees au personnel.
 *
 * `GET /api/catalog/parts` appliquait `active_only` (vrai par defaut) sans
 * regarder qui demandait. Un visiteur anonyme pouvait donc envoyer
 * `active_only=0` et lister les pieces AutoParc inactives : le scope
 * `visibleToPublic` ne porte `is_active` que sur les annonces partenaires.
 * Les accessoires publics, eux, imposent deja `active()`. Ce banc verrouille
 * les deux catalogues, et le parametre `inactive_only` du back-office.
 */
class CatalogueArticlesInactifsTest extends TestCase
{
    use RefreshDatabase;

    private Part $pieceActive;

    private Part $pieceInactive;

    private Accessory $accessoireActif;

    private Accessory $accessoireInactif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pieceActive = Part::factory()->create(['name' => 'Filtre actif', 'is_active' => true]);
        $this->pieceInactive = Part::factory()->create(['name' => 'Filtre inactif', 'is_active' => false]);
        $this->accessoireActif = Accessory::factory()->create(['name' => 'Tapis actif', 'is_active' => true, 'is_available' => true, 'stock_quantity' => 2]);
        $this->accessoireInactif = Accessory::factory()->create(['name' => 'Tapis inactif', 'is_active' => false, 'is_available' => true, 'stock_quantity' => 2]);
    }

    /** @return list<int> */
    private function identifiants(string $uri, array $headers = []): array
    {
        return collect($this->getJson($uri, $headers)->assertOk()->json('data'))
            ->pluck('id')
            ->all();
    }

    public function test_le_catalogue_public_des_pieces_masque_les_inactives_meme_avec_active_only_a_zero(): void
    {
        foreach (['/api/catalog/parts', '/api/catalog/parts?active_only=0', '/api/catalog/parts?inactive_only=1'] as $uri) {
            $ids = $this->identifiants($uri);
            $this->assertContains($this->pieceActive->id, $ids, $uri);
            $this->assertNotContains($this->pieceInactive->id, $ids, $uri);
        }
    }

    public function test_la_fiche_publique_d_une_piece_inactive_rend_404(): void
    {
        $this->getJson("/api/catalog/parts/{$this->pieceActive->id}")->assertOk();
        $this->getJson("/api/catalog/parts/{$this->pieceInactive->id}")->assertNotFound();
    }

    public function test_le_catalogue_public_des_accessoires_ignore_active_only(): void
    {
        foreach (['/api/catalog/accessories', '/api/catalog/accessories?active_only=0', '/api/catalog/accessories?inactive_only=1'] as $uri) {
            $ids = $this->identifiants($uri);
            $this->assertContains($this->accessoireActif->id, $ids, $uri);
            $this->assertNotContains($this->accessoireInactif->id, $ids, $uri);
        }

        $this->getJson("/api/catalog/accessories/{$this->accessoireActif->id}")->assertOk();
        $this->getJson("/api/catalog/accessories/{$this->accessoireInactif->id}")->assertNotFound();
    }

    public function test_la_route_du_personnel_refuse_un_visiteur_anonyme(): void
    {
        $this->getJson('/api/parts')->assertUnauthorized();
        $this->getJson('/api/accessories')->assertUnauthorized();
    }

    public function test_un_client_ne_peut_pas_lister_les_pieces_inactives_via_la_route_du_personnel(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'is_active' => true]));

        $this->getJson('/api/parts?active_only=0')->assertForbidden();
        $this->getJson('/api/accessories?inactive_only=1')->assertForbidden();
    }

    public function test_le_personnel_liste_seulement_les_actives_par_defaut(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $pieces = $this->identifiants('/api/parts');
        $this->assertContains($this->pieceActive->id, $pieces);
        $this->assertNotContains($this->pieceInactive->id, $pieces);

        $accessoires = $this->identifiants('/api/accessories');
        $this->assertContains($this->accessoireActif->id, $accessoires);
        $this->assertNotContains($this->accessoireInactif->id, $accessoires);
    }

    public function test_le_personnel_peut_n_afficher_que_les_fiches_inactives(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $pieces = $this->identifiants('/api/parts?inactive_only=1');
        $this->assertSame([$this->pieceInactive->id], $pieces);

        $accessoires = $this->identifiants('/api/accessories?inactive_only=1');
        $this->assertSame([$this->accessoireInactif->id], $accessoires);
    }

    public function test_le_personnel_peut_elargir_avec_active_only_a_zero(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $pieces = $this->identifiants('/api/parts?active_only=0');
        $this->assertContains($this->pieceActive->id, $pieces);
        $this->assertContains($this->pieceInactive->id, $pieces);
    }

    public function test_le_personnel_ouvre_la_fiche_d_un_article_inactif(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->getJson("/api/parts/{$this->pieceInactive->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->pieceInactive->id)
            ->assertJsonPath('data.is_active', false);

        $this->getJson("/api/accessories/{$this->accessoireInactif->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->accessoireInactif->id)
            ->assertJsonPath('data.is_active', false);
    }
}
