<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import des cotes de consommation officielles.
 *
 * Le fichier d'exemple est un extrait reel de Ressources naturelles Canada, y
 * compris ses pieges : une ligne de notes en fin de fichier, des colonnes a
 * « n/a », et un vehicule sans cote mixte. Une charge utile ecrite a la main
 * aurait ete trop propre pour reveler quoi que ce soit — la lecon du contrat
 * d'API, ou Laravel serialisait les decimaux en chaines.
 *
 * Ces tests tiennent surtout deux promesses :
 *   - la commande se relance sans dupliquer, puisqu'elle est faite pour
 *     tourner sur la base en ligne a chaque republication canadienne ;
 *   - le rattachement au catalogue ne se trompe pas de modele, « Camry Hybrid
 *     LE » devant rejoindre la Camry et non un modele au nom plus court.
 */
class ImportConsommationsTest extends TestCase
{
    use RefreshDatabase;

    private function extrait(): string
    {
        return base_path('tests/Fixtures/nrcan-extrait.csv');
    }

    private function importer(array $options = []): void
    {
        $this->artisan('catalog:import-consommations', array_merge(
            ['--fichier' => [$this->extrait()]],
            $options,
        ))->assertSuccessful();
    }

    public function test_les_cotes_sont_lues_avec_leurs_valeurs(): void
    {
        $this->importer();

        $camry = Motorisation::where('model_raw', 'Camry')->where('engine_l', 2.5)->first();

        $this->assertNotNull($camry);
        $this->assertSame(2013, $camry->model_year);
        $this->assertSame('Toyota', $camry->make_raw);
        $this->assertEquals(9.5, (float) $camry->consumption_city);
        $this->assertEquals(6.6, (float) $camry->consumption_highway);
        $this->assertEquals(8.2, (float) $camry->consumption_combined);
        $this->assertSame(4, $camry->cylinders);
        $this->assertSame('AS6', $camry->transmission_code);
        $this->assertSame('X', $camry->fuel_code);
        $this->assertSame(190, $camry->co2_g_km);
        $this->assertSame('nrcan', $camry->source);
        $this->assertSame('5-cycle', $camry->cycle);
    }

    public function test_la_meme_voiture_a_deux_cotes_selon_sa_motorisation(): void
    {
        $this->importer();

        $consos = Motorisation::where('model_raw', 'Camry')
            ->orderBy('engine_l')
            ->get()
            ->map(fn (Motorisation $m) => [(float) $m->engine_l, (float) $m->consumption_combined])
            ->all();

        // C'est tout l'interet de la table : la finition ne dit rien, le
        // moteur dit tout.
        $this->assertSame([[2.5, 8.2], [3.5, 9.4]], $consos);
    }

    public function test_la_ligne_de_notes_et_les_cotes_absentes_sont_ecartees(): void
    {
        $this->importer();

        // 7 lignes de donnees dans l'extrait, dont un Pajero sans cote mixte.
        $this->assertSame(6, Motorisation::count());
        $this->assertSame(0, Motorisation::where('model_raw', 'Pajero')->count());
        $this->assertSame(0, Motorisation::where('make_raw', 'like', 'Notes%')->count());
    }

    public function test_relancer_l_import_ne_duplique_rien(): void
    {
        $this->importer();
        $premier = Motorisation::count();

        $this->importer();

        $this->assertSame($premier, Motorisation::count());
    }

    public function test_une_cote_corrigee_a_la_source_est_mise_a_jour(): void
    {
        $this->importer();

        Motorisation::where('model_raw', 'Camry')->where('engine_l', 2.5)
            ->update(['consumption_combined' => 99.9]);

        $this->importer();

        $this->assertEqualsWithDelta(
            8.2,
            (float) Motorisation::where('model_raw', 'Camry')->where('engine_l', 2.5)->value('consumption_combined'),
            0.01,
        );
    }

    public function test_le_rattachement_choisit_le_modele_le_plus_precis(): void
    {
        $toyota = Brand::create(['name' => 'Toyota']);
        $camry  = VehicleModel::create(['brand_id' => $toyota->id, 'name' => 'Camry']);
        $rav4   = VehicleModel::create(['brand_id' => $toyota->id, 'name' => 'RAV4']);

        $this->importer();

        // « Camry Hybrid LE » et « RAV4 AWD » portent leur variante dans le nom.
        $this->assertSame(
            $camry->id,
            Motorisation::where('model_raw', 'Camry Hybrid LE')->value('vehicle_model_id'),
        );
        $this->assertSame(
            $rav4->id,
            Motorisation::where('model_raw', 'RAV4 AWD')->value('vehicle_model_id'),
        );
        $this->assertSame(
            $toyota->id,
            Motorisation::where('model_raw', 'Corolla')->value('brand_id'),
            'La marque se reconnait meme quand le modele est inconnu du catalogue.',
        );
    }

    public function test_la_cote_rejoint_la_generation_produite_cette_annee_la(): void
    {
        $toyota = Brand::create(['name' => 'Toyota']);
        $ancienne = VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Camry', 'generation' => 'XV40',
            'production_start' => 2006, 'production_end' => 2011,
        ]);
        $actuelle = VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Camry', 'generation' => 'XV50',
            'production_start' => 2012, 'production_end' => 2017,
        ]);

        $this->importer();

        // Sans le millesime, les trente millesimes canadiens se seraient tous
        // rattaches a la meme generation, et les autres auraient paru
        // depourvues de donnees.
        $this->assertSame(
            $actuelle->id,
            Motorisation::where('model_raw', 'Camry')->where('engine_l', 2.5)->value('vehicle_model_id'),
        );
        $this->assertSame(
            0,
            Motorisation::where('vehicle_model_id', $ancienne->id)->count(),
            'Aucune cote de 2013 ne doit atterrir sur la generation 2006-2011.',
        );
    }

    public function test_une_cote_hors_des_generations_connues_reste_non_rattachee(): void
    {
        $toyota = Brand::create(['name' => 'Toyota']);
        VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Camry', 'generation' => 'XV40',
            'production_start' => 2006, 'production_end' => 2011,
        ]);
        VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Camry', 'generation' => 'XV70',
            'production_start' => 2018, 'production_end' => 2024,
        ]);

        $this->importer();

        // 2013 ne tombe dans aucune des deux : mieux vaut ne rien affirmer que
        // de coller la cote a la generation la plus proche.
        $this->assertNull(
            Motorisation::where('model_raw', 'Camry')->where('engine_l', 2.5)->value('vehicle_model_id'),
        );
    }

    public function test_un_modele_absent_du_catalogue_n_empeche_pas_l_import(): void
    {
        Brand::create(['name' => 'Toyota']);

        $this->importer();

        $corolla = Motorisation::where('model_raw', 'Corolla')->first();
        $this->assertNotNull($corolla);
        $this->assertNull($corolla->vehicle_model_id, 'Aucun modele Corolla au catalogue.');

        $civic = Motorisation::where('model_raw', 'Civic')->first();
        $this->assertNull($civic->brand_id, 'Honda n\'est pas au catalogue.');
    }

    public function test_un_fichier_en_iso_8859_1_est_converti(): void
    {
        // Les fichiers annuels canadiens sont publies en ISO-8859-1 : l'accent
        // de « A5 Coupé » y tient sur un octet, que MySQL en utf8mb4 rejette.
        // L'import entier s'arretait dessus, a la 22e ligne du fichier 2025.
        $this->artisan('catalog:import-consommations', [
            '--fichier' => [base_path('tests/Fixtures/nrcan-latin1.csv')],
        ])->assertSuccessful();

        $this->assertSame(
            'A5 Coupé 45 TFSI quattro',
            Motorisation::where('make_raw', 'Audi')->value('model_raw'),
        );
    }

    public function test_le_mode_analyse_n_ecrit_rien(): void
    {
        $this->importer(['--dry-run' => true]);

        $this->assertSame(0, Motorisation::count());
    }

    public function test_un_fichier_illisible_ne_fait_pas_echouer_la_commande(): void
    {
        $this->artisan('catalog:import-consommations', ['--fichier' => [base_path('tests/Fixtures/absent.csv')]])
            ->expectsOutputToContain('Aucune source de donnees exploitable.')
            ->assertFailed();
    }

    public function test_le_libelle_d_une_motorisation_se_lit_sans_jargon(): void
    {
        $this->importer();

        $camry = Motorisation::where('model_raw', 'Camry')->where('engine_l', 2.5)->first();

        $this->assertSame('2,5 l 4 cyl. boîte auto. 6', $camry->libelle);
        $this->assertSame('Essence ordinaire', Motorisation::libelleCarburant($camry->fuel_code));
    }
}
