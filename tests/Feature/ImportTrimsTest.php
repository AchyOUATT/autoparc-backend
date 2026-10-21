<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Trim;
use App\Models\TrimApplication;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportTrimsTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $brand = Brand::create(['name' => 'Marque fictive', 'slug' => 'marque-fictive']);
        VehicleModel::create([
            'brand_id' => $brand->id,
            'name' => 'Modèle de test',
            'slug' => 'modele-test',
            'generation' => 'T1',
            'production_start' => 2010,
            'production_end' => 2020,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** Données explicitement fictives : elles ne constituent pas un lot automobile. */
    private function document(): array
    {
        return ['version' => 1, 'entries' => [[
            'brand' => 'marque-fictive',
            'model_slug' => 'modele-test',
            'market' => 'US',
            'model_year_from' => 2015,
            'model_year_to' => 2016,
            'trims' => [['name' => 'Finition fictive', 'code' => 'TEST', 'description' => 'Description de test']],
            'sources' => [['url' => 'https://constructeur.example.test/brochure', 'title' => 'Document fictif de test', 'consulted_on' => today()->toDateString()]],
            'verified_on' => today()->toDateString(),
        ]]];
    }

    private function file(array $document): string
    {
        $file = tempnam(sys_get_temp_dir(), 'trim-test-');
        file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));
        $this->files[] = $file;

        return $file;
    }

    private function import(array $document, array $options = [])
    {
        return $this->artisan('catalog:import-trims', ['file' => $this->file($document), ...$options]);
    }

    public function test_le_lot_rattache_finition_marche_annees_et_sources_sans_inventer_de_moteur(): void
    {
        $document = $this->document();
        $this->import($document)->assertSuccessful();

        $trim = Trim::sole();
        $application = TrimApplication::sole();
        $this->assertSame(VehicleModel::sole()->id, $trim->vehicle_model_id);
        $this->assertNull($trim->default_engine_type_id);
        $this->assertNull($trim->default_drivetrain_id);
        $this->assertSame($trim->id, $application->trim_id);
        $this->assertSame('US', $application->market);
        $this->assertEquals(2015, $application->model_year_from);
        $this->assertSame($document['entries'][0]['sources'], $application->sources);
        $this->assertSame(today()->toDateString(), $application->verified_at->toDateString());
    }

    public function test_la_simulation_valide_sans_creer_de_finition_ni_application(): void
    {
        $this->import($this->document(), ['--dry-run' => true])
            ->expectsOutputToContain('Simulation : aucune écriture.')
            ->expectsOutputToContain('Finitions : 1 ajout(s)')
            ->assertSuccessful();

        $this->assertDatabaseCount('trims', 0);
        $this->assertDatabaseCount('trim_applications', 0);
    }

    public function test_le_rejeu_preserve_ids_et_horodatages(): void
    {
        $this->travelTo(now()->startOfSecond());
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        $trim = Trim::sole();
        $application = TrimApplication::sole();
        $this->travel(2)->minutes();

        $this->import($document)
            ->expectsOutputToContain('Finitions : 0 ajout(s), 0 mise(s) à jour, 1 inchangée(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('trims', 1);
        $this->assertDatabaseCount('trim_applications', 1);
        $this->assertSame($trim->id, Trim::sole()->id);
        $this->assertTrue($trim->updated_at->equalTo(Trim::sole()->updated_at));
        $this->assertSame($application->id, TrimApplication::sole()->id);
        $this->assertTrue($application->updated_at->equalTo(TrimApplication::sole()->updated_at));
    }

    public function test_un_conflit_refuse_tout_le_lot_et_preserve_la_finition_existante(): void
    {
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        $trim = Trim::sole();
        $document['entries'][0]['trims'][] = ['name' => 'Autre finition fictive'];
        $document['entries'][0]['trims'][0]['description'] = 'Correction à examiner';

        $this->import($document)->expectsOutputToContain('--update-existing')->assertFailed();

        $this->assertDatabaseCount('trims', 1);
        $this->assertDatabaseCount('trim_applications', 1);
        $this->assertSame('Description de test', $trim->fresh()->description);
    }

    public function test_la_correction_explicitement_autorisee_preserve_l_identifiant(): void
    {
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        $trim = Trim::sole();
        $document['entries'][0]['trims'][0]['description'] = 'Correction autorisée';

        $this->import($document, ['--update-existing' => true])->assertSuccessful();

        $this->assertDatabaseCount('trims', 1);
        $this->assertSame($trim->id, Trim::sole()->id);
        $this->assertSame('Correction autorisée', $trim->fresh()->description);
    }

    public function test_un_champ_omis_nefface_pas_la_description_existante(): void
    {
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        unset($document['entries'][0]['trims'][0]['description'], $document['entries'][0]['trims'][0]['code']);
        $document['entries'][0]['market'] = 'CA';

        $this->import($document)->assertSuccessful();

        $this->assertSame('Description de test', Trim::sole()->description);
        $this->assertSame('TEST', Trim::sole()->code);
        $this->assertDatabaseCount('trim_applications', 2);
    }

    public function test_un_ancien_lot_ne_remplace_pas_une_verification_plus_recente(): void
    {
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        $application = TrimApplication::sole();
        $old = today()->subDay()->toDateString();
        $document['entries'][0]['verified_on'] = $old;
        $document['entries'][0]['sources'][0]['consulted_on'] = $old;
        $document['entries'][0]['sources'][0]['title'] = 'Ancien document fictif';

        $this->import($document, ['--update-existing' => true])
            ->expectsOutputToContain('Une vérification plus récente existe déjà')
            ->assertFailed();

        $this->assertSame($application->sources, $application->fresh()->sources);
        $this->assertTrue($application->verified_at->equalTo($application->fresh()->verified_at));
    }

    public function test_un_millesime_documente_peut_depasser_lannee_de_production_par_derogation_explicite(): void
    {
        $document = $this->document();
        $document['entries'][0]['model_year_from'] = 2021;
        $document['entries'][0]['model_year_to'] = 2021;

        $this->import($document)->expectsOutputToContain('--allow-production-year-mismatch')->assertFailed();
        $this->assertDatabaseCount('trims', 0);

        $this->import($document, ['--allow-production-year-mismatch' => true])
            ->expectsOutputToContain('dérogation documentaire explicite')
            ->assertSuccessful();

        $this->assertSame(2021, TrimApplication::sole()->model_year_from);
        $this->assertSame(2020, VehicleModel::sole()->production_end);
    }

    public function test_une_entree_invalide_en_fin_de_lot_ne_laisse_aucune_ecriture_partielle(): void
    {
        $document = $this->document();
        $document['entries'][] = [...$document['entries'][0], 'model_slug' => 'absent'];

        $this->import($document)->assertFailed();

        $this->assertDatabaseCount('trims', 0);
        $this->assertDatabaseCount('trim_applications', 0);
    }

    public static function invalidFields(): array
    {
        return [
            'année hors génération' => ['model_year_from', 2009],
            'période inversée' => ['model_year_to', 2014],
            'année texte' => ['model_year_from', '2015'],
            'marché non normalisé' => ['market', 'us'],
            'vérification future' => ['verified_on', '2999-01-01'],
            'aucune source' => ['sources', []],
            'champ inconnu' => ['invented', true],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_les_lots_non_documentes_ou_incoherents_sont_refuses(string $field, mixed $value): void
    {
        $document = $this->document();
        $document['entries'][0][$field] = $value;

        $this->import($document)->assertFailed();

        $this->assertDatabaseCount('trims', 0);
    }

    public function test_un_brouillon_de_recherche_ne_peut_pas_etre_importe_comme_lot_verifie(): void
    {
        $this->import(['status' => 'blocked_by_network_policy', 'verified_entries' => []])->assertFailed();
        $this->assertDatabaseCount('trims', 0);
    }

    public function test_les_identifiants_dans_une_url_source_sont_refuses(): void
    {
        $document = $this->document();
        $document['entries'][0]['sources'][0]['url'] = 'https://user:password@constructeur.example.test/brochure';

        $this->import($document)->assertFailed();

        $this->assertDatabaseCount('trim_applications', 0);
    }

    public function test_le_modele_est_resolu_dans_sa_marque_et_non_par_slug_seul(): void
    {
        $otherBrand = Brand::create(['name' => 'Autre marque fictive', 'slug' => 'autre-marque']);
        $otherModel = VehicleModel::create([
            'brand_id' => $otherBrand->id,
            'name' => 'Autre modèle fictif',
            'slug' => 'modele-test',
            'production_start' => 2010,
            'production_end' => 2020,
        ]);
        $document = $this->document();
        $document['entries'][0]['brand'] = 'autre-marque';

        $this->import($document)->assertSuccessful();

        $this->assertSame($otherModel->id, Trim::sole()->vehicle_model_id);
    }

    public function test_deux_definitions_contradictoires_du_meme_lot_sont_refusees_meme_avec_autorisation(): void
    {
        $document = $this->document();
        $document['entries'][] = $document['entries'][0];
        $document['entries'][1]['market'] = 'CA';
        $document['entries'][1]['trims'][0]['code'] = 'AUTRE';

        $this->import($document, ['--update-existing' => true])->assertFailed();

        $this->assertDatabaseCount('trims', 0);
        $this->assertDatabaseCount('trim_applications', 0);
    }

    public function test_la_simulation_dune_correction_ne_modifie_pas_les_donnees_existantes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        $trim = Trim::sole();
        $application = TrimApplication::sole();
        $this->travel(2)->minutes();
        $document['entries'][0]['trims'][0]['description'] = 'Correction simulée';
        $document['entries'][0]['sources'][0]['title'] = 'Provenance simulée';

        $this->import($document, ['--dry-run' => true, '--update-existing' => true])->assertSuccessful();

        $this->assertSame('Description de test', $trim->fresh()->description);
        $this->assertTrue($trim->updated_at->equalTo($trim->fresh()->updated_at));
        $this->assertSame('Document fictif de test', $application->fresh()->sources[0]['title']);
        $this->assertTrue($application->updated_at->equalTo($application->fresh()->updated_at));
    }

    public function test_une_correction_de_provenance_remonte_dans_le_delta_des_finitions(): void
    {
        $this->travelTo(now()->startOfSecond());
        $document = $this->document();
        $this->import($document)->assertSuccessful();
        $trim = Trim::sole();
        $cursor = now()->toIso8601String();
        $this->travel(2)->minutes();
        $document['entries'][0]['sources'][0]['title'] = 'Titre corrigé du document fictif';

        $this->import($document)->assertSuccessful();

        $this->assertTrue($trim->fresh()->updated_at->greaterThan($trim->updated_at));
        $this->getJson('/api/catalog/sync?'.http_build_query(['since' => $cursor]))
            ->assertOk()
            ->assertJsonPath('trims.0.id', $trim->id);
        $this->assertSame('Titre corrigé du document fictif', TrimApplication::sole()->sources[0]['title']);
    }
}
