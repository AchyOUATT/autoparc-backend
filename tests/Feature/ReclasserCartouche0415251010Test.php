<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cartouche 04152-51010 doit etre rangee dans « Filtre a huile »,
 * sans toucher au prix, au stock ni aux compatibilites.
 */
class ReclasserCartouche0415251010Test extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path(
            'migrations/2026_11_19_103000_reclasser_la_cartouche_04152_51010_en_filtre_a_huile.php'
        );
    }

    private function categorie(string $nom, string $slug): PartCategory
    {
        return PartCategory::create(['name' => $nom, 'slug' => $slug]);
    }

    private function pieceDans(PartCategory $categorie): Part
    {
        return Part::factory()->create([
            'sku' => '04152-51010',
            'manufacturer_reference' => '04152-51010',
            'name' => 'Cartouche de filtre a huile 04152-51010',
            'description' => 'Element filtrant du V8 diesel 1VD-FTV.',
            'part_category_id' => $categorie->id,
            'selling_price' => 3000,
            'stock_quantity' => 15,
            'is_active' => true,
            'is_available' => true,
        ]);
    }

    public function test_la_migration_reclasse_la_piece_mal_rangee(): void
    {
        $huile = $this->categorie('Filtre à huile', 'filtre-huile');
        $alternateur = $this->categorie('Alternateur', 'alternateur');
        $piece = $this->pieceDans($alternateur);

        $marque = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);
        $modele = VehicleModel::create([
            'brand_id' => $marque->id,
            'name' => 'Land Cruiser',
            'slug' => 'land-cruiser-j200',
            'is_active' => true,
        ]);
        $piece->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'engine_code' => '1VD-FTV',
            'year_from' => 2008,
            'year_to' => 2021,
            'source' => FitmentSource::Declared,
        ]);

        $this->migration()->up();

        $piece->refresh();
        $this->assertSame($huile->id, $piece->part_category_id);
        $this->assertSame('Filtre à huile', $piece->category->name);
        $this->assertSame(3000.0, (float) $piece->selling_price);
        $this->assertSame(15, $piece->stock_quantity);
        $this->assertTrue($piece->is_active);
        $this->assertTrue($piece->is_available);
        $this->assertSame('Element filtrant du V8 diesel 1VD-FTV.', $piece->description);
        $this->assertCount(1, $piece->fitments);
        $this->assertSame('1VD-FTV', $piece->fitments->first()->engine_code);
        $this->assertSame(2008, $piece->fitments->first()->year_from);
        $this->assertSame(2021, $piece->fitments->first()->year_to);
    }

    public function test_un_second_passage_ne_change_rien(): void
    {
        $huile = $this->categorie('Filtre à huile', 'filtre-huile');
        $piece = $this->pieceDans($huile);

        $this->migration()->up();
        $avant = $piece->fresh();

        $this->migration()->up();

        $apres = $piece->fresh();
        $this->assertSame($huile->id, $apres->part_category_id);
        $this->assertSame((string) $avant->updated_at, (string) $apres->updated_at);
        $this->assertSame(3000.0, (float) $apres->selling_price);
        $this->assertSame(15, $apres->stock_quantity);
    }

    public function test_sans_categorie_cible_la_piece_reste_en_place(): void
    {
        $alternateur = $this->categorie('Alternateur', 'alternateur');
        $piece = $this->pieceDans($alternateur);

        $this->migration()->up();

        $this->assertSame($alternateur->id, $piece->fresh()->part_category_id);
        $this->assertSame(3000.0, (float) $piece->fresh()->selling_price);
        $this->assertSame(15, $piece->fresh()->stock_quantity);
    }

    public function test_sans_piece_la_migration_ne_plante_pas(): void
    {
        $this->categorie('Filtre à huile', 'filtre-huile');

        $this->migration()->up();

        $this->assertSame(0, Part::where('sku', '04152-51010')->count());
    }
}
