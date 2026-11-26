<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetroRemplissagePreuvesTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(?string $notes, array $preuve = []): PartFitment
    {
        $marque = Brand::firstOrCreate(['slug' => 'marque-rr'], ['name' => 'Marque RR', 'is_active' => true]);
        $modele = VehicleModel::firstOrCreate(['brand_id' => $marque->id, 'slug' => 'modele-rr'], ['name' => 'Modèle RR', 'is_active' => true]);
        $categorie = PartCategory::firstOrCreate(['slug' => 'cat-rr'], ['name' => 'Cat RR']);
        $piece = Part::create(['name' => 'Pièce RR', 'part_category_id' => $categorie->id, 'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000]);

        return $piece->fitments()->create(['vehicle_model_id' => $modele->id, 'source' => FitmentSource::Declared, 'notes' => $notes] + $preuve);
    }

    private function migrer(): void
    {
        (require database_path('migrations/2026_11_26_230000_retro_remplir_les_preuves_depuis_les_notes.php'))->up();
    }

    public function test_seules_les_url_de_domaines_classes_sont_reprises(): void
    {
        $cat = $this->ligne('SOURCE DE PREMIER RANG — Caterpillar. https://parts.cat.com/en/catcorp/product/435-6493 liste DE150GC.');
        $deere = $this->ligne('Guide (https://www.deere.com/assets/pdfs/common/qrg/5m.pdf), mars 2021.');
        $cusco = $this->ligne('Sources : https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf ; https://www.hks-power.co.jp/en/product_search/product/935/media/print');
        $mixte = $this->ligne('https://www.cusco.co.jp/a.pdf et https://exemple-inconnu.test/b');
        $texte = $this->ligne('Source : MazdaPartsNow / Mazda USA, 2016-2020.');
        $deja = $this->ligne('https://parts.cat.com/x', ['evidence_level' => 'unverified']);

        $this->migrer();
        $this->migrer(); // rejouable

        $this->assertSame('manufacturer_official', $cat->fresh()->evidence_level);
        $this->assertSame(['https://parts.cat.com/en/catcorp/product/435-6493'], $cat->fresh()->evidence_sources);
        $this->assertSame('manufacturer_official', $deere->fresh()->evidence_level);
        $this->assertSame(['https://www.deere.com/assets/pdfs/common/qrg/5m.pdf'], $deere->fresh()->evidence_sources);
        $this->assertSame('aftermarket_crossref', $cusco->fresh()->evidence_level);
        $this->assertCount(2, $cusco->fresh()->evidence_sources);
        foreach ([$mixte, $texte] as $intacte) {
            $this->assertNull($intacte->fresh()->evidence_level);
            $this->assertNull($intacte->fresh()->evidence_sources);
        }
        $this->assertSame('unverified', $deja->fresh()->evidence_level);
        $this->assertNull($deja->fresh()->evidence_sources);

        $this->assertTrue(\App\Support\FitmentEvidence::isDocumented($cat->fresh()));
        $this->assertFalse(\App\Support\FitmentEvidence::isDocumented($cusco->fresh()));
    }
}
