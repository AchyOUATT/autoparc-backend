<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Support\CorrigerLesFiltresYzznEtYzzd4;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorrigerLesFiltresYzznEtYzzd4Test extends TestCase
{
    use RefreshDatabase;

    private PartCategory $categorie;

    private Manufacturer $fabricant;

    private Brand $toyota;

    private Brand $lexus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categorie = PartCategory::create(['name' => 'Filtre à huile', 'slug' => 'filtre-huile']);
        $this->fabricant = Manufacturer::create(['name' => 'Chine']);
        $this->toyota = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);
        $this->lexus = Brand::create(['name' => 'Lexus', 'slug' => 'lexus', 'is_active' => true]);
    }

    public function test_la_migration_applique_les_priorites_1_a_3(): void
    {
        $catalogue = $this->catalogueComplet();

        $rapport = CorrigerLesFiltresYzznEtYzzd4::run();

        $this->assertSame(3, $rapport['removed']);
        $this->assertSame(14, $rapport['moved']);
        $this->assertSame(10, $rapport['flagged']);
        $this->assertSame(0, $rapport['kept_conflict']);
        $this->assertTrue($rapport['oem_10009_updated']);
        $this->assertSame([], $rapport['skipped_missing_part']);
        $this->assertSame([], $rapport['skipped_missing_model']);

        $this->assertNull($this->ligne('90915-YZZN1', 'Toyota', 'Corolla', 'E140', '1ZZ-FE'));
        $this->assertNull($this->ligne('90915-10003', 'Toyota', 'Corolla', 'E140', '1ZZ-FE'));

        $yaris = $this->ligne('90915-10003', 'Toyota', 'Yaris', 'XP130', '1NZ-FE');
        $this->assertNotNull($yaris);
        $this->assertSame('NCP131', $yaris->chassis_code);
        $this->assertSame(2010, (int) $yaris->year_from);
        $this->assertSame(2020, (int) $yaris->year_to);
        $this->assertStringContainsString('ancienne note Yaris XP130', $yaris->notes);
        $this->assertStringContainsString(CorrigerLesFiltresYzznEtYzzd4::HKS_URL, $yaris->notes);
        $this->assertNull($this->ligne('90915-YZZN1', 'Toyota', 'Yaris', 'XP130', '1NZ-FE'));

        $prius = $this->ligne('90915-10003', 'Toyota', 'Prius', 'XW50', '2ZR-FXE');
        $this->assertSame('ZVW50/ZVW51/ZVW55', $prius->chassis_code);
        $this->assertSame(2017, (int) $prius->year_from);
        $this->assertSame(2022, (int) $prius->year_to);
        $this->assertStringContainsString('09/2017', $prius->notes);

        $e180Fae = $this->ligne('90915-10003', 'Toyota', 'Corolla', 'E180', '2ZR-FAE');
        $this->assertSame('ZRE186H', $e180Fae->chassis_code);
        $this->assertSame(2016, (int) $e180Fae->year_from);
        $this->assertSame(2018, (int) $e180Fae->year_to);
        $this->assertStringContainsString('09/2016–05/2018', $e180Fae->notes);
        $this->assertStringContainsString('ancienne note Yaris XP130', $yaris->notes);

        $e180Fe = $this->ligne('90915-YZZN1', 'Toyota', 'Corolla', 'E180', '2ZR-FE');
        $this->assertNotNull($e180Fe);
        $this->assertNull($this->ligne('90915-10003', 'Toyota', 'Corolla', 'E180', '2ZR-FE'));
        $this->assertSame(2017, (int) $e180Fe->year_from);
        $this->assertSame(2019, (int) $e180Fe->year_to);
        $this->assertStringContainsString('À vérifier', $e180Fe->notes);
        $this->assertStringContainsString('note E180 2ZR-FE', $e180Fe->notes);

        $e210Fae = $this->ligne('90915-10003', 'Toyota', 'Corolla', 'E210', '2ZR-FAE');
        $this->assertSame('ZRE212/ZRE212W', $e210Fae->chassis_code);
        $this->assertSame(2019, (int) $e210Fae->year_from);
        $this->assertSame(2022, (int) $e210Fae->year_to);

        $e210Fxe = $this->ligne('90915-10003', 'Toyota', 'Corolla', 'E210', '2ZR-FXE');
        $this->assertSame('ZWE211/ZWE214/ZWE215/ZWE219', $e210Fxe->chassis_code);
        $this->assertSame(2019, (int) $e210Fxe->year_from);
        $this->assertNull($e210Fxe->year_to);

        $e210Fe = $this->ligne('90915-YZZN1', 'Toyota', 'Corolla', 'E210', '2ZR-FE');
        $this->assertNotNull($e210Fe);
        $this->assertNull($this->ligne('90915-10003', 'Toyota', 'Corolla', 'E210', '2ZR-FE'));
        $this->assertStringContainsString('À vérifier', $e210Fe->notes);

        $camryHybride = $this->ligne('90915-YZZN2', 'Toyota', 'Camry', 'XV70', 'A25A-FXS');
        $this->assertSame('AXVH70', $camryHybride->chassis_code);
        $this->assertSame(2017, (int) $camryHybride->year_from);
        $this->assertSame(2023, (int) $camryHybride->year_to);
        $this->assertStringContainsString(CorrigerLesFiltresYzznEtYzzd4::BOSHOKU_URL, $camryHybride->notes);
        $this->assertStringContainsString('01/2017–12/2023', $camryHybride->notes);

        $camryFks = $this->ligne('90915-YZZN1', 'Toyota', 'Camry', 'XV70', 'A25A-FKS');
        $this->assertNotNull($camryFks);
        $this->assertNull($this->ligne('90915-YZZN2', 'Toyota', 'Camry', 'XV70', 'A25A-FKS'));
        $this->assertStringContainsString('À vérifier', $camryFks->notes);
        $this->assertSame(2018, (int) $camryFks->year_from);

        $this->assertSame('AXAH52/AXAH54/AXAP54', $this->ligne('90915-YZZN2', 'Toyota', 'RAV4', 'XA50', 'A25A-FXS')->chassis_code);
        $this->assertSame(2025, (int) $this->ligne('90915-YZZN2', 'Toyota', 'RAV4', 'XA50', 'A25A-FXS')->year_to);
        $this->assertNotNull($this->ligne('90915-YZZN1', 'Toyota', 'RAV4', 'XA50', 'A25A-FKS'));
        $this->assertNull($this->ligne('90915-YZZN2', 'Toyota', 'RAV4', 'XA50', 'A25A-FKS'));

        $avalon = $this->ligne('90915-YZZN1', 'Toyota', 'Avalon', null, 'A25A-FXS');
        $this->assertNotNull($avalon);
        $this->assertNull($this->ligne('90915-YZZN2', 'Toyota', 'Avalon', null, 'A25A-FXS'));
        $this->assertStringContainsString('À vérifier', $avalon->notes);
        $this->assertSame(2019, (int) $avalon->year_from);
        $this->assertSame(2022, (int) $avalon->year_to);

        $this->assertNotNull($this->ligne('90915-YZZN1', 'Toyota', 'Highlander', null, 'A25A-FXS'));
        $this->assertNotNull($this->ligne('90915-YZZN1', 'Toyota', 'Highlander', null, 'T24A-FTS'));
        $this->assertNotNull($this->ligne('90915-YZZN1', 'Toyota', 'Sienna', null, 'A25A-FXS'));
        $this->assertNotNull($this->ligne('90915-YZZN1', 'Toyota', 'Venza', 'XU80', 'A25A-FXS'));
        $this->assertNull($this->ligne('90915-YZZN2', 'Toyota', 'Highlander', null, 'A25A-FXS'));
        $this->assertNull($this->ligne('90915-YZZN2', 'Toyota', 'Sienna', null, 'A25A-FXS'));
        $this->assertNull($this->ligne('90915-YZZN2', 'Toyota', 'Venza', 'XU80', 'A25A-FXS'));

        $m20a = $this->ligne('90915-YZZN2', 'Toyota', 'Corolla', 'E210', 'M20A-FKS');
        $this->assertSame('MZEA12H/MZEA12W', $m20a->chassis_code);
        $this->assertSame(2020, (int) $m20a->year_from);
        $this->assertSame(2025, (int) $m20a->year_to);

        $esFxs = $this->ligne('90915-YZZN2', 'Lexus', 'ES', 'XV70', 'A25A-FXS');
        $this->assertSame('AXZH10', $esFxs->chassis_code);
        $this->assertSame(2019, (int) $esFxs->year_from);
        $this->assertSame(2026, (int) $esFxs->year_to);
        $this->assertNotNull($this->ligne('90915-YZZN1', 'Lexus', 'ES', 'XV70', 'A25A-FKS'));
        $this->assertNull($this->ligne('90915-YZZN2', 'Lexus', 'ES', 'XV70', 'A25A-FKS'));

        $this->assertSame('AAZA20/AAZA25', $this->ligne('90915-YZZN2', 'Lexus', 'NX', 'AZ20', 'A25A-FKS')->chassis_code);
        $this->assertSame(2021, (int) $this->ligne('90915-YZZN2', 'Lexus', 'NX', 'AZ20', 'A25A-FKS')->year_from);
        $this->assertSame('AAZH20/AAZH25/AAZH26', $this->ligne('90915-YZZN2', 'Lexus', 'NX', 'AZ20', 'A25A-FXS')->chassis_code);
        $this->assertSame('AALH10/AALH15/AALH16', $this->ligne('90915-YZZN2', 'Lexus', 'RX', 'AL30', 'A25A-FXS')->chassis_code);
        $this->assertSame(2022, (int) $this->ligne('90915-YZZN2', 'Lexus', 'RX', 'AL30', 'A25A-FXS')->year_from);

        $uxFks = $this->ligne('90915-YZZN2', 'Lexus', 'UX', 'ZA10', 'M20A-FKS');
        $this->assertSame('MZAA10', $uxFks->chassis_code);
        $this->assertSame(2018, (int) $uxFks->year_from);
        $this->assertSame(2023, (int) $uxFks->year_to);
        $this->assertSame('MZAH10/MZAH15/MZAH11/MZAH16', $this->ligne('90915-YZZN2', 'Lexus', 'UX', 'ZA10', 'M20A-FXS')->chassis_code);

        $this->assertSame(10, $catalogue['yzzn1']->fresh()->fitments()->count());
        $this->assertSame(9, $catalogue['yzzn2']->fresh()->fitments()->count());
        $this->assertSame(6, $catalogue['sku10003']->fresh()->fitments()->count());

        $this->assertNull($this->ligne('90915-YZZD4', 'Toyota', 'Land Cruiser Prado', 'J120', '5L-E'));
        $this->assertNull($this->ligne('90915-30002', 'Toyota', 'Land Cruiser Prado', 'J120', '5L-E'));
        $this->assertNull($this->ligne('90915-YZZD4', 'Toyota', 'Land Cruiser', 'J200', '1GR-FE'));
        $prado1gr = $this->ligne('90915-YZZD4', 'Toyota', 'Land Cruiser Prado', 'J120', '1GR-FE');
        $this->assertNotNull($prado1gr);
        $this->assertSame('note Prado 1GR-FE', $prado1gr->notes);
        $this->assertNotNull($this->ligne('90915-YZZD4', 'Toyota', 'Land Cruiser', 'J100', '2UZ-FE'));
        $this->assertNotNull($this->ligne('90915-YZZD4', 'Toyota', 'Hilux', 'AN10', '1GR-FE'));

        $existant10003 = $this->ligne('90915-10003', 'Toyota', 'Corolla', 'E120', '1ZZ-FE');
        $this->assertSame('ZZE122/ZZE124', $existant10003->chassis_code);
        $this->assertSame('note CUSCO E120', $existant10003->notes);

        $yzza1 = $this->ligne('04152-YZZA1', 'Toyota', 'Camry', 'XV50', '2AR-FE');
        $this->assertNotNull($yzza1);
        $this->assertSame('note YZZA1 intacte', $yzza1->notes);

        foreach (['yzzn1', 'yzzn2', 'sku10003', 'yzzd4', 'sku30002', 'yzza1'] as $cle) {
            $piece = $catalogue[$cle]->fresh();
            $this->assertSame((float) $catalogue[$cle]->selling_price, (float) $piece->selling_price);
            $this->assertSame($catalogue[$cle]->stock_quantity, $piece->stock_quantity);
            $this->assertSame($catalogue[$cle]->is_active, $piece->is_active);
        }

        $numero = OemNumber::query()->where('normalized_number', OemNumber::normalize('90915-10009'))->first();
        $this->assertFalse($numero->is_superseded);
        $this->assertNull($numero->superseded_by_id);
        $this->assertTrue($catalogue['yzzn2']->fresh()->oemNumbers->contains('id', $numero->id));
        $this->assertFalse($catalogue['yzzn1']->fresh()->oemNumbers->contains('id', $numero->id));
    }

    public function test_un_doublon_compatible_fusionne_les_notes_avant_suppression(): void
    {
        $yzzn1 = $this->piece('90915-YZZN1', 15, true, 2500);
        $sku10003 = $this->piece('90915-10003', 0, false, 0);
        $this->modele($this->toyota, 'yaris-xp130', 'Yaris', 'XP130');
        $this->poser($yzzn1, 'Toyota', 'Yaris', 'XP130', '1NZ-FE', 2011, 2019, 'restriction YZZN1 : filtre court');
        $this->poser($sku10003, 'Toyota', 'Yaris', 'XP130', '1NZ-FE', 2010, 2018, 'note deja presente sur le 10003');

        $rapport = CorrigerLesFiltresYzznEtYzzd4::run();

        $this->assertSame(1, $rapport['merged']);
        $this->assertSame(1, $rapport['moved']);
        $this->assertSame(0, $rapport['kept_conflict']);
        $this->assertNull($this->ligne('90915-YZZN1', 'Toyota', 'Yaris', 'XP130', '1NZ-FE'));
        $cible = $this->ligne('90915-10003', 'Toyota', 'Yaris', 'XP130', '1NZ-FE');
        $this->assertSame('NCP131', $cible->chassis_code);
        $this->assertSame(2010, (int) $cible->year_from);
        $this->assertSame(2020, (int) $cible->year_to);
        $this->assertStringContainsString('note deja presente sur le 10003', $cible->notes);
        $this->assertStringContainsString('restriction YZZN1 : filtre court', $cible->notes);
        $this->assertStringContainsString(CorrigerLesFiltresYzznEtYzzd4::HKS_URL, $cible->notes);
        $this->assertSame(1, substr_count($cible->notes, '[audit filtres 2026-11-19]'));
    }

    public function test_un_doublon_en_conflit_conserve_les_deux_lignes(): void
    {
        $yzzn1 = $this->piece('90915-YZZN1', 15, true, 2500);
        $sku10003 = $this->piece('90915-10003', 0, false, 0);
        $this->modele($this->toyota, 'yaris-xp130', 'Yaris', 'XP130');
        $this->poser($yzzn1, 'Toyota', 'Yaris', 'XP130', '1NZ-FE', 2011, 2019, 'note source XP130');
        $this->poser($sku10003, 'Toyota', 'Yaris', 'XP130', '1NZ-FE', 2005, 2008, 'note cible autre periode', 'NCP91');

        $rapport = CorrigerLesFiltresYzznEtYzzd4::run();

        $this->assertSame(0, $rapport['merged']);
        $this->assertSame(1, $rapport['kept_conflict']);
        $source = $this->ligne('90915-YZZN1', 'Toyota', 'Yaris', 'XP130', '1NZ-FE');
        $cible = $this->ligne('90915-10003', 'Toyota', 'Yaris', 'XP130', '1NZ-FE');
        $this->assertNotNull($source);
        $this->assertNotNull($cible);
        $this->assertSame(2011, (int) $source->year_from);
        $this->assertSame(2019, (int) $source->year_to);
        $this->assertStringContainsString('note source XP130', $source->notes);
        $this->assertStringContainsString('12/2010–01/2020', $source->notes);
        $this->assertSame('NCP91', $cible->chassis_code);
        $this->assertSame(2005, (int) $cible->year_from);
        $this->assertSame(2008, (int) $cible->year_to);
        $this->assertSame('note cible autre periode', $cible->notes);
    }

    public function test_la_migration_est_idempotente(): void
    {
        $this->catalogueComplet();

        CorrigerLesFiltresYzznEtYzzd4::run();
        $apresPremier = $this->empreinteCatalogue();
        $notesYaris = $this->ligne('90915-10003', 'Toyota', 'Yaris', 'XP130', '1NZ-FE')->notes;

        $rapport = CorrigerLesFiltresYzznEtYzzd4::run();

        $this->assertSame(0, $rapport['removed']);
        $this->assertSame(0, $rapport['moved']);
        $this->assertSame(0, $rapport['flagged']);
        $this->assertSame(0, $rapport['kept_conflict']);
        $this->assertFalse($rapport['oem_10009_updated']);
        $this->assertSame($apresPremier, $this->empreinteCatalogue());
        $this->assertSame($notesYaris, $this->ligne('90915-10003', 'Toyota', 'Yaris', 'XP130', '1NZ-FE')->notes);
        $this->assertSame(1, substr_count($notesYaris, '[audit filtres 2026-11-19]'));
        $notesE180 = $this->ligne('90915-YZZN1', 'Toyota', 'Corolla', 'E180', '2ZR-FE')->notes;
        $this->assertSame(1, substr_count($notesE180, '[audit filtres 2026-11-19]'));
    }

    public function test_sans_piece_cible_les_lignes_restent_en_place(): void
    {
        $yzzn1 = $this->piece('90915-YZZN1', 15, true, 3000);
        $this->modele($this->toyota, 'yaris-xp130', 'Yaris', 'XP130');
        $this->poser($yzzn1, 'Toyota', 'Yaris', 'XP130', '1NZ-FE', 2011, 2019, 'note XP130');

        $rapport = CorrigerLesFiltresYzznEtYzzd4::run();

        $this->assertContains('90915-10003', $rapport['skipped_missing_part']);
        $this->assertNotNull($this->ligne('90915-YZZN1', 'Toyota', 'Yaris', 'XP130', '1NZ-FE'));
        $this->assertSame(2011, (int) $this->ligne('90915-YZZN1', 'Toyota', 'Yaris', 'XP130', '1NZ-FE')->year_from);
        $this->assertSame(15, $yzzn1->fresh()->stock_quantity);
        $this->assertSame(3000.0, (float) $yzzn1->fresh()->selling_price);
    }

    public function test_la_classe_migration_appelle_bien_la_correction(): void
    {
        $this->catalogueComplet();
        $migration = require database_path('migrations/2026_11_19_150000_corriger_les_compatibilites_yzzn_et_yzzd4.php');
        $migration->up();

        $this->assertNull($this->ligne('90915-YZZN1', 'Toyota', 'Corolla', 'E140', '1ZZ-FE'));
        $this->assertNotNull($this->ligne('90915-10003', 'Toyota', 'Yaris', 'XP130', '1NZ-FE'));
    }

    /**
     * @return array<string, Part>
     */
    private function catalogueComplet(): array
    {
        $yzzn1 = $this->piece('90915-YZZN1', 15, true, 2500);
        $yzzn2 = $this->piece('90915-YZZN2', 15, true, 2500);
        $sku10003 = $this->piece('90915-10003', 0, false, 0);
        $yzzd4 = $this->piece('90915-YZZD4', 15, true, 4000);
        $sku30002 = $this->piece('90915-30002', 15, true, 4000);
        $yzza1 = $this->piece('04152-YZZA1', 15, true, 3500);

        $yzzn1Numero = OemNumber::create([
            'number' => '90915-YZZN1',
            'label' => 'YZZN1',
            'is_superseded' => false,
        ]);
        $cent009 = OemNumber::create([
            'number' => '90915-10009',
            'label' => '10009',
            'is_superseded' => true,
            'superseded_by_id' => $yzzn1Numero->id,
        ]);
        $yzzn1->oemNumbers()->attach($yzzn1Numero->id, ['is_primary' => true]);
        $yzzn1->oemNumbers()->attach($cent009->id, ['is_primary' => false]);
        $yzzn2Numero = OemNumber::create([
            'number' => '90915-YZZN2',
            'label' => 'YZZN2',
            'is_superseded' => false,
        ]);
        $yzzn2->oemNumbers()->attach($yzzn2Numero->id, ['is_primary' => true]);

        $this->modele($this->toyota, 'corolla-e120', 'Corolla', 'E120');
        $this->modele($this->toyota, 'corolla-e140', 'Corolla', 'E140');
        $this->modele($this->toyota, 'corolla-e180', 'Corolla', 'E180');
        $this->modele($this->toyota, 'corolla-e210', 'Corolla', 'E210');
        $this->modele($this->toyota, 'yaris-xp130', 'Yaris', 'XP130');
        $this->modele($this->toyota, 'prius-xw50', 'Prius', 'XW50');
        $this->modele($this->toyota, 'camry-xv70', 'Camry', 'XV70');
        $this->modele($this->toyota, 'rav4-xa50', 'RAV4', 'XA50');
        $this->modele($this->toyota, 'avalon', 'Avalon', null);
        $this->modele($this->toyota, 'highlander', 'Highlander', null);
        $this->modele($this->toyota, 'sienna', 'Sienna', null);
        $this->modele($this->toyota, 'venza-xu80', 'Venza', 'XU80');
        $this->modele($this->toyota, 'prado-j120', 'Land Cruiser Prado', 'J120');
        $this->modele($this->toyota, 'land-cruiser-j100', 'Land Cruiser', 'J100');
        $this->modele($this->toyota, 'land-cruiser-j200', 'Land Cruiser', 'J200');
        $this->modele($this->toyota, 'hilux-an10', 'Hilux', 'AN10');
        $this->modele($this->toyota, 'camry-xv50', 'Camry', 'XV50');
        $this->modele($this->lexus, 'es-xv70', 'ES', 'XV70');
        $this->modele($this->lexus, 'nx-az20', 'NX', 'AZ20');
        $this->modele($this->lexus, 'rx-al30', 'RX', 'AL30');
        $this->modele($this->lexus, 'ux-za10', 'UX', 'ZA10');

        $this->poser($sku10003, 'Toyota', 'Corolla', 'E120', '1ZZ-FE', 2000, 2006, 'note CUSCO E120', 'ZZE122/ZZE124');

        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E140', '1ZZ-FE', 2006, 2008, 'note E140 1ZZ-FE');
        $this->poser($yzzn1, 'Toyota', 'Yaris', 'XP130', '1NZ-FE', 2011, 2019, 'ancienne note Yaris XP130');
        $this->poser($yzzn1, 'Toyota', 'Prius', 'XW50', '2ZR-FXE', 2018, 2022, 'note Prius XW50');
        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E180', '2ZR-FE', 2017, 2019, 'note E180 2ZR-FE');
        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E180', '2ZR-FAE', 2017, 2019, 'note E180 2ZR-FAE');
        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E210', '2ZR-FE', 2019, null, 'note E210 2ZR-FE');
        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E210', '2ZR-FAE', 2019, null, 'note E210 2ZR-FAE');
        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E210', '2ZR-FXE', 2020, null, 'note E210 2ZR-FXE');
        $this->poser($yzzn1, 'Toyota', 'Corolla', 'E210', 'M20A-FKS', 2019, null, 'note E210 M20A');
        $this->poser($yzzn1, 'Toyota', 'Camry', 'XV70', 'A25A-FKS', 2018, null, 'note Camry FKS');
        $this->poser($yzzn1, 'Toyota', 'Camry', 'XV70', 'A25A-FXS', 2018, null, 'note Camry FXS');
        $this->poser($yzzn1, 'Toyota', 'RAV4', 'XA50', 'A25A-FKS', 2019, null, 'note RAV4 FKS');
        $this->poser($yzzn1, 'Toyota', 'RAV4', 'XA50', 'A25A-FXS', 2019, null, 'note RAV4 FXS');
        $this->poser($yzzn1, 'Toyota', 'Avalon', null, 'A25A-FXS', 2019, 2022, 'note Avalon');
        $this->poser($yzzn1, 'Toyota', 'Highlander', null, 'A25A-FXS', 2020, null, 'note Highlander A25A');
        $this->poser($yzzn1, 'Toyota', 'Highlander', null, 'T24A-FTS', 2023, null, 'note Highlander T24A');
        $this->poser($yzzn1, 'Toyota', 'Sienna', null, 'A25A-FXS', 2021, null, 'note Sienna');
        $this->poser($yzzn1, 'Toyota', 'Venza', 'XU80', 'A25A-FXS', 2021, 2024, 'note Venza');
        $this->poser($yzzn1, 'Lexus', 'ES', 'XV70', 'A25A-FXS', 2019, null, 'note ES FXS');
        $this->poser($yzzn1, 'Lexus', 'ES', 'XV70', 'A25A-FKS', 2021, null, 'note ES FKS');
        $this->poser($yzzn1, 'Lexus', 'NX', 'AZ20', 'A25A-FKS', 2022, null, 'note NX FKS');
        $this->poser($yzzn1, 'Lexus', 'NX', 'AZ20', 'A25A-FXS', 2022, null, 'note NX FXS');
        $this->poser($yzzn1, 'Lexus', 'RX', 'AL30', 'A25A-FXS', 2023, null, 'note RX');
        $this->poser($yzzn1, 'Lexus', 'UX', 'ZA10', 'M20A-FKS', 2019, null, 'note UX FKS');
        $this->poser($yzzn1, 'Lexus', 'UX', 'ZA10', 'M20A-FXS', 2019, null, 'note UX FXS');

        $this->poser($yzzd4, 'Toyota', 'Land Cruiser Prado', 'J120', '5L-E', 2002, 2009, 'note Prado 5L-E');
        $this->poser($yzzd4, 'Toyota', 'Land Cruiser Prado', 'J120', '1GR-FE', 2003, 2009, 'note Prado 1GR-FE');
        $this->poser($yzzd4, 'Toyota', 'Land Cruiser', 'J200', '1GR-FE', 2007, 2012, 'note LC200 1GR');
        $this->poser($yzzd4, 'Toyota', 'Land Cruiser', 'J100', '2UZ-FE', 1998, 2007, 'note LC100');
        $this->poser($yzzd4, 'Toyota', 'Hilux', 'AN10', '1GR-FE', 2005, 2013, 'note Hilux 1GR');

        $this->poser($yzza1, 'Toyota', 'Camry', 'XV50', '2AR-FE', 2012, 2017, 'note YZZA1 intacte');

        return compact('yzzn1', 'yzzn2', 'sku10003', 'yzzd4', 'sku30002', 'yzza1');
    }

    private function piece(string $sku, int $stock, bool $actif, int $prix): Part
    {
        return Part::create([
            'sku' => $sku,
            'name' => 'Filtre a huile visse '.$sku,
            'manufacturer_reference' => $sku,
            'part_category_id' => $this->categorie->id,
            'manufacturer_id' => $this->fabricant->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => $prix,
            'stock_quantity' => $stock,
            'is_active' => $actif,
            'is_available' => $actif,
        ]);
    }

    private function modele(Brand $marque, string $slug, string $nom, ?string $generation): VehicleModel
    {
        return VehicleModel::create([
            'brand_id' => $marque->id,
            'name' => $nom,
            'slug' => $slug,
            'generation' => $generation,
            'is_active' => true,
        ]);
    }

    private function poser(
        Part $piece,
        string $marque,
        string $modele,
        ?string $generation,
        string $moteur,
        ?int $debut,
        ?int $fin,
        string $notes,
        ?string $chassis = null,
    ): PartFitment {
        $modeleId = $this->trouverModele($marque, $modele, $generation)->id;

        return $piece->fitments()->create([
            'vehicle_model_id' => $modeleId,
            'engine_code' => $moteur,
            'year_from' => $debut,
            'year_to' => $fin,
            'chassis_code' => $chassis,
            'notes' => $notes,
            'source' => FitmentSource::Declared,
        ]);
    }

    private function ligne(string $sku, string $marque, string $modele, ?string $generation, string $moteur): ?object
    {
        $requete = DB::table('part_fitments as pf')
            ->join('parts as p', 'p.id', '=', 'pf.part_id')
            ->join('vehicle_models as vm', 'vm.id', '=', 'pf.vehicle_model_id')
            ->join('brands as b', 'b.id', '=', 'vm.brand_id')
            ->where('p.sku', $sku)
            ->where('b.name', $marque)
            ->where('vm.name', $modele)
            ->where('pf.engine_code', $moteur)
            ->select('pf.*');
        if ($generation === null) {
            $requete->whereNull('vm.generation');
        } else {
            $requete->where('vm.generation', $generation);
        }

        return $requete->first();
    }

    private function trouverModele(string $marque, string $modele, ?string $generation): VehicleModel
    {
        $requete = VehicleModel::query()
            ->whereHas('brand', fn ($q) => $q->where('name', $marque))
            ->where('name', $modele);
        if ($generation === null) {
            $requete->whereNull('generation');
        } else {
            $requete->where('generation', $generation);
        }

        return $requete->firstOrFail();
    }

    private function empreinteCatalogue(): string
    {
        return DB::table('part_fitments as pf')
            ->join('parts as p', 'p.id', '=', 'pf.part_id')
            ->join('vehicle_models as vm', 'vm.id', '=', 'pf.vehicle_model_id')
            ->join('brands as b', 'b.id', '=', 'vm.brand_id')
            ->orderBy('p.sku')
            ->orderBy('b.name')
            ->orderBy('vm.name')
            ->orderBy('vm.generation')
            ->orderBy('pf.engine_code')
            ->get(['p.sku', 'pf.chassis_code', 'pf.year_from', 'pf.year_to', 'pf.notes', 'pf.engine_code'])
            ->map(fn ($l) => implode('|', [
                $l->sku,
                $l->chassis_code,
                $l->year_from,
                $l->year_to,
                $l->notes,
                $l->engine_code,
            ]))
            ->implode("\n");
    }
}
