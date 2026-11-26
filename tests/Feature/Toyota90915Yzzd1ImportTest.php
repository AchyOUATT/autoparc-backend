<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\OemNumber;
use App\Models\OemNumberFitment;
use App\Models\OemNumberRelation;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\VehicleModel;
use App\Support\Toyota90915Yzzd1Import;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Database\Seeders\ManufacturersSeeder;
use Database\Seeders\PartCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Toyota90915Yzzd1ImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{brand: string, slug: string, name: string, generation: ?string}> */
    private const MODELES = [
        ['brand' => 'toyota', 'slug' => 'camry-xv10', 'name' => 'Camry', 'generation' => 'XV10'],
        ['brand' => 'toyota', 'slug' => 'camry-xv20', 'name' => 'Camry', 'generation' => 'XV20'],
        ['brand' => 'toyota', 'slug' => 'tacoma-i', 'name' => 'Tacoma', 'generation' => 'I'],
        ['brand' => 'toyota', 'slug' => 'tacoma-ii', 'name' => 'Tacoma', 'generation' => 'II'],
        ['brand' => 'toyota', 'slug' => 'tacoma-iii', 'name' => 'Tacoma', 'generation' => 'III'],
        ['brand' => 'toyota', 'slug' => 'highlander', 'name' => 'Highlander', 'generation' => null],
        ['brand' => 'lexus', 'slug' => 'rx-xu30', 'name' => 'RX', 'generation' => 'XU30'],
        // Modèles présents en production mais volontairement sans ligne.
        ['brand' => 'toyota', 'slug' => 'tacoma-iv', 'name' => 'Tacoma', 'generation' => 'IV'],
        ['brand' => 'toyota', 'slug' => 'tacoma', 'name' => 'Tacoma', 'generation' => null],
        ['brand' => 'toyota', 'slug' => 'sienna', 'name' => 'Sienna', 'generation' => null],
        ['brand' => 'toyota', 'slug' => 'avalon', 'name' => 'Avalon', 'generation' => null],
    ];

    private function catalogue(): void
    {
        foreach (['toyota' => 'Toyota', 'lexus' => 'Lexus'] as $slug => $nom) {
            Brand::firstOrCreate(['slug' => $slug], ['name' => $nom, 'is_active' => true]);
        }
        foreach (self::MODELES as $modele) {
            VehicleModel::firstOrCreate(
                ['brand_id' => Brand::where('slug', $modele['brand'])->value('id'), 'slug' => $modele['slug']],
                ['name' => $modele['name'], 'generation' => $modele['generation'], 'is_active' => true],
            );
        }

        $rx = VehicleModel::where('slug', 'rx-xu30')->firstOrFail();
        foreach ([['330', 'RX 330', 1], ['400h', 'RX 400h', 2], ['350', 'RX 350', 3]] as [$code, $nom, $rang]) {
            Trim::firstOrCreate(
                ['vehicle_model_id' => $rx->id, 'code' => $code],
                ['name' => $nom, 'rank' => $rang],
            );
        }
    }

    private function filtre(string $sku = Toyota90915Yzzd1Import::SKU): Part
    {
        $categorie = PartCategory::firstOrCreate(['slug' => 'filtre-huile'], ['name' => 'Filtre à huile']);

        return Part::create([
            'sku' => $sku,
            'name' => 'Filtre a huile visse '.$sku,
            'part_category_id' => $categorie->id,
            'manufacturer_reference' => $sku,
            'condition' => 'new',
            'selling_price' => 3000,
            'currency' => 'XOF',
            'stock_quantity' => 15,
            'is_active' => true,
            'is_available' => true,
        ]);
    }

    public function test_sans_article_l_import_est_inerte(): void
    {
        $this->catalogue();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertNull($rapport['part_id']);
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(0, Part::where('sku', Toyota90915Yzzd1Import::SKU)->count());
    }

    public function test_il_pose_les_applications_sourcees_sans_toucher_au_commerce(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame($piece->id, $rapport['part_id']);
        $this->assertSame(11, $rapport['fitments_inserted']);
        $this->assertSame([], $rapport['fitments_skipped_missing_model']);
        $this->assertSame([], $rapport['fitments_skipped_missing_trim']);

        $piece->refresh();
        $this->assertEquals(3000, $piece->selling_price);
        $this->assertSame(15, $piece->stock_quantity);
        $this->assertTrue((bool) $piece->is_active);
        $this->assertSame('Filtre a huile visse 90915-YZZD1', $piece->name);

        $lignes = $piece->fitments()->with(['vehicleModel', 'trim'])->get()
            ->map(fn ($f) => $f->vehicleModel->slug.'|'.($f->trim?->code ?? '-').'|'.$f->engine_code.'|'.$f->year_from.'|'.$f->year_to)
            ->sort()->values()->all();
        $this->assertSame([
            'camry-xv10|-|1MZ-FE|1993|1996',
            'camry-xv10|-|3VZ-FE|1991|1993',
            'camry-xv20|-|1MZ-FE|1996|2001',
            'highlander|-|3MZ-FE|2008|2010',
            'rx-xu30|330|3MZ-FE|2003|2006',
            'rx-xu30|400h|3MZ-FE|2005|2008',
            'tacoma-iii|-|2TR-FE|2016|2023',
            'tacoma-ii|-|2TR-FE|2005|2015',
            'tacoma-i|-|2RZ-FE|1995|2004',
            'tacoma-i|-|3RZ-FE|1995|2004',
            'tacoma-i|-|5VZ-FE|1995|2004',
        ], $lignes);

        foreach ($piece->fitments as $ligne) {
            $this->assertSame('declared', $ligne->source->value ?? $ligne->source);
            $this->assertNotNull($ligne->engine_code);
            // Châssis documenté par l'EPC Toyota pour chaque ligne ; le marché,
            // sans colonne dédiée, est écrit dans la note.
            $this->assertNotEmpty($ligne->chassis_code);
            $this->assertStringContainsString('marché', (string) $ligne->notes);
            $this->assertStringContainsString('Période EPC Toyota', (string) $ligne->notes);
            $this->assertStringContainsString('https://', (string) $ligne->notes);
            $this->assertStringNotContainsString('@', (string) $ligne->notes);
        }
    }

    public function test_les_lignes_officielles_citent_le_catalogue_toyota(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        Toyota90915Yzzd1Import::run();

        foreach (['camry-xv20', 'tacoma-i', 'tacoma-ii', 'tacoma-iii', 'highlander'] as $slug) {
            $modele = VehicleModel::where('slug', $slug)->firstOrFail();
            foreach ($piece->fitments()->where('vehicle_model_id', $modele->id)->get() as $ligne) {
                $this->assertStringContainsString('06_OilFilters2025_hi.pdf', (string) $ligne->notes, $slug);
            }
        }
    }

    public function test_les_modeles_ambigus_ne_recoivent_aucune_ligne(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        Toyota90915Yzzd1Import::run();

        $exclus = VehicleModel::whereIn('slug', ['tacoma-iv', 'tacoma', 'sienna', 'avalon'])->pluck('id');
        $this->assertSame(0, $piece->fitments()->whereIn('vehicle_model_id', $exclus)->count());
    }

    public function test_les_anciens_numeros_officiels_sont_rattaches_comme_perimes(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(5, $rapport['oem_superseded_linked']);
        $actuel = OemNumber::where('normalized_number', '90915YZZD1')->firstOrFail();
        $this->assertFalse((bool) $actuel->is_superseded);
        foreach (Toyota90915Yzzd1Import::NUMEROS_REMPLACES as $ancien) {
            $numero = OemNumber::where('normalized_number', OemNumber::normalize($ancien))->firstOrFail();
            $this->assertTrue((bool) $numero->is_superseded, $ancien);
            $this->assertSame($actuel->id, (int) $numero->superseded_by_id, $ancien);
            $this->assertTrue($piece->oemNumbers()->where('oem_numbers.id', $numero->id)->exists(), $ancien);
            $this->assertFalse((bool) $piece->oemNumbers()->where('oem_numbers.id', $numero->id)->first()->pivot->is_primary);
        }
        $this->assertSame(['90915-YZZB1', '90915-YZZB9', '90915-YZZG2'], Toyota90915Yzzd1Import::NUMEROS_REMPLACES);
    }

    public function test_le_niveau_de_preuve_distingue_constructeur_et_catalogues_de_distribution(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        Toyota90915Yzzd1Import::run();

        $actuel = OemNumber::where('normalized_number', '90915YZZD1')->firstOrFail();
        // Le numéro actuel ne remplace rien de son côté : aucun lien sortant.
        $this->assertSame(0, OemNumberRelation::where('from_oem_number_id', $actuel->id)->count());
        // Une seule table de preuves : plus aucune colonne supersession_* sur oem_numbers.
        $this->assertFalse(Schema::hasColumn('oem_numbers', 'supersession_evidence'));
        $this->assertFalse(Schema::hasColumn('oem_numbers', 'supersession_sources'));

        foreach (['90915-YZZB1', '90915-YZZB9', '90915-YZZG2'] as $ancien) {
            $numero = OemNumber::where('normalized_number', OemNumber::normalize($ancien))->firstOrFail();
            $lien = OemNumberRelation::where('from_oem_number_id', $numero->id)->sole();
            $this->assertSame($actuel->id, (int) $lien->to_oem_number_id, $ancien);
            $this->assertSame(OemNumberRelation::KIND_MANUFACTURER_SUPERSESSION, $lien->kind, $ancien);
            $this->assertSame('manufacturer_official', $lien->evidence_level, $ancien);
            $this->assertSame(Toyota90915Yzzd1Import::SOURCES_REMPLACEMENT_CONSTRUCTEUR, $lien->sources, $ancien);
            $this->assertContains('https://autoparts.toyota.com/products/product/filter-oil-90915yzzd1', $lien->sources);
        }
        $this->assertSame(['90915-20001', '90915-20003'], Toyota90915Yzzd1Import::NUMEROS_REMPLACES_DISTRIBUTION);
        foreach (Toyota90915Yzzd1Import::NUMEROS_REMPLACES_DISTRIBUTION as $ancien) {
            $numero = OemNumber::where('normalized_number', OemNumber::normalize($ancien))->firstOrFail();
            $this->assertTrue((bool) $numero->is_superseded, $ancien);
            $this->assertSame($actuel->id, (int) $numero->superseded_by_id, $ancien);
            $lien = OemNumberRelation::where('from_oem_number_id', $numero->id)->sole();
            $this->assertSame(OemNumberRelation::KIND_DISTRIBUTOR_REPLACEMENT, $lien->kind, $ancien);
            $this->assertSame('dealer_catalog', $lien->evidence_level, $ancien);
            $this->assertCount(5, $lien->sources);
            foreach (['toyotapartsdeal.com', 'toyotapartsnow.com', 'frontiertoyota.com', 'nucar.com'] as $site) {
                $this->assertNotEmpty(array_filter($lien->sources, fn (string $url) => str_contains($url, $site)), $site);
            }
            $this->assertTrue($piece->oemNumbers()->where('oem_numbers.id', $numero->id)->exists(), $ancien);
        }
        $this->assertSame(5, OemNumberRelation::count());
    }

    public function test_un_ancien_numero_ne_transfere_aucune_compatibilite(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        Toyota90915Yzzd1Import::run();

        // Les anciens numéros n'ont aucune ligne propre : le rattachement ne
        // crée ni oem_number_fitments ni part_fitments supplémentaires.
        $anciens = OemNumber::whereIn('normalized_number', array_map(
            fn (string $n) => OemNumber::normalize($n),
            [...Toyota90915Yzzd1Import::NUMEROS_REMPLACES, ...Toyota90915Yzzd1Import::NUMEROS_REMPLACES_DISTRIBUTION],
        ))->pluck('id');
        $this->assertCount(5, $anciens);
        $this->assertSame(0, OemNumberFitment::whereIn('oem_number_id', $anciens)->count());
        $this->assertSame(count(Toyota90915Yzzd1Import::applications()), $piece->fitments()->count());
    }

    public function test_l_api_oem_expose_le_niveau_de_preuve_sans_inventer_de_modeles(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        // Comme en production : le numéro principal est déjà rattaché à l'article.
        $principal = OemNumber::create(['number' => '90915-YZZD1', 'brand_id' => null, 'label' => 'x', 'is_superseded' => false]);
        $piece->oemNumbers()->attach($principal->id, ['is_primary' => true]);
        Toyota90915Yzzd1Import::run();

        $croisees = $this->getJson('/api/oem/90915-20003/cross-references')->assertOk()->json('data');
        $parNumero = collect($croisees)->keyBy('number');
        $this->assertArrayHasKey('90915-YZZD1', $parNumero->all());
        $this->assertSame('distributor_replacement', $parNumero['90915-20001']['replaced_by'][0]['kind']);
        $this->assertSame('dealer_catalog', $parNumero['90915-20001']['replaced_by'][0]['evidence_level']);
        $this->assertSame('90915-YZZD1', $parNumero['90915-20001']['replaced_by'][0]['number']);
        $this->assertSame('manufacturer_supersession', $parNumero['90915-YZZB1']['replaced_by'][0]['kind']);
        $this->assertSame('manufacturer_official', $parNumero['90915-YZZB1']['replaced_by'][0]['evidence_level']);
        $this->assertSame([], $parNumero['90915-YZZD1']['replaced_by']);
        $this->assertArrayNotHasKey('supersession_evidence', $parNumero['90915-YZZB1']);

        // Les compatibilités du filtre sont des part_fitments ; aucune ligne
        // OEM n'est posée, donc la recherche par ancien numéro ne renvoie
        // aucun modèle déduit du remplacement.
        $this->assertSame([], $this->getJson('/api/oem/90915-20003/models')->assertOk()->json('data'));
    }

    public function test_rejouer_le_seeder_des_filtres_n_efface_pas_les_compatibilites(): void
    {
        $this->seed([
            PartCategoriesSeeder::class,
            ManufacturersSeeder::class,
        ]);
        $this->catalogue();
        $this->filtre();
        Toyota90915Yzzd1Import::run();
        $piece = Part::where('sku', Toyota90915Yzzd1Import::SKU)->firstOrFail();
        $avant = $piece->fitments()->count();
        $this->assertSame(count(Toyota90915Yzzd1Import::applications()), $avant);

        // Ce seeder fait `fitments()->delete()` sur ses références : la
        // YZZD1 y est marquée `compatibilites_a_verifier`, il doit la laisser.
        $this->seed(FiltresHuileBoutiqueSeeder::class);

        $this->assertSame($avant, $piece->fitments()->count());
    }

    public function test_un_lien_de_remplacement_deja_qualifie_n_est_pas_reecrit(): void
    {
        $this->catalogue();
        $this->filtre();
        $actuel = OemNumber::create(['number' => '90915-YZZD1', 'brand_id' => null, 'label' => 'x', 'is_superseded' => false]);
        $ancien = OemNumber::create([
            'number' => '90915-20003', 'brand_id' => null, 'label' => 'x',
            'is_superseded' => true, 'superseded_by_id' => $actuel->id,
        ]);
        OemNumberRelation::create([
            'from_oem_number_id' => $ancien->id, 'to_oem_number_id' => $actuel->id,
            'kind' => OemNumberRelation::KIND_MANUFACTURER_SUPERSESSION, 'evidence_level' => 'manufacturer_epc',
            'sources' => ['https://exemple.test'],
        ]);

        Toyota90915Yzzd1Import::run();

        $lien = OemNumberRelation::where('from_oem_number_id', $ancien->id)->sole();
        $this->assertSame(OemNumberRelation::KIND_MANUFACTURER_SUPERSESSION, $lien->kind);
        $this->assertSame('manufacturer_epc', $lien->evidence_level);
        $this->assertSame(['https://exemple.test'], $lien->sources);
    }

    public function test_un_numero_deja_remplace_par_une_autre_reference_n_est_pas_modifie(): void
    {
        $this->catalogue();
        $this->filtre();
        $autre = OemNumber::create(['number' => '90915-YZZG9', 'brand_id' => null, 'label' => 'autre', 'is_superseded' => false]);
        $ancien = OemNumber::create([
            'number' => '90915-YZZB1', 'brand_id' => null, 'label' => 'x',
            'is_superseded' => true, 'superseded_by_id' => $autre->id,
        ]);

        Toyota90915Yzzd1Import::run();

        $this->assertSame($autre->id, (int) $ancien->fresh()->superseded_by_id);
        $this->assertSame(0, OemNumberRelation::where('from_oem_number_id', $ancien->id)->count());
    }

    public function test_rejouer_est_idempotent_et_respecte_les_lignes_des_autres_articles(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        $autre = $this->filtre('90915-10001');
        $xv10 = VehicleModel::where('slug', 'camry-xv10')->firstOrFail();
        $autre->fitments()->create([
            'vehicle_model_id' => $xv10->id,
            'engine_code' => '5S-FE',
            'year_from' => 1992,
            'year_to' => 1996,
            'source' => 'declared',
        ]);

        $premier = Toyota90915Yzzd1Import::run();
        $oem = OemNumber::count();
        $liens = OemNumberRelation::orderBy('id')->get(['id', 'from_oem_number_id', 'to_oem_number_id', 'kind', 'evidence_level', 'sources'])->toArray();
        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(5, $premier['oem_relations_created']);
        $this->assertSame(0, $rapport['oem_relations_created']);
        $this->assertSame($liens, OemNumberRelation::orderBy('id')->get(['id', 'from_oem_number_id', 'to_oem_number_id', 'kind', 'evidence_level', 'sources'])->toArray());
        $this->assertSame(0, $rapport['fitments_inserted']);
        $this->assertSame(11, $rapport['fitments_already_present']);
        $this->assertSame(0, $rapport['oem_superseded_linked']);
        $this->assertSame(11, $piece->fitments()->count());
        $this->assertSame($oem, OemNumber::count());
        $this->assertSame(1, $autre->fitments()->count());
        $this->assertSame('5S-FE', $autre->fitments()->first()->engine_code);
    }

    public function test_une_ligne_deja_saisie_a_la_main_n_est_pas_reecrite(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        $hl = VehicleModel::where('slug', 'highlander')->firstOrFail();
        $piece->fitments()->create([
            'vehicle_model_id' => $hl->id,
            'engine_code' => '3MZ-FE',
            'year_from' => 2008,
            'year_to' => 2010,
            'notes' => 'Saisie par le commerçant',
            'source' => 'declared',
        ]);

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(10, $rapport['fitments_inserted']);
        $this->assertSame(1, $rapport['fitments_already_present']);
        $this->assertSame(
            'Saisie par le commerçant',
            $piece->fitments()->where('vehicle_model_id', $hl->id)->firstOrFail()->notes,
        );
    }

    public function test_un_modele_ou_une_finition_absents_sont_ignores_sans_erreur(): void
    {
        $this->catalogue();
        VehicleModel::where('slug', 'tacoma-iii')->delete();
        Trim::where('code', '400h')->delete();
        $piece = $this->filtre();

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(9, $rapport['fitments_inserted']);
        $this->assertSame(['tacoma-iii'], $rapport['fitments_skipped_missing_model']);
        $this->assertSame(['rx-xu30:400h'], $rapport['fitments_skipped_missing_trim']);
        $this->assertSame(9, $piece->fitments()->count());
    }

    public function test_la_migration_rejoue_l_import(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        $migration = require database_path('migrations/2026_11_26_140000_poser_les_compatibilites_du_filtre_90915_yzzd1.php');
        $migration->up();
        $migration->up();

        $this->assertSame(11, $piece->fitments()->count());
    }

    public function test_migrations_et_seeder_rejoues_deux_fois_sans_doublon(): void
    {
        $this->seed([PartCategoriesSeeder::class, ManufacturersSeeder::class]);
        $this->catalogue();
        $this->filtre();

        $migrations = [
            '2026_11_26_140000_poser_les_compatibilites_du_filtre_90915_yzzd1.php',
            '2026_11_26_200000_ajouter_les_criteres_chassis_marche_et_mois_aux_compatibilites.php',
            '2026_11_26_201000_qualifier_les_remplacements_du_filtre_90915_yzzd1.php',
        ];
        $etat = function (): array {
            $piece = Part::where('sku', Toyota90915Yzzd1Import::SKU)->firstOrFail();

            return [
                'fitments' => $piece->fitments()->orderBy('id')->get(['id', 'vehicle_model_id', 'trim_id', 'engine_code', 'year_from', 'year_to', 'chassis_code', 'evidence_level', 'evidence_sources'])->toArray(),
                'oem' => OemNumber::orderBy('id')->get(['id', 'normalized_number', 'is_superseded', 'superseded_by_id'])->toArray(),
                'liens' => OemNumberRelation::orderBy('id')->get(['from_oem_number_id', 'to_oem_number_id', 'kind', 'evidence_level', 'sources'])->toArray(),
                'pivot' => \DB::table('oem_number_part')->orderBy('oem_number_id')->get(['part_id', 'oem_number_id', 'is_primary'])->map(fn ($r) => (array) $r)->all(),
                'oem_fitments' => OemNumberFitment::count(),
            ];
        };

        foreach ($migrations as $fichier) {
            (require database_path('migrations/'.$fichier))->up();
        }
        $this->seed(FiltresHuileBoutiqueSeeder::class);
        $apres1 = $etat();

        foreach ($migrations as $fichier) {
            (require database_path('migrations/'.$fichier))->up();
        }
        $this->seed(FiltresHuileBoutiqueSeeder::class);
        $apres2 = $etat();

        $this->assertSame($apres1, $apres2);
        $this->assertCount(5, $apres2['liens']);
        $this->assertCount(count(Toyota90915Yzzd1Import::applications()), $apres2['fitments']);
        $this->assertSame(0, $apres2['oem_fitments'], 'Aucune ligne OEM copiée vers ou depuis un ancien numéro.');
    }

    public function test_chaque_ligne_porte_une_preuve_structuree_tiree_de_ses_sources(): void
    {
        $this->catalogue();
        $piece = $this->filtre();

        Toyota90915Yzzd1Import::run();

        $lignes = $piece->fitments()->get();
        $this->assertCount(count(Toyota90915Yzzd1Import::applications()), $lignes);
        foreach ($lignes as $ligne) {
            $this->assertContains($ligne->evidence_level, ['manufacturer_official', 'dealer_catalog'], $ligne->notes);
            $this->assertNotEmpty($ligne->evidence_sources);
            foreach ($ligne->evidence_sources as $url) {
                $this->assertStringContainsString($url, $ligne->notes, 'Une source structurée doit être citée par la note.');
            }
            $this->assertTrue(\App\Support\FitmentEvidence::isDocumented($ligne));
            // Sans document constructeur qui nomme la YZZD1, la chaîne passe
            // par le remplacement distributeur : niveau dealer_catalog.
            $constructeur = str_contains($ligne->notes, 'toyotapartsandservice.com') || str_contains($ligne->notes, 'parts.lexus.com');
            $this->assertSame($constructeur ? 'manufacturer_official' : 'dealer_catalog', $ligne->evidence_level);
        }
        $this->assertSame(3, $lignes->where('evidence_level', 'dealer_catalog')->count());
    }

    public function test_le_rejeu_qualifie_ses_propres_lignes_sans_toucher_aux_autres(): void
    {
        $this->catalogue();
        $piece = $this->filtre();
        Toyota90915Yzzd1Import::run();
        // Comme après la migration 140000 en production : lignes posées avant
        // l'existence des colonnes de preuve.
        \DB::table('part_fitments')->where('part_id', $piece->id)->update(['evidence_level' => null, 'evidence_sources' => null]);
        $propre = $piece->fitments()->orderBy('id')->first();
        $qualifiee = $piece->fitments()->orderBy('id')->skip(1)->first();
        $qualifiee->update(['evidence_level' => 'unverified']);
        $manuelle = $piece->fitments()->orderBy('id')->skip(2)->first();
        $manuelle->update(['notes' => 'Saisie atelier.']);

        $rapport = Toyota90915Yzzd1Import::run();

        $this->assertSame(count(Toyota90915Yzzd1Import::applications()) - 2, $rapport['fitments_evidence_qualified']);
        $this->assertNotNull($propre->fresh()->evidence_level);
        $this->assertSame('unverified', $qualifiee->fresh()->evidence_level, 'Une preuve déjà posée n\'est jamais réécrite.');
        $this->assertNull($manuelle->fresh()->evidence_level, 'Une ligne dont la note a changé n\'est plus celle de l\'import.');
        $this->assertSame(0, Toyota90915Yzzd1Import::run()['fitments_evidence_qualified']);
    }
}
