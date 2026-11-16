<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La chemise Cummins 3919937 entre, et n'entre pas en vente.
 */
class ChemiseCummins3919937Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PartCategoriesSeeder::class);

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\ChemiseCummins3919937Seeder::class,
            '--force' => true,
        ])->assertSuccessful();
    }

    public function test_la_reference_entre(): void
    {
        $this->assertTrue(Part::where('sku', '3919937')->exists());
    }

    public function test_elle_n_est_pas_mise_en_vente(): void
    {
        $piece = Part::where('sku', '3919937')->firstOrFail();

        $this->assertFalse((bool) $piece->is_active);
        $this->assertEquals(0, $piece->selling_price);
        $this->assertSame(0, $piece->stock_quantity);
    }

    public function test_elle_est_une_piece_constructeur_cummins(): void
    {
        $piece = Part::where('sku', '3919937')->firstOrFail();

        $this->assertSame('oem', $piece->type->value ?? $piece->type);
        $this->assertSame('Cummins', $piece->manufacturer?->name);
        $this->assertSame('Chemise de cylindre', $piece->category?->name);
        $this->assertSame('moteur', $piece->category?->parent?->slug);
    }

    public function test_cummins_n_entre_pas_dans_les_marques_de_vehicules(): void
    {
        $this->assertFalse(
            Brand::where('name', 'Cummins')->exists(),
            'Cummins est un motoriste, pas un constructeur de machine au referentiel vehicules.'
        );
    }

    public function test_aucune_compatibilite_machine_n_est_posee(): void
    {
        $piece = Part::where('sku', '3919937')->firstOrFail();

        $this->assertFalse($piece->fitments()->exists());
    }

    public function test_les_equivalents_non_confirmes_ne_sont_pas_enregistres(): void
    {
        $piece = Part::where('sku', '3919937')->firstOrFail();
        $numeros = $piece->oemNumbers()->pluck('number')->all();

        $this->assertEqualsCanonicalizing(['3919937'], $numeros);
        $this->assertNotContains('3802407', $numeros);
        $this->assertNotContains('3948095', $numeros);
        $this->assertNotContains('J919937', $numeros);
    }

    public function test_le_descriptif_cite_les_familles_moteur_et_les_reserves(): void
    {
        $piece = Part::where('sku', '3919937')->firstOrFail();

        $this->assertStringContainsString('6CT', $piece->description);
        $this->assertStringContainsString('3802407', $piece->description);
        $this->assertStringContainsString('Aucune machine', $piece->description);
    }

    public function test_rejouer_le_seeder_ne_cree_rien(): void
    {
        $id = Part::where('sku', '3919937')->value('id');

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\ChemiseCummins3919937Seeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame($id, Part::where('sku', '3919937')->value('id'));
        $this->assertSame(1, Part::where('sku', '3919937')->count());
        $this->assertSame(1, PartCategory::where('slug', 'chemise-cylindre')->count());
    }

    public function test_une_relance_ne_rend_pas_un_prix_pose_a_la_main(): void
    {
        $piece = Part::where('sku', '3919937')->firstOrFail();
        $piece->update(['selling_price' => 85000, 'stock_quantity' => 6, 'is_active' => true]);

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\ChemiseCummins3919937Seeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $piece->refresh();

        $this->assertEquals(85000, $piece->selling_price);
        $this->assertSame(6, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active);
    }
}
