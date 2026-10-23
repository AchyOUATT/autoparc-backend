<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

    // ── La garde d'age, et ce que le journal doit dire ───────────────
    //
    // Le Dockerfile lance cette commande a chaque demarrage, en arriere-plan.
    // Sans garde, chaque reveil de l'instance gratuite retelechargerait les
    // quatre fichiers canadiens — mesure sur base jetable : 161,8 s et 29 187
    // lignes. Ces tests tiennent la garde ET ce que la commande dit quand la
    // source se derobe, parce qu'un journal de deploiement est le seul organe
    // d'observation dont dispose un plan sans Shell.

    public function test_la_garde_d_age_evite_de_retelecharger(): void
    {
        $this->importer();

        Http::fake();

        $this->artisan('catalog:import-consommations', ['--si-plus-vieux-que' => 180])
            ->expectsOutputToContain('rien a faire')
            ->assertSuccessful();

        // La vraie promesse n'est pas le message, c'est l'absence d'appel :
        // c'est le telechargement qu'on evite, pas l'ecriture.
        Http::assertNothingSent();
    }

    public function test_une_table_vide_passe_la_garde(): void
    {
        // Le premier demarrage d'une base neuve : aucune cote, donc aucun age,
        // donc l'import doit avoir lieu. C'est exactement l'etat de la
        // production aujourd'hui.
        $this->importer(['--si-plus-vieux-que' => 180]);

        $this->assertSame(6, Motorisation::count());
    }

    public function test_des_cotes_trop_vieilles_relancent_l_import(): void
    {
        $this->importer();
        Motorisation::query()->update(['updated_at' => now()->subDays(400)]);

        // Ressources naturelles Canada republie chaque millesime : une table
        // non vide ne doit pas interdire la mise a jour pour autant.
        $this->importer(['--si-plus-vieux-que' => 180]);

        $this->assertTrue(
            Motorisation::query()->max('updated_at') > now()->subMinute(),
            'Un import qui a eu lieu rafraichit la date, donc repousse la garde.',
        );
    }

    public function test_un_age_de_zero_jour_force_l_import(): void
    {
        $this->importer();

        // La soupape : aucune ligne n'est plus recente que « maintenant moins
        // zero jour ». C'est le seul moyen de forcer une reprise depuis le
        // tableau de bord de Render, qui n'ouvre pas de Shell.
        $this->artisan('catalog:import-consommations', [
            '--fichier' => [$this->extrait()],
            '--si-plus-vieux-que' => 0,
        ])
            ->expectsOutputToContain('lignes enregistrees')
            ->assertSuccessful();
    }

    public function test_un_catalogue_injoignable_le_dit_en_clair_et_echoue(): void
    {
        // open.canada.ca peut ne pas repondre du tout : DNS, refus de
        // connexion, 60 s depassees. Sans ce traitement, l'exception remontait
        // en trace de pile au milieu du journal de deploiement.
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: open.canada.ca'));

        $this->artisan('catalog:import-consommations')
            ->expectsOutputToContain('Le catalogue open.canada.ca est injoignable')
            ->expectsOutputToContain('Aucune source de donnees exploitable.')
            ->assertFailed();

        $this->assertSame(0, Motorisation::count());
    }

    public function test_une_source_qui_rend_autre_chose_que_prevu_echoue_en_clair(): void
    {
        // Le cas le plus traitre : le service repond 200, le fichier existe,
        // et ses colonnes ont change de libelle. `analyser()` ecarte alors le
        // fichier entier sur un simple avertissement — la commande rendait 0
        // et le demarrage se lisait comme sain.
        Http::fake([
            'open.canada.ca/*' => Http::response([
                'result' => ['resources' => [
                    ['url' => 'https://exemple.test/my2027-fuel-consumption-ratings.csv'],
                ]],
            ]),
            'exemple.test/*' => Http::response("Annee,Marque,Modele\n2027,Toyota,Corolla\n"),
        ]);

        $this->artisan('catalog:import-consommations')
            ->expectsOutputToContain('En-tete inattendu')
            ->expectsOutputToContain('Aucune cote ecrite')
            ->assertFailed();

        $this->assertSame(0, Motorisation::count());
    }

    // ── Chaque source vieillit pour son compte ─────────────────────────────
    //
    // La garde d'age mesurait le `max(updated_at)` de TOUTE la table, sans
    // regarder d'ou venaient les lignes. Tant qu'une seule source tournait,
    // c'etait sans consequence — et c'est pour cela que personne ne l'a vu.
    //
    // Le defaut ne devient visible qu'au moment ou l'on branche la seconde
    // source, et il est alors MUET : le demarrage lance le Canada, qui ecrit
    // 29 187 lignes, puis l'Europe, qui trouve un maximum vieux de deux
    // secondes et annonce « rien a faire ». La source europeenne ne tournerait
    // jamais, et le journal afficherait une ligne rassurante a chaque reveil.
    //
    // Ce sont les cotes du Hilux, du Land Cruiser, du D-Max, du L200 et du
    // Navara qui disparaitraient ainsi — l'ossature du parc burkinabe, que le
    // Canada ignore et que l'Europe est seule a publier.

    public function test_une_source_fraiche_ne_bloque_pas_l_autre(): void
    {
        // Le canadien vient de tourner.
        $this->importer();

        $this->assertSame(6, Motorisation::where('source', 'nrcan')->count());

        // Le service europeen ne rend rien : ce test porte sur la GARDE, pas
        // sur le rendement. Ce qui compte est que la commande aille jusqu'a
        // l'interroger.
        Http::fake(['*' => Http::response(['results' => []])]);

        $this->artisan('catalog:import-consommations', [
            '--source'            => 'eea',
            '--si-plus-vieux-que' => 180,
        ])
            ->doesntExpectOutputToContain('rien a faire')
            ->assertSuccessful();
    }

    public function test_la_garde_reste_posee_source_par_source(): void
    {
        // Une cote europeenne fraiche, et rien du cote canadien.
        Motorisation::create([
            'source'     => 'eea',
            'cycle'      => 'wltp',
            'model_year' => 2024,
            'make_raw'   => 'Toyota',
            'model_raw'  => 'Hilux',
        ]);

        Http::fake();

        // Le canadien n'a jamais tourne : sa garde doit laisser passer, meme
        // si la table porte une ligne ecrite a l'instant par l'autre source.
        $this->artisan('catalog:import-consommations', [
            '--fichier'           => [$this->extrait()],
            '--si-plus-vieux-que' => 180,
        ])->assertSuccessful();

        $this->assertSame(
            6,
            Motorisation::where('source', 'nrcan')->count(),
            "L'import canadien a ete bloque par la fraicheur d'une cote europeenne."
        );

        // Et dans l'autre sens, la garde tient toujours : relancer le canadien
        // juste apres ne retelecharge rien.
        Http::fake();

        $this->artisan('catalog:import-consommations', [
            '--fichier'           => [$this->extrait()],
            '--si-plus-vieux-que' => 180,
        ])
            ->expectsOutputToContain('rien a faire')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_le_journal_nomme_la_source_qu_il_saute(): void
    {
        // Deux lignes identiques dans un journal de deploiement laisseraient
        // croire qu'un seul import a ete saute. Le plan gratuit de Render
        // n'offre pas de Shell : ce journal est le seul organe d'observation.
        $this->importer();

        Http::fake();

        $this->artisan('catalog:import-consommations', ['--si-plus-vieux-que' => 180])
            ->expectsOutputToContain('Cotes nrcan importees')
            ->assertSuccessful();
    }
}
