<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Part;
use App\Models\VehicleModel;
use App\Support\CompatibilitesPourApercu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'apercu de partage d'une piece (et d'un accessoire) montre quelques
 * vehicules compatibles, plus la description catalogue.
 *
 * C'est ce texte que WhatsApp lit dans og:description. Le corps de la page
 * publique conserve la description, qui reste utile une fois la page ouverte.
 */
class ApercuCompatibilitePiecesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);

        config(['app.url' => 'https://partage-essai.example']);
    }

    public function test_l_apercu_d_une_piece_cite_ses_compatibilites_et_non_sa_description(): void
    {
        $piece = $this->pieceAvecDescription();
        $this->declarer($piece, [
            ['name' => 'Corolla', 'generation' => 'E100', 'year_from' => 1991, 'year_to' => 1995, 'engine_code' => '4A-FE'],
            ['name' => 'Corolla', 'generation' => 'E110', 'year_from' => 1995, 'year_to' => 2000, 'engine_code' => '4A-FE'],
            ['name' => 'Camry', 'generation' => 'XV10', 'year_from' => 1992, 'year_to' => 1996, 'engine_code' => '5S-FE'],
            ['name' => 'Camry', 'generation' => 'XV20', 'year_from' => 1996, 'year_to' => 2001, 'engine_code' => '5S-FE'],
            ['name' => 'RAV4', 'generation' => 'XA10', 'year_from' => 1994, 'year_to' => 2000, 'engine_code' => '3S-FE'],
        ]);

        $attendu = 'Compatible : Toyota Corolla E100 (1991-1995, 4A-FE), Toyota Corolla E110 (1995-2000, 4A-FE), Toyota Camry XV10 (1992-1996, 5S-FE), Toyota Camry XV20 (1996-2001, 5S-FE), Toyota RAV4 XA10 (1994-2000, 3S-FE)';

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:description" content="'.$attendu.'">',
            $html,
        );
        $this->assertStringContainsString(
            '<meta name="description" content="'.$attendu.'">',
            $html,
        );
        $this->assertStringNotContainsString(
            'Visse, ancienne reference remplacee par le 90915-YZZN1',
            explode('</head>', $html)[0],
        );
        $this->assertStringContainsString(
            'Visse, ancienne reference remplacee par le 90915-YZZN1 sur les catalogues nord-americains.',
            $html,
        );
    }

    public function test_au_dela_de_cinq_vehicules_l_apercu_ajoute_et_n_autres(): void
    {
        $piece = $this->pieceAvecDescription();
        $this->declarer($piece, [
            ['name' => 'Camry', 'generation' => 'XV70', 'year_from' => 2018, 'year_to' => null, 'engine_code' => 'A25A-FKS'],
            ['name' => 'Camry', 'generation' => 'XV70', 'year_from' => 2018, 'year_to' => null, 'engine_code' => 'A25A-FXS'],
            ['name' => 'RAV4', 'generation' => 'XA50', 'year_from' => 2019, 'year_to' => null, 'engine_code' => 'A25A-FKS'],
            ['name' => 'RAV4', 'generation' => 'XA50', 'year_from' => 2019, 'year_to' => null, 'engine_code' => 'A25A-FXS'],
            ['name' => 'Corolla', 'generation' => 'E140', 'year_from' => 2006, 'year_to' => 2008, 'engine_code' => '1ZZ-FE'],
            ['name' => 'Corolla', 'generation' => 'E180', 'year_from' => 2017, 'year_to' => 2019, 'engine_code' => '2ZR-FE'],
            ['name' => 'Yaris', 'generation' => 'XP130', 'year_from' => 2011, 'year_to' => 2019, 'engine_code' => '1NZ-FE'],
        ]);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:description" content="Compatible : Toyota Camry XV70 (depuis 2018, A25A-FKS), Toyota Camry XV70 (depuis 2018, A25A-FXS), Toyota RAV4 XA50 (depuis 2019, A25A-FKS), Toyota RAV4 XA50 (depuis 2019, A25A-FXS), Toyota Corolla E140 (2006-2008, 1ZZ-FE) et 2 autres">',
            $html,
        );
        $this->assertStringNotContainsString('Toyota Yaris XP130', explode('</head>', $html)[0]);
    }

    public function test_les_compatibilites_identiques_ne_sont_citees_qu_une_fois(): void
    {
        $modele = $this->modele('Corolla', 'E100');
        $piece = $this->pieceAvecDescription();
        $piece->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'year_from' => 1991,
            'year_to' => 1995,
            'engine_code' => '4A-FE',
            'source' => FitmentSource::Declared,
        ]);
        $piece->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'year_from' => 1991,
            'year_to' => 1995,
            'engine_code' => '4A-FE',
            'source' => FitmentSource::Declared,
            'notes' => 'Doublon a ecarter',
        ]);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:description" content="Compatible : Toyota Corolla E100 (1991-1995, 4A-FE)">',
            $html,
        );
        $this->assertStringNotContainsString('et 1 autres', $html);
    }

    public function test_sans_compatibilite_l_apercu_reprend_les_precisions_et_pas_la_description(): void
    {
        $piece = $this->pieceAvecDescription([
            'manufacturer_reference' => '90915-10001',
        ]);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();
        preg_match('/<meta property="og:description" content="([^"]*)">/', $html, $trouve);

        $this->assertNotEmpty($trouve[1] ?? null);
        $this->assertStringNotContainsString('Visse', $trouve[1]);
        $this->assertStringNotContainsString('Compatible :', $trouve[1]);
        $this->assertStringContainsString('90915-10001', $trouve[1]);
        $this->assertStringContainsString(
            'Visse, ancienne reference remplacee par le 90915-YZZN1 sur les catalogues nord-americains.',
            $html,
        );
    }

    public function test_l_apercu_d_un_accessoire_suit_la_meme_regle(): void
    {
        $accessoire = Accessory::factory()->create([
            'name' => 'Tapis de sol',
            'description' => 'Note interne a ne pas partager.',
            'is_active' => true,
            'is_available' => true,
            'stock_quantity' => 2,
        ]);
        $accessoire->fitments()->create([
            'vehicle_model_id' => $this->modele('Corolla', 'E210')->id,
            'year_from' => 2019,
            // Les compatibilites d'accessoires n'ont pas de colonne
            // engine_code : on n'en invente pas dans l'apercu.
            'source' => FitmentSource::Declared,
        ]);

        $html = $this->get('/a/'.$accessoire->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:description" content="Compatible : Toyota Corolla E210 (depuis 2019)">',
            $html,
        );
        $this->assertStringNotContainsString(
            'Note interne a ne pas partager.',
            explode('</head>', $html)[0],
        );
        $this->assertStringContainsString('Note interne a ne pas partager.', $html);
    }

    public function test_le_formateur_n_invente_ni_annee_ni_code_moteur(): void
    {
        $fitment = $this->pieceAvecDescription()->fitments()->create([
            'vehicle_model_id' => $this->modele('Hilux', 'AN120')->id,
            'source' => FitmentSource::Declared,
        ]);
        $fitment->load('vehicleModel.brand');

        $this->assertSame('Toyota Hilux AN120', CompatibilitesPourApercu::formater($fitment));
        $this->assertSame(
            'Compatible : Toyota Hilux AN120',
            CompatibilitesPourApercu::texte([$fitment]),
        );
    }

    private function pieceAvecDescription(array $attributs = []): Part
    {
        return Part::factory()->create(array_merge([
            'name' => 'Filtre a huile visse 90915-10001',
            'description' => 'Visse, ancienne reference remplacee par le 90915-YZZN1 sur les catalogues nord-americains.',
            'is_active' => true,
            'is_available' => true,
        ], $attributs));
    }

    /**
     * @param  list<array{name: string, generation: string, year_from: ?int, year_to: ?int, engine_code: string}>  $lignes
     */
    private function declarer(Part $piece, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            $piece->fitments()->create([
                'vehicle_model_id' => $this->modele($ligne['name'], $ligne['generation'])->id,
                'year_from' => $ligne['year_from'],
                'year_to' => $ligne['year_to'],
                'engine_code' => $ligne['engine_code'],
                'source' => FitmentSource::Declared,
            ]);
        }
    }

    private function modele(string $nom, string $generation): VehicleModel
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'toyota-apercu'],
            ['name' => 'Toyota', 'is_active' => true],
        );

        return VehicleModel::firstOrCreate(
            ['slug' => 'apercu-'.strtolower($nom).'-'.strtolower($generation)],
            [
                'brand_id' => $marque->id,
                'name' => $nom,
                'generation' => $generation,
                'is_active' => true,
            ],
        );
    }
}
