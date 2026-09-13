<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filtres du catalogue public — ceux que le client manipule sans etre connecte.
 *
 * Le filtre par categorie est un piege connu : l'arbre compte trois niveaux et
 * aucune piece n'est rattachee a une racine. La barre de filtres, elle, ne
 * propose que les racines. Une comparaison stricte renvoyait donc zero resultat
 * sur « Freinage » alors que la base contient des plaquettes et des disques —
 * une page vide, sans erreur, sans message.
 */
class CatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reconstruit un fragment d'arbre a trois niveaux, comme le seeder.
     *
     * @return array{0: PartCategory, 1: PartCategory}
     */
    private function tree(): array
    {
        $racine = PartCategory::create(['name' => 'Freinage', 'slug' => 'freinage', 'parent_id' => null]);
        $milieu = PartCategory::create(['name' => 'Disques & Plaquettes', 'slug' => 'disques-plaquettes', 'parent_id' => $racine->id]);
        $feuille = PartCategory::create(['name' => 'Plaquettes avant', 'slug' => 'plaquettes-avant', 'parent_id' => $milieu->id]);

        return [$racine, $feuille];
    }

    private function part(string $name, PartCategory $category): Part
    {
        return Part::create([
            'sku'              => 'PRT-'.strtoupper(substr(md5($name), 0, 8)),
            'name'             => $name,
            'part_category_id' => $category->id,
            'manufacturer_id'  => Manufacturer::create(['name' => 'Bosch '.$name])->id,
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'cost_price'       => 5000,
            'selling_price'    => 9000,
            'currency'         => 'XOF',
            'vat_rate'         => 18.00,
            'stock_quantity'   => 4,
            'is_active'        => true,
        ]);
    }

    public function test_filtrer_sur_une_racine_remonte_les_pieces_des_sous_categories(): void
    {
        [$racine, $feuille] = $this->tree();
        $this->part('Plaquettes de frein avant', $feuille);

        $this->getJson("/api/catalog/parts?category_id={$racine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Plaquettes de frein avant');
    }

    public function test_filtrer_sur_une_racine_sans_descendance_ne_remonte_rien(): void
    {
        [$racine, $feuille] = $this->tree();
        $this->part('Plaquettes de frein avant', $feuille);

        $autre = PartCategory::create(['name' => 'Climatisation', 'slug' => 'climatisation', 'parent_id' => null]);

        $this->getJson("/api/catalog/parts?category_id={$autre->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_la_recherche_par_nom_filtre_la_liste(): void
    {
        [, $feuille] = $this->tree();
        $this->part('Plaquettes de frein avant', $feuille);
        $this->part('Disque de frein arriere', $feuille);

        $this->getJson('/api/catalog/parts?q=Disque')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Disque de frein arriere');
    }

    /**
     * L'arbre expose a l'application porte `parent_id` : sans lui, impossible
     * de ne proposer que le premier niveau dans la barre de filtres.
     */
    public function test_l_arbre_des_categories_expose_le_parent(): void
    {
        [$racine, $feuille] = $this->tree();

        $response = $this->getJson('/api/catalog/part-categories')->assertOk();

        $parNom = collect($response->json())->keyBy('name');

        $this->assertNull($parNom['Freinage']['parent_id']);
        $this->assertNotNull($parNom['Plaquettes avant']['parent_id']);
        $this->assertSame($racine->id, $parNom['Freinage']['id']);
        $this->assertSame($feuille->id, $parNom['Plaquettes avant']['id']);
    }

    // ── Vehicules : mises en avant et dedouanement ───────────────────────────

    /**
     * La puce « Bonne affaire » n'apparait que si l'API en denombre : un filtre
     * casse la ferait disparaitre de l'ecran sans autre symptome.
     */
    public function test_le_filtre_bonne_affaire_ne_garde_que_les_vehicules_mis_en_avant(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $enAvant = \App\Models\Vehicle::factory()->create(['deal_type' => 'good_deal']);
        \App\Models\Vehicle::factory()->create(['deal_type' => null]);

        // Les deux doivent etre visibles sans filtre…
        $this->getJson('/api/catalog/vehicles')->assertOk()->assertJsonCount(2, 'data');

        // …et une seule avec.
        $this->getJson('/api/catalog/vehicles?deal=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $enAvant->id);
    }

    public function test_le_filtre_dedouane_s_appuie_sur_le_dossier_d_import(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $dedouane = \App\Models\Vehicle::factory()->imported()->create();
        $bloque   = \App\Models\Vehicle::factory()->imported()->create();

        $origine = \App\Models\Country::query()->value('id');

        DB::table('vehicle_import_details')->insert([
            ['vehicle_id' => $dedouane->id, 'origin_country_id' => $origine, 'customs_cleared' => true,  'created_at' => now(), 'updated_at' => now()],
            ['vehicle_id' => $bloque->id,   'origin_country_id' => $origine, 'customs_cleared' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson('/api/catalog/vehicles?customs_cleared=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dedouane->id);
    }
}
