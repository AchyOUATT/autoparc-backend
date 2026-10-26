<?php

namespace App\Console\Commands;

use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Trim;
use App\Models\TrimApplication;
use App\Models\VehicleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/**
 * Corrige les VALEURS PAR DEFAUT d'une finition, sous preuve et sous garde.
 *
 * ── CE QU'ELLE CORRIGE, ET POURQUOI CA NE POUVAIT PAS ALLER AILLEURS ───────
 *
 * `trims.default_engine_type_id` et `trims.default_drivetrain_id`, et rien
 * d'autre. Ces deux colonnes sont les seules de la table que personne ne savait
 * corriger :
 *
 *   — `catalog:import-trims` les ignore DELIBEREMENT. Son intitule le dit —
 *     « sans deduire leur moteur » — et son code le fait : il ne retient que
 *     `code` et `description` (`array_intersect_key(..., ['code',
 *     'description'])`). C'est un choix juste : un lot d'importation documente
 *     des MARCHES et des MILLESIMES, pas une motorisation.
 *   — `TrimsSeeder` n'ecrit que ce qui n'existe pas. Il teste
 *     `exists()` sur (vehicle_model_id, name) et fait `insert` ou rien. Une
 *     finition deja en base garde donc ses valeurs, justes ou fausses, pour
 *     toujours.
 *
 * Verifie le 26/10/2026 sur le code, pas suppose.
 *
 * ── POURQUOI UNE VALEUR FAUSSE EST PIRE QU'UNE VALEUR ABSENTE ──────────────
 *
 * C'est le coeur du sujet, et le projet le mesure deja :
 * `FinitionsToyotaSecondeMoitieTest::test_une_motricite_nulle_n_exclut_aucune_piece`.
 * Une motricite par defaut FAUSSE cache une piece qui convient ; une motricite
 * NULLE ne filtre rien. Effacer est donc une correction legitime, et souvent la
 * bonne — d'ou la semantique de `updates` ci-dessous, qui sait effacer.
 *
 * ── LA PORTEE, QUI EST LA REGLE LA PLUS FACILE A OUBLIER ───────────────────
 *
 * Une valeur posee dans `trims` s'applique a TOUTES les applications qui en
 * heritent — tous les marches, tous les millesimes declares dans
 * `trim_applications`. Une brochure d'un seul marche et d'un seul millesime ne
 * justifie donc pas un defaut global.
 *
 * Cette regle ne peut pas rester une promesse : chaque correction DECLARE le
 * nombre d'applications que sa preuve couvre, et la commande refuse si ce
 * nombre ne correspond pas a ce que la base porte. Le jour ou une application
 * s'ajoute, le compte cesse de correspondre et la correction est refusee — ce
 * qui force a reexaminer la preuve au lieu de l'etendre en silence.
 *
 * ── CE QU'ELLE NE FAIT PAS ─────────────────────────────────────────────────
 *
 * Elle ne CREE aucune finition. Ce chemin existe deja et il est bon :
 * `catalog:import-trims` cree la finition absente (`new Trim([...])`) avec son
 * marche, ses millesimes et ses sources. Une commande qui saurait creer ET
 * corriger laisserait creer sans preuve d'application.
 *
 * Et elle ne corrige pas `TrimsSeeder`. Elle ne peut pas : ses donnees sont des
 * constantes PHP. Mais la correction y est OBLIGATOIRE, sans quoi une base
 * neuve — et le `Dockerfile` reseme a chaque demarrage du conteneur —
 * reviendrait a l'ancienne valeur. C'est `CorrectionDesFinitionsTest` qui tient
 * cette obligation : il relit les fichiers de correction et exige que
 * `TrimsSeeder` leur donne raison.
 */
class CorrectTrims extends Command
{
    protected $signature = 'catalog:correct-trims
                            {file : Fichier JSON des corrections documentees}
                            {--dry-run : Tout verifier et afficher le bilan sans ecrire}';

    protected $description = 'Corrige les valeurs par defaut d\'une finition, sous preuve documentaire et controle de concurrence';

    /**
     * Les seuls champs corrigeables, et la table de reference de chacun.
     *
     * Enumeres plutot que devines : un champ inconnu doit etre REFUSE avec la
     * liste de ce qui est permis, et non ignore en silence — un `updates` dont
     * la cle est mal orthographiee rendrait sinon « corrige » sans rien
     * corriger.
     */
    private const CHAMPS = [
        'default_engine_type' => ['colonne' => 'default_engine_type_id', 'modele' => EngineType::class],
        'default_drivetrain'  => ['colonne' => 'default_drivetrain_id',  'modele' => Drivetrain::class],
    ];

    public function handle(): int
    {
        try {
            $document = $this->lireDocument();
            $this->valider($document);

            // ── ATOMICITE ───────────────────────────────────────────────────
            //
            // La comparaison de `expected` et l'ecriture vivent dans LA MEME
            // transaction, et la ligne est verrouillee avant d'etre lue
            // (`lockForUpdate`). Sans cela, une modification concurrente peut
            // s'intercaler entre la lecture et l'ecriture, et la garde
            // `expected` ne garderait plus rien — elle donnerait seulement
            // l'impression de garder quelque chose.
            //
            // En `--dry-run` la transaction est ouverte quand meme, les
            // ecritures ont lieu, puis tout est defait : les contraintes de la
            // base sont ainsi reellement eprouvees, et pas seulement les
            // notres. Une simulation qui n'ecrirait rien ne simulerait pas ce
            // qui se passera.
            //
            // CONTROLE MANUEL, et non `DB::transaction()` : appeler
            // `DB::rollBack()` dans la fermeture d'un `DB::transaction()`
            // casse sa comptabilite de niveaux — il croit la transaction
            // encore ouverte et tente de valider par-dessus un retour arriere
            // deja fait.
            DB::beginTransaction();

            try {
                $bilan = [];

                foreach ($document['corrections'] as $index => $correction) {
                    $bilan[] = $this->appliquer($index, $correction);
                }

                if ($this->option('dry-run')) {
                    DB::rollBack();
                } else {
                    DB::commit();
                }
            } catch (\Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }
        } catch (JsonException $exception) {
            $this->error('JSON invalide : '.$exception->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $champ => $messages) {
                foreach ($messages as $message) {
                    $this->error($champ.' : '.$message);
                }
            }

            return self::FAILURE;
        }

        $this->rendreCompte($bilan);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function lireDocument(): array
    {
        $chemin = (string) $this->argument('file');

        if (! is_file($chemin)) {
            throw new InvalidArgumentException("Fichier introuvable : {$chemin}");
        }

        $document = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($document)) {
            throw new InvalidArgumentException('Le document doit etre un objet JSON.');
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function valider(array $document): void
    {
        Validator::make($document, [
            'corrections'                          => ['required', 'array', 'min:1'],
            'corrections.*.brand'                  => ['required', 'string'],
            'corrections.*.model_slug'             => ['required', 'string'],
            'corrections.*.trim'                   => ['required', 'string'],
            // Le nombre d'applications que la PREUVE couvre. Voir le docblock
            // de la classe : c'est ce qui rend la regle de portee mecanique.
            'corrections.*.applications_couvertes' => ['required', 'integer', 'min:0'],
            'corrections.*.expected'               => ['required', 'array'],
            'corrections.*.updates'                => ['required', 'array', 'min:1'],
            'corrections.*.reason'                 => ['required', 'string', 'min:10'],
            'corrections.*.sources'                => ['required', 'array', 'min:1'],
            'corrections.*.sources.*'              => ['required', 'string', 'min:4'],
            'corrections.*.verified_on'            => ['required', 'date'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $correction
     * @return array<string, mixed>
     */
    private function appliquer(int $index, array $correction): array
    {
        $prefixe = "corrections.{$index}";

        $modele = VehicleModel::query()
            ->where('slug', $correction['model_slug'])
            ->whereHas('brand', fn ($q) => $q->where('slug', $correction['brand']))
            ->first();

        if (! $modele) {
            throw new InvalidArgumentException(
                "{$prefixe} : modele introuvable pour {$correction['brand']}/{$correction['model_slug']}."
            );
        }

        // Verrou AVANT lecture : c'est l'ordre qui compte.
        $finition = Trim::where('vehicle_model_id', $modele->id)
            ->where('name', $correction['trim'])
            ->lockForUpdate()
            ->first();

        if (! $finition) {
            throw new InvalidArgumentException(
                "{$prefixe} : finition « {$correction['trim']} » absente de {$correction['model_slug']}. "
                .'Cette commande ne CREE rien : utiliser `catalog:import-trims`, qui cree la finition avec '
                .'son marche, ses millesimes et ses sources.'
            );
        }

        $this->verifierLaPortee($prefixe, $finition, (int) $correction['applications_couvertes']);
        $this->verifierLesChamps($prefixe, $correction);
        $this->verifierLEtatAttendu($prefixe, $finition, $correction['expected']);

        $avant = $this->etatActuel($finition);
        $ecritures = [];

        foreach (self::CHAMPS as $champ => $meta) {
            // `array_key_exists` et NON `isset` : c'est toute la semantique de
            // `updates`. Un champ ABSENT se conserve ; un champ present et
            // explicitement null s'EFFACE. `isset` confondrait les deux et
            // ferait de chaque correction un effacement de tout le reste.
            if (! array_key_exists($champ, $correction['updates'])) {
                continue;
            }

            $valeur = $correction['updates'][$champ];
            $ecritures[$meta['colonne']] = $valeur === null
                ? null
                : $this->resoudreCode($prefixe, $champ, $meta['modele'], (string) $valeur);
        }

        $finition->fill($ecritures)->save();

        return [
            'finition'     => "{$correction['brand']}/{$correction['model_slug']} — {$correction['trim']}",
            'avant'        => $avant,
            'apres'        => $this->etatActuel($finition->refresh()),
            'applications' => (int) $correction['applications_couvertes'],
        ];
    }

    /**
     * La regle de portee : la preuve doit couvrir TOUTES les applications.
     */
    private function verifierLaPortee(string $prefixe, Trim $finition, int $declarees): void
    {
        $reelles = TrimApplication::where('trim_id', $finition->id)->count();

        if ($reelles === $declarees) {
            return;
        }

        throw new InvalidArgumentException(
            "{$prefixe} : la preuve declare couvrir {$declarees} application(s), la base en porte {$reelles}. "
            .'Une valeur par defaut vaut pour TOUS les marches et millesimes qui en heritent : '
            .'reexaminer la preuve, ou corriger `applications_couvertes` apres l\'avoir etendue.'
        );
    }

    /**
     * @param  array<string, mixed>  $correction
     */
    private function verifierLesChamps(string $prefixe, array $correction): void
    {
        $permis = array_keys(self::CHAMPS);

        foreach (['expected', 'updates'] as $bloc) {
            foreach (array_keys($correction[$bloc]) as $champ) {
                if (! in_array($champ, $permis, true)) {
                    throw new InvalidArgumentException(
                        "{$prefixe}.{$bloc} : champ inconnu « {$champ} ». Corrigeables : "
                        .implode(', ', $permis).'.'
                    );
                }
            }
        }

        // `expected` doit couvrir CHAQUE champ modifie. Sans cela, un champ
        // pourrait etre ecrit sans qu'on ait verifie sa valeur de depart — la
        // garde de concurrence aurait alors un trou exactement de la taille du
        // champ qu'on modifie.
        foreach (array_keys($correction['updates']) as $champ) {
            if (! array_key_exists($champ, $correction['expected'])) {
                throw new InvalidArgumentException(
                    "{$prefixe} : « {$champ} » est modifie sans etre declare dans `expected`. "
                    .'Chaque champ modifie doit porter sa valeur attendue.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attendu
     */
    private function verifierLEtatAttendu(string $prefixe, Trim $finition, array $attendu): void
    {
        $actuel = $this->etatActuel($finition);

        foreach ($attendu as $champ => $valeur) {
            $lu = $actuel[$champ];
            $correspond = $valeur === null
                ? $lu === null
                : ($lu !== null && strcasecmp($lu, (string) $valeur) === 0);

            if (! $correspond) {
                throw new InvalidArgumentException(
                    "{$prefixe} : « {$champ} » attendu a ".$this->afficher($valeur)
                    .', trouve '.$this->afficher($lu).'. La base a change depuis la redaction de la '
                    .'correction : relire avant d\'ecrire.'
                );
            }
        }
    }

    /**
     * L'etat courant, exprime en CODES et non en identifiants.
     *
     * Les identifiants ne sont pas stables d'une base a l'autre — un
     * `drivetrains.id` depend de l'ordre de semis — et une correction ecrite
     * contre des identifiants serait juste sur un serveur et fausse sur le
     * suivant. `expected` se compare donc sur des codes.
     *
     * @return array<string, string|null>
     */
    private function etatActuel(Trim $finition): array
    {
        $etat = [];

        foreach (self::CHAMPS as $champ => $meta) {
            $id = $finition->{$meta['colonne']};
            $etat[$champ] = $id === null
                ? null
                : $meta['modele']::find($id)?->code;
        }

        return $etat;
    }

    /**
     * @param  class-string  $modele
     */
    private function resoudreCode(string $prefixe, string $champ, string $modele, string $code): int
    {
        // Sans egard a la casse : `drivetrains` porte « AWD » et
        // `engine_types` « petrol ». Exiger la casse exacte ferait echouer une
        // correction juste sur une majuscule, et personne ne devine de quel
        // cote est la convention.
        $trouve = $modele::whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();

        if ($trouve) {
            return (int) $trouve->id;
        }

        $connus = $modele::orderBy('code')->pluck('code')->implode(', ');

        throw new InvalidArgumentException(
            "{$prefixe} : code inconnu « {$code} » pour « {$champ} ». Codes en base : {$connus}."
        );
    }

    private function afficher(mixed $valeur): string
    {
        return $valeur === null ? '(vide)' : '« '.$valeur.' »';
    }

    /**
     * @param  array<int, array<string, mixed>>  $bilan
     */
    private function rendreCompte(array $bilan): void
    {
        foreach ($bilan as $ligne) {
            $this->line($ligne['finition']);

            foreach (array_keys(self::CHAMPS) as $champ) {
                $avant = $ligne['avant'][$champ];
                $apres = $ligne['apres'][$champ];

                $this->line(sprintf(
                    '  %-22s %s%s',
                    $champ,
                    $this->afficher($avant),
                    $avant === $apres ? ' (inchange)' : ' -> '.$this->afficher($apres),
                ));
            }

            $this->line("  applications couvertes {$ligne['applications']}");
        }

        if ($this->option('dry-run')) {
            $this->warn('Simulation : aucune ecriture conservee.');

            return;
        }

        $this->warn(
            'Corriger aussi `TrimsSeeder` : il n\'ecrit que ce qui n\'existe pas, et une base neuve '
            .'reviendrait a l\'ancienne valeur. `CorrectionDesFinitionsTest` echoue tant que ce n\'est pas fait.'
        );
    }
}
