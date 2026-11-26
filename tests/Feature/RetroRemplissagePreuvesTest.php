<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Support\FitmentEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetroRemplissagePreuvesTest extends TestCase
{
    use RefreshDatabase;

    private const FICHIER = 'migrations/2026_11_26_230000_retro_remplir_les_preuves_depuis_les_notes.php';

    private function ligne(string $sku, string $slug, ?string $notes, array $autres = []): PartFitment
    {
        $marque = Brand::firstOrCreate(['slug' => 'marque-rr'], ['name' => 'Marque RR', 'is_active' => true]);
        $modele = VehicleModel::firstOrCreate(['brand_id' => $marque->id, 'slug' => $slug], ['name' => $slug, 'is_active' => true]);
        $categorie = PartCategory::firstOrCreate(['slug' => 'cat-rr'], ['name' => 'Cat RR']);
        $piece = Part::firstOrCreate(['sku' => $sku], ['name' => 'Pièce '.$sku, 'part_category_id' => $categorie->id, 'type' => 'oem', 'condition' => 'new', 'selling_price' => 1000]);

        return $piece->fitments()->create(['vehicle_model_id' => $modele->id, 'source' => FitmentSource::Declared, 'notes' => $notes] + $autres);
    }

    public function test_seules_les_lignes_relues_recoivent_leur_niveau_et_le_down_les_rend(): void
    {
        $migration = require database_path(self::FICHIER);
        $cat = $this->ligne('435-6493', 'cat-140-gc', 'Fiche officielle : https://parts.cat.com/en/catcorp/product/435-6493 liste 140 GC.');
        $cusco = $this->ligne('90915-10003', 'yaris-xp90', 'Sources : https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf ; https://www.hks-power.co.jp/en/product_search/product/935/media/print',
            ['engine_code' => '1NZ-FE', 'year_from' => 2005, 'year_to' => 2010]);
        // Même domaine officiel, mais ligne non relue : jamais promue.
        $autreDeere = $this->ligne('RE504836', 'jd-6110m', 'Voir https://www.deere.com/assets/pdfs/common/qrg/5m-series-5075m-5090m-5100m-5115m-tractors.pdf');
        // Ligne listée, mais la note ne cite pas la source.
        $sansUrl = $this->ligne('RE59754', 'jd-5410', 'Guide John Deere relu, sans adresse.');
        $deja = $this->ligne('RE504836', 'jd-5090m', 'https://www.deere.com/assets/pdfs/common/qrg/5m-series-5075m-5090m-5100m-5115m-tractors.pdf', ['evidence_level' => 'unverified']);

        $migration->up();
        $migration->up(); // rejouable

        $this->assertSame('manufacturer_official', $cat->fresh()->evidence_level);
        $this->assertSame(['https://parts.cat.com/en/catcorp/product/435-6493'], $cat->fresh()->evidence_sources);
        $this->assertTrue(FitmentEvidence::isDocumented($cat->fresh()));
        $this->assertSame('aftermarket_crossref', $cusco->fresh()->evidence_level);
        $this->assertCount(2, $cusco->fresh()->evidence_sources);
        $this->assertFalse(FitmentEvidence::isDocumented($cusco->fresh()));
        foreach ([$autreDeere, $sansUrl] as $intacte) {
            $this->assertNull($intacte->fresh()->evidence_level);
            $this->assertNull($intacte->fresh()->evidence_sources);
        }
        $this->assertSame('unverified', $deja->fresh()->evidence_level);

        // Une preuve corrigée à la main ensuite n'est pas effacée par down().
        $cusco->forceFill(['evidence_sources' => ['https://www.cusco.co.jp/autre.pdf']])->save();
        $migration->down();
        $this->assertNull($cat->fresh()->evidence_level);
        $this->assertNull($cat->fresh()->evidence_sources);
        $this->assertSame(['https://www.cusco.co.jp/autre.pdf'], $cusco->fresh()->evidence_sources);
        $this->assertSame('unverified', $deja->fresh()->evidence_level);
    }
}
