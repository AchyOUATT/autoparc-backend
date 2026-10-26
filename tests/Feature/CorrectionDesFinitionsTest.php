<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Trim;
use App\Models\TrimApplication;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `catalog:correct-trims`, et les sept regles qu'elle doit tenir.
 *
 * Elle corrige `trims.default_engine_type_id` et
 * `trims.default_drivetrain_id` — les deux seules colonnes de la table que
 * personne ne savait corriger :
 *
 *   — `catalog:import-trims` les ignore deliberement, son intitule le dit
 *     (« sans deduire leur moteur ») et son code ne retient que `code` et
 *     `description` ;
 *   — `TrimsSeeder` n'ecrit que ce qui n'existe pas : `exists()` puis `insert`
 *     ou rien, donc une finition deja en base garde ses valeurs pour toujours.
 *
 * ── CE QUI EST EN JEU ──────────────────────────────────────────────────────
 *
 * Une valeur par defaut FAUSSE cache une piece qui convient ; une valeur NULLE
 * ne filtre rien. Le projet le mesure deja dans
 * `FinitionsToyotaSecondeMoitieTest::test_une_motricite_nulle_n_exclut_aucune_piece`.
 * Effacer est donc une correction legitime, et souvent la bonne — d'ou la
 * semantique de `updates`, qui distingue l'absence de l'effacement.
 */
class CorrectionDesFinitionsTest extends TestCase
{
    use RefreshDatabase;

    private VehicleModel $modele;
    private Trim $finition;

    /** Racine temporaire des documents de ce banc. */
    private string $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $marque = Brand::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->modele = VehicleModel::create([
            'brand_id' => $marque->id, 'name' => 'Highlander', 'slug' => 'highlander',
        ]);

        EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        EngineType::create(['code' => 'hybrid', 'label' => 'Hybride']);
        Drivetrain::create(['code' => 'FWD', 'label' => 'Traction avant']);
        Drivetrain::create(['code' => 'AWD', 'label' => 'Transmission integrale']);

        $this->finition = Trim::create([
            'vehicle_model_id' => $this->modele->id,
            'name' => 'Limited',
            'rank' => 3,
            'default_engine_type_id' => EngineType::where('code', 'petrol')->value('id'),
            'default_drivetrain_id' => Drivetrain::where('code', 'AWD')->value('id'),
        ]);

        // ── LA RACINE DES DOCUMENTS, POUR CE BANC SEULEMENT ────────────────
        //
        // `catalog:correct-trims` n'accepte que des documents sous le
        // repertoire versionne des corrections. Ce banc ecrit des documents
        // JETABLES : les poser dans le repertoire versionne y laisserait, apres
        // un test interrompu, un fichier que la regle 4 relirait ensuite comme
        // une reference. Il surcharge donc la racine vers un repertoire
        // temporaire, qu'il nettoie.
        $this->racine = storage_path('framework/testing/corrections-'.uniqid());
        File::ensureDirectoryExists($this->racine);
        config()->set('catalogue.corrections_finitions', $this->racine);

        // Deux applications : c'est ce que la preuve devra couvrir.
        foreach ([[2020, 2022], [2023, 2024]] as [$de, $a]) {
            TrimApplication::create([
                'trim_id' => $this->finition->id,
                'market' => 'na',
                'model_year_from' => $de,
                'model_year_to' => $a,
                'sources' => ['https://exemple.test/brochure.pdf'],
                'verified_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->racine);

        parent::tearDown();
    }

    /** Ecrit un document de correction et rend son chemin. */
    private function document(array $correction, array $remplacements = []): string
    {
        $chemin = $this->racine.DIRECTORY_SEPARATOR.'correction-'.uniqid().'.json';

        File::put($chemin, json_encode(
            ['corrections' => [array_replace($correction, $remplacements)]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $chemin;
    }

    /** Une correction valable : elle efface la motricite par defaut. */
    private function correctionValable(): array
    {
        return [
            'brand' => 'toyota',
            'model_slug' => 'highlander',
            'trim' => 'Limited',
            'applications_couvertes' => 2,
            'expected' => ['default_drivetrain' => 'AWD'],
            'updates' => ['default_drivetrain' => null],
            'reason' => 'La brochure donne la Limited en traction avant de serie, AWD en option.',
            'sources' => ['https://exemple.test/brochure-2021.pdf'],
            'verified_on' => '2026-10-26',
        ];
    }

    private function lancer(string $chemin, array $options = []): int
    {
        return Artisan::call('catalog:correct-trims', ['file' => $chemin] + $options);
    }

    // ── ETAPE 1 : REJOUABLE, BORNEE, ET QUI REFUSE LISIBLEMENT ──────────────

    /**
     * LE MEME FICHIER, DEUX FOIS.
     *
     * `expected` decrit l'etat AVANT la correction. Apres une passe reussie, la
     * base porte `updates` : le meme document rejoue echouait donc sur « la
     * base a change depuis la redaction de la correction » — vrai, mais
     * inutile. Or rejouer est un geste normal : apres un echec partiel, ou sur
     * une base restauree.
     */
    public function test_le_meme_document_se_rejoue_sans_ecrire(): void
    {
        $chemin = $this->document($this->correctionValable());

        $this->assertSame(0, $this->lancer($chemin), 'La premiere passe doit reussir.');
        $this->assertNull($this->finition->refresh()->default_drivetrain_id);

        $empreinte = $this->finition->refresh()->updated_at;

        $this->assertSame(0, $this->lancer($chemin), 'La seconde passe doit reussir aussi.');
        $this->assertStringContainsString('deja applique', Artisan::output());

        // ── POURQUOI `updated_at` COMPTE ──────────────────────────────────
        //
        // C'est le curseur de la synchro mobile : `/catalog/sync?since=…` ne
        // renvoie que ce qui a bouge. Une reecriture a l'identique ferait
        // retelecharger la finition par tous les appareils, pour rien.
        $this->assertEquals($empreinte, $this->finition->refresh()->updated_at,
            'Une passe sans effet ne doit pas toucher a `updated_at`.');
    }

    /**
     * L'etat MIXTE a son propre refus.
     *
     * Un champ deja corrige et un autre non : la correction a ete
     * partiellement appliquee — passe interrompue, ou ecriture a la main. Le
     * message generique « la base a change » enverrait relire la preuve, alors
     * que le travail est de constater ce qui est passe.
     */
    public function test_une_correction_partiellement_appliquee_est_nommee(): void
    {
        $correction = array_replace($this->correctionValable(), [
            'expected' => ['default_drivetrain' => 'AWD', 'default_engine_type' => 'petrol'],
            'updates' => ['default_drivetrain' => null, 'default_engine_type' => 'hybrid'],
        ]);

        // Un seul des deux champs a deja ete passe.
        $this->finition->update(['default_drivetrain_id' => null]);

        $this->assertSame(1, $this->lancer($this->document($correction)));

        $sortie = Artisan::output();
        $this->assertStringContainsString('partiellement appliquee', $sortie);
        $this->assertStringContainsString('default_drivetrain', $sortie);

        // Et rien n'a bouge : un refus ne corrige pas a moitie.
        $this->assertSame(
            EngineType::where('code', 'petrol')->value('id'),
            $this->finition->refresh()->default_engine_type_id,
        );
    }

    /**
     * LE DOCUMENT DOIT VIVRE DANS LE DEPOT.
     *
     * Le README du repertoire enonce la regle — ces fichiers sont versionnes et
     * ils sont la reference, la suite exige que `TrimsSeeder` leur donne
     * raison — mais rien ne la tenait. Une correction appliquee depuis `/tmp`
     * partait en base sans laisser de trace, et la suite continuait de donner
     * raison a l'ancienne valeur.
     */
    public function test_un_document_hors_du_repertoire_versionne_est_refuse(): void
    {
        $dehors = storage_path('framework/testing/hors-depot-'.uniqid().'.json');
        File::put($dehors, json_encode(['corrections' => [$this->correctionValable()]]));

        $this->assertSame(1, $this->lancer($dehors));
        $this->assertStringContainsString('doit vivre sous', Artisan::output());

        // La base n'a pas bouge.
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);

        File::delete($dehors);
    }

    /**
     * UN REPERTOIRE VOISIN NE PASSE PAS POUR LA RACINE.
     *
     * La comparaison porte sur le separateur final. Sans lui,
     * `corrections-finitions-brouillon` commence par `corrections-finitions` et
     * passerait la borne : un repertoire de travail pose a cote du bon suffit
     * alors a appliquer des corrections hors du depot. Une mutation a survecu
     * exactement comme ca.
     */
    public function test_un_repertoire_voisin_au_nom_proche_est_refuse(): void
    {
        $voisin = $this->racine.'-brouillon';
        File::ensureDirectoryExists($voisin);

        $chemin = $voisin.DIRECTORY_SEPARATOR.'correction.json';
        File::put($chemin, json_encode(['corrections' => [$this->correctionValable()]]));

        $this->assertSame(1, $this->lancer($chemin));
        $this->assertStringContainsString('doit vivre sous', Artisan::output());
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);

        File::deleteDirectory($voisin);
    }

    /** Une remontee par `..` ne sort pas de la racine : `realpath` resout avant. */
    public function test_une_remontee_de_repertoire_ne_contourne_pas_la_borne(): void
    {
        $dehors = storage_path('framework/testing/remontee-'.uniqid().'.json');
        File::put($dehors, json_encode(['corrections' => [$this->correctionValable()]]));

        $parLeHaut = $this->racine.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($dehors);

        $this->assertSame(1, $this->lancer($parLeHaut));
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);

        File::delete($dehors);
    }

    /**
     * Une valeur non scalaire faisait lever une `ErrorException` de conversion,
     * sans dire quel champ ni quel fichier : un document mal forme ressortait
     * en trace PHP au lieu d'un refus lisible.
     */
    public function test_une_valeur_non_scalaire_est_refusee_par_son_nom(): void
    {
        $correction = array_replace($this->correctionValable(), [
            'updates' => ['default_drivetrain' => ['AWD', 'FWD']],
        ]);

        $this->assertSame(1, $this->lancer($this->document($correction)));

        $sortie = Artisan::output();
        $this->assertStringContainsString('default_drivetrain', $sortie);
        $this->assertStringContainsString('array', $sortie);
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);
    }

    // ── REGLE 2 : le sens de `updates` ───────────────────────────────────────

    public function test_un_null_explicite_efface_la_valeur(): void
    {
        $this->assertSame(0, $this->lancer($this->document($this->correctionValable())));

        $this->assertNull($this->finition->refresh()->default_drivetrain_id);
    }

    public function test_un_champ_ABSENT_de_updates_est_CONSERVE(): void
    {
        // LE TEST QUI COMPTE LE PLUS DE CE BANC. C'est lui qui distingue une
        // correction ciblee d'un effacement systematique : `updates` ne parle
        // pas de la motorisation, elle doit donc survivre intacte.
        //
        // En PHP la frontiere tient a un seul choix : `array_key_exists` et non
        // `isset`. `isset` rend faux pour une cle presente valant null, si bien
        // qu'un effacement demande serait pris pour une absence — et une
        // absence pour un effacement.
        $avant = $this->finition->default_engine_type_id;
        $this->assertNotNull($avant);

        $this->assertSame(0, $this->lancer($this->document($this->correctionValable())));

        $this->assertSame(
            $avant,
            $this->finition->refresh()->default_engine_type_id,
            'Un champ absent de `updates` a ete efface.'
        );
    }

    public function test_une_valeur_peut_etre_remplacee_et_pas_seulement_effacee(): void
    {
        $chemin = $this->document($this->correctionValable(), [
            'expected' => ['default_engine_type' => 'petrol'],
            'updates' => ['default_engine_type' => 'hybrid'],
        ]);

        $this->assertSame(0, $this->lancer($chemin));

        $this->assertSame(
            EngineType::where('code', 'hybrid')->value('id'),
            $this->finition->refresh()->default_engine_type_id,
        );
    }

    public function test_un_code_est_lu_sans_egard_a_la_casse(): void
    {
        // `drivetrains` porte « AWD », `engine_types` porte « petrol » : exiger
        // la casse exacte ferait echouer une correction juste sur une majuscule,
        // et personne ne devine de quel cote est la convention.
        $chemin = $this->document($this->correctionValable(), [
            'expected' => ['default_drivetrain' => 'awd'],
            'updates' => ['default_drivetrain' => 'fwd'],
        ]);

        $this->assertSame(0, $this->lancer($chemin));

        $this->assertSame(
            Drivetrain::where('code', 'FWD')->value('id'),
            $this->finition->refresh()->default_drivetrain_id,
        );
    }

    // ── REGLE 3 : concurrence ────────────────────────────────────────────────

    public function test_un_etat_attendu_qui_ne_correspond_plus_est_refuse(): void
    {
        // La base a change depuis la redaction de la correction.
        $this->finition->update(['default_drivetrain_id' => Drivetrain::where('code', 'FWD')->value('id')]);

        $chemin = $this->document($this->correctionValable());

        $this->assertSame(1, $this->lancer($chemin));
        $this->assertSame(
            Drivetrain::where('code', 'FWD')->value('id'),
            $this->finition->refresh()->default_drivetrain_id,
            'Un refus a quand meme ecrit.'
        );
    }

    public function test_un_champ_modifie_sans_valeur_attendue_est_refuse(): void
    {
        // Sans cela, la garde de concurrence aurait un trou exactement de la
        // taille du champ qu'on modifie.
        $chemin = $this->document($this->correctionValable(), [
            'expected' => ['default_drivetrain' => 'AWD'],
            'updates' => ['default_drivetrain' => null, 'default_engine_type' => 'hybrid'],
        ]);

        $this->assertSame(1, $this->lancer($chemin));
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id, 'Rien ne devait etre ecrit.');
    }

    public function test_un_etat_attendu_nul_est_compare_comme_tel(): void
    {
        // `null` attendu doit correspondre a une colonne vide, et non a
        // n'importe quoi : une comparaison laxiste laisserait passer une
        // correction ecrite contre un etat qui n'existe plus.
        $chemin = $this->document($this->correctionValable(), [
            'expected' => ['default_drivetrain' => null],
            'updates' => ['default_drivetrain' => 'FWD'],
        ]);

        $this->assertSame(1, $this->lancer($chemin), 'Attendu nul contre une valeur posee : refus attendu.');
    }

    // ── REGLE 1 : la portee ──────────────────────────────────────────────────

    public function test_une_preuve_qui_ne_couvre_pas_toutes_les_applications_est_refusee(): void
    {
        // Une brochure d'un seul marche et d'un seul millesime ne justifie pas
        // un defaut global : la finition porte DEUX applications.
        $chemin = $this->document($this->correctionValable(), ['applications_couvertes' => 1]);

        $this->assertSame(1, $this->lancer($chemin));
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);
    }

    public function test_une_application_ajoutee_fait_refuser_une_correction_jusque_la_valable(): void
    {
        // La propriete qui compte : le compte declare cesse de correspondre, et
        // la correction est refusee au lieu d'etre etendue en silence a un
        // marche que la preuve ne couvre pas.
        $chemin = $this->document($this->correctionValable());

        TrimApplication::create([
            'trim_id' => $this->finition->id,
            'market' => 'eu',
            'model_year_from' => 2021,
            'model_year_to' => 2021,
            'sources' => ['https://exemple.test/autre.pdf'],
            'verified_at' => now(),
        ]);

        $this->assertSame(1, $this->lancer($chemin));
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);
    }

    // ── REGLE 5 : la creation reste a `import-trims` ─────────────────────────

    public function test_une_finition_absente_est_refusee_en_nommant_la_commande_de_creation(): void
    {
        $chemin = $this->document($this->correctionValable(), ['trim' => 'Platinum']);

        $this->assertSame(1, $this->lancer($chemin));
        $this->assertStringContainsString('catalog:import-trims', Artisan::output());
    }

    public function test_un_modele_absent_est_refuse(): void
    {
        $this->assertSame(1, $this->lancer($this->document($this->correctionValable(), ['model_slug' => 'sienna'])));
    }

    // ── REGLE 6 : surete ─────────────────────────────────────────────────────

    public function test_une_simulation_n_ecrit_rien(): void
    {
        $avant = $this->finition->default_drivetrain_id;

        $this->assertSame(0, $this->lancer($this->document($this->correctionValable()), ['--dry-run' => true]));

        $this->assertSame($avant, $this->finition->refresh()->default_drivetrain_id);
    }

    public function test_un_champ_inconnu_est_refuse_en_nommant_les_champs_permis(): void
    {
        $chemin = $this->document($this->correctionValable(), [
            'expected' => ['default_motricite' => 'AWD'],
            'updates' => ['default_motricite' => null],
        ]);

        $this->assertSame(1, $this->lancer($chemin));

        $sortie = Artisan::output();
        $this->assertStringContainsString('default_drivetrain', $sortie);
        $this->assertStringContainsString('default_engine_type', $sortie);
    }

    public function test_un_code_inconnu_est_refuse_en_nommant_les_codes_de_la_base(): void
    {
        $chemin = $this->document($this->correctionValable(), [
            'expected' => ['default_drivetrain' => 'AWD'],
            'updates' => ['default_drivetrain' => 'QUATTRO'],
        ]);

        $this->assertSame(1, $this->lancer($chemin));

        $sortie = Artisan::output();
        $this->assertStringContainsString('QUATTRO', $sortie);
        $this->assertStringContainsString('AWD', $sortie);
        $this->assertNotNull($this->finition->refresh()->default_drivetrain_id);
    }

    public function test_une_preuve_incomplete_est_refusee(): void
    {
        foreach (['reason' => '', 'sources' => [], 'verified_on' => 'pas une date'] as $champ => $valeur) {
            $this->assertSame(
                1,
                $this->lancer($this->document($this->correctionValable(), [$champ => $valeur])),
                "Une correction sans {$champ} valable a ete acceptee.",
            );
        }
    }

    // ── Conservation des identifiants et des relations ───────────────────────

    public function test_la_correction_ne_recree_rien_et_garde_ses_relations(): void
    {
        // Une correction qui supprimerait puis recreerait la finition casserait
        // les vehicules qui la portent et les applications qui l'attestent, en
        // laissant la base d'apparence correcte.
        $idFinition = $this->finition->id;
        $idsApplications = TrimApplication::where('trim_id', $idFinition)->orderBy('id')->pluck('id')->all();

        $this->assertSame(0, $this->lancer($this->document($this->correctionValable())));

        $this->assertSame(1, Trim::where('vehicle_model_id', $this->modele->id)->count());
        $this->assertSame($idFinition, Trim::where('name', 'Limited')->value('id'));
        $this->assertSame(
            $idsApplications,
            TrimApplication::where('trim_id', $idFinition)->orderBy('id')->pluck('id')->all(),
        );

        // Et le reste de la ligne n'a pas bouge.
        $apres = $this->finition->refresh();
        $this->assertSame('Limited', $apres->name);
        $this->assertSame(3, $apres->rank);
    }

    // ── REGLE 4 : la reference doit etre corrigee aussi ──────────────────────

    /**
     * LE GARDE QUI REND LA REGLE 4 MECANIQUE.
     *
     * `TrimsSeeder` n'ecrit que ce qui n'existe pas. Corriger la base ne suffit
     * donc pas : une base neuve — et le `Dockerfile` reseme a chaque demarrage
     * du conteneur — reviendrait a l'ancienne valeur, sans que rien ne le dise.
     *
     * Ce test relit les fichiers de correction versionnes et exige que le
     * seeder leur donne raison. Il ECHOUE tant que le seeder porte l'ancienne
     * valeur, ce qui est exactement l'obligation que la regle 4 pose.
     *
     * Sans fichier de correction, il ne se SAUTE PAS : un test saute partout
     * est un test qui n'existe pas, et ce dossier en a deja fait l'experience
     * avec les variantes d'apercu. Il verifie alors que le dispositif est en
     * place et s'arme de lui-meme au premier fichier.
     */
    public function test_le_seeder_donne_raison_aux_corrections_versionnees(): void
    {
        $dossier = database_path('catalogue/corrections-finitions');

        $this->assertDirectoryExists($dossier, 'Le dossier des corrections a disparu : le garde ne garde plus rien.');

        $fichiers = array_values(array_filter(
            File::files($dossier),
            fn ($f) => $f->getExtension() === 'json',
        ));

        if ($fichiers === []) {
            // Aucune correction appliquee a ce jour. On verifie que le
            // mecanisme tient : le format est documente et la commande existe.
            $this->assertFileExists($dossier.'/README.md');
            $this->assertArrayHasKey('catalog:correct-trims', Artisan::all());

            return;
        }

        foreach ([
            \Database\Seeders\CountriesSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\DrivetrainsSeeder::class,
            \Database\Seeders\EngineTypesSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
            \Database\Seeders\TrimsSeeder::class,
        ] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        foreach ($fichiers as $fichier) {
            $document = json_decode($fichier->getContents(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($document['corrections'] as $correction) {
                $finition = Trim::query()
                    ->where('name', $correction['trim'])
                    ->whereHas('vehicleModel', fn ($q) => $q->where('slug', $correction['model_slug']))
                    ->first();

                $this->assertNotNull($finition, sprintf(
                    '%s : le seeder ne cree pas %s/%s — %s.',
                    $fichier->getFilename(), $correction['brand'], $correction['model_slug'], $correction['trim'],
                ));

                foreach ($correction['updates'] as $champ => $attendu) {
                    $colonne = $champ === 'default_engine_type' ? 'default_engine_type_id' : 'default_drivetrain_id';
                    $modele = $champ === 'default_engine_type' ? EngineType::class : Drivetrain::class;

                    $obtenu = $finition->{$colonne} === null
                        ? null
                        : $modele::find($finition->{$colonne})?->code;

                    $message = sprintf(
                        '%s : TrimsSeeder porte encore %s pour %s/%s (%s attendu). '
                        .'Corriger le seeder : une base neuve reviendrait a l\'ancienne valeur.',
                        $fichier->getFilename(),
                        $obtenu ?? '(vide)',
                        $correction['model_slug'],
                        $correction['trim'],
                        $attendu ?? '(vide)',
                    );

                    if ($attendu === null) {
                        $this->assertNull($obtenu, $message);
                    } else {
                        $this->assertNotNull($obtenu, $message);
                        $this->assertSame(0, strcasecmp($obtenu, (string) $attendu), $message);
                    }
                }
            }
        }
    }
}
