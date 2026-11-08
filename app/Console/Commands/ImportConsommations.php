<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use App\Services\ConsumptionSourceArchive;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Import source references while retaining their identity, original bytes and limits.
 * A name-based catalogue link is not documentary verification of a vehicle version.
 */
class ImportConsommations extends Command
{
    protected $signature = 'catalog:import-consommations
                            {--source=nrcan : nrcan (Canada) ou eea (Europe)}
                            {--fichier=* : Fichier CSV local, au lieu du telechargement}
                            {--modele=* : Limiter la source europeenne a ces modeles}
                            {--dry-run : Analyser sans rien ecrire}
                            {--relier-seulement : Ne pas importer, seulement rattacher au catalogue}
                            {--si-plus-vieux-que= : N\'importer que si le dernier catalogue complet date de plus de N jours (0 : toujours)}';

    protected $description = 'Importe des references de consommation documentees (Canada, Europe)';

    private array $failures = [];

    private array $importFiles = [];

    private ?int $runId = null;

    /** Jeu de donnees « Cotes de consommation de carburant » sur open.canada.ca. */
    private const CATALOGUE_CKAN = 'https://open.canada.ca/data/api/3/action/package_show?id=98f1a129-f628-4ce4-b24d-6f16bf24dd64';

    /**
     * Noms de marque des sources qui ne sont pas ceux du catalogue.
     *
     * Le registre europeen ecrit tantot « VOLKSWAGEN », tantot « VW » — deux
     * cotes de Polo et trois de T-Roc sont restees orphelines pour cette seule
     * raison, alors que le modele existait au catalogue.
     */
    private const ALIAS_MARQUE = [
        'vw' => 'volkswagen',
        'volkswagen-nutzfahrzeuge' => 'volkswagen',
        'mercedes-amg' => 'mercedes-benz',
        'mercedes' => 'mercedes-benz',
        'bmw-i' => 'bmw',
    ];

    /**
     * Les noms sous lesquels cette marque peut figurer au catalogue.
     *
     * Le registre europeen empile parfois les appellations dans un seul champ
     * — « VOLKSWAGEN, VW » — et le catalogue n'en connait qu'une. On rend donc
     * chaque variante, a charge a l'appelant de retenir celle qu'il reconnait.
     *
     * @return array<int, string>
     */
    private static function slugsMarque(string $marque): array
    {
        $slugs = [];

        foreach (preg_split('/[,\/]+/', $marque) as $partie) {
            $slug = Str::slug(trim($partie));
            if ($slug === '') {
                continue;
            }
            $slugs[] = self::ALIAS_MARQUE[$slug] ?? $slug;
        }

        return array_values(array_unique($slugs));
    }

    /** Stable source configuration; a rounded engine volume cannot identify a version. */
    public static function empreinte(array $ligne): string
    {
        $identity = [];
        foreach ([
            'source', 'market', 'year_kind', 'model_year', 'make_raw', 'model_raw',
            'engine_l', 'displacement_cc', 'power_kw', 'cylinders',
            'transmission_code', 'fuel_code', 'hybrid_type', 'cycle', 'method',
        ] as $field) {
            $value = $ligne[$field] ?? null;
            $identity[$field] = $value === null ? null : mb_strtolower(trim((string) $value));
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /** A changed file or changed published value creates a new immutable reference. */
    private function qualifier(array $ligne, array $provenance): array
    {
        $ligne['identity_key'] = self::empreinte($ligne);
        $revision = [
            $ligne['identity_key'], $provenance['file_sha256'] ?? null,
            $provenance['resource_id'] ?? null, $provenance['resource_revision'] ?? null,
            $provenance['resource_modified_at'] ?? null, $provenance['published_at'] ?? null,
        ];
        foreach (['consumption_city', 'consumption_highway', 'consumption_combined', 'co2_g_km'] as $field) {
            $revision[] = $ligne[$field] ?? null;
        }
        $ligne['cle_source'] = hash('sha256', json_encode($revision, JSON_THROW_ON_ERROR));
        $ligne['reference_status'] = 'documented';
        $ligne['is_current'] = true;
        $ligne['provenance'] = $provenance;

        return $ligne;
    }

    public function handle(): int
    {
        $this->failures = [];
        $this->importFiles = [];
        $this->runId = null;
        if (! in_array($this->option('source'), ['nrcan', 'eea'], true)) {
            $this->error('Source inconnue.');

            return self::FAILURE;
        }
        if ($this->option('relier-seulement')) {
            return $this->relier();
        }

        if ($this->estEncoreFraiche()) {
            return self::SUCCESS;
        }

        if (! $this->option('dry-run')) {
            $this->runId = DB::table('consumption_imports')->insertGetId([
                'source' => $this->option('source'),
                'scope' => $this->option('fichier') ? 'local_files' : ($this->option('source') === 'eea' ? 'model_sample' : 'catalogue'),
                'status' => 'running',
                'started_at' => now(),
            ]);
        }

        try {
            return $this->executerImport();
        } catch (\Throwable $exception) {
            $this->terminerImport('failed', $this->runId === null ? 0 : (int) DB::table('consumption_imports')->where('id', $this->runId)->value('written_count'));
            throw $exception;
        }
    }

    private function executerImport(): int
    {
        if ($this->option('source') === 'eea') {
            return $this->importerEurope();
        }

        $sources = $this->option('fichier')
            ? $this->depuisFichiers($this->option('fichier'))
            : $this->depuisCanada();

        if ($sources === []) {
            $this->error('Aucune source de donnees exploitable.');
            $this->terminerImport('failed', 0);

            return self::FAILURE;
        }

        $total = ['lues' => 0, 'retenues' => 0, 'ignorees' => 0];
        $ecrites = 0;

        foreach ($sources as $nom => $source) {
            $this->info("Lecture de $nom");
            $lignes = $this->analyser($source['content'], $total, $source['provenance']);
            $this->importFiles[] = [
                'name' => $nom, 'source_url' => $source['provenance']['source_url'],
                'file_sha256' => $source['provenance']['file_sha256'],
                'status' => $lignes === [] ? 'failed' : 'parsed', 'record_count' => count($lignes),
            ];
            if ($lignes === []) {
                $this->failures[] = $nom;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('  %d lignes exploitables (rien n\'est ecrit)', count($lignes)));

                continue;
            }

            $cePaquet = $this->enregistrer($lignes);
            $ecrites += $cePaquet;
            $this->line(sprintf('  %d lignes enregistrees', $cePaquet));
        }

        $this->newLine();
        $this->line(sprintf(
            'Lignes lues : %d — retenues : %d — ignorees : %d',
            $total['lues'], $total['retenues'], $total['ignorees']
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // UNE SOURCE QUI REPOND N'EST PAS UNE SOURCE QUI SERT, et le cas n'est
        // pas theorique : les libelles de colonnes varient deja d'un fichier a
        // l'autre — c'est la raison d'etre de `cleColonne()`, qui fait tenir
        // « City (L/100 km) » et « City (L/100km) » pour la meme colonne. Le
        // jour ou la variation depasse ce que la normalisation absorbe,
        // `analyser()` ecarte le fichier entier sur un simple `warn` et la
        // commande rendait 0 : le journal de deploiement affichait « 0 lignes
        // enregistrees » au milieu d'un demarrage qui se lisait comme sain, et
        // le `|| echo` du Dockerfile ne s'allumait jamais. Rendre 1 ici est la
        // seule facon qu'a ce bloc de dire quelque chose.
        if ($ecrites === 0) {
            $this->error(
                'Aucune cote ecrite : la source a repondu, mais rien d\'exploitable n\'en est sorti '
                .'(en-tetes inattendus, ou fichiers vides).'
            );

            $this->terminerImport('failed', 0);

            return self::FAILURE;
        }

        $partial = $this->failures !== [];
        $this->terminerImport($partial || $this->option('fichier') ? 'partial' : 'complete', $ecrites);
        $this->relier();
        if ($partial) {
            $this->error('Import partiel : les ressources manquantes doivent etre reprises.');
        }

        return $partial ? self::FAILURE : self::SUCCESS;
    }

    /** Freshness belongs to a completed catalogue run, never a reference timestamp. */
    private function estEncoreFraiche(): bool
    {
        $jours = $this->option('si-plus-vieux-que');
        if ($jours === null || $this->option('fichier') || $this->option('modele')) {
            return false;
        }
        $lastRun = DB::table('consumption_imports')
            ->where('source', $this->option('source'))->where('scope', 'catalogue')
            ->latest('id')->first();
        $derniere = $lastRun?->completed_at;
        if ($lastRun?->status !== 'complete' || $derniere === null || Carbon::parse($derniere)->lessThanOrEqualTo(now()->subDays((int) $jours))) {
            return false;
        }
        // A pre-archive import can be recent while its only copies have been
        // lost with a Render container. Reimport once using durable storage.
        $files = json_decode($lastRun->files ?? '{}', true);
        if (($files['archive_store'] ?? null) !== 'database') {
            return false;
        }
        $hashes = array_unique(array_column($files['resources'] ?? [], 'file_sha256'));
        if ($hashes === []) {
            return false;
        }
        try {
            foreach ($hashes as $hash) {
                app(ConsumptionSourceArchive::class)->describe($hash);
            }
        } catch (\Throwable) {
            return false;
        }
        $this->info(sprintf('Cotes %s importees le %s : rien a faire.', $this->option('source'), $derniere));

        return true;
    }

    private function terminerImport(string $status, int $written): void
    {
        if ($this->runId !== null) {
            DB::table('consumption_imports')->where('id', $this->runId)->update([
                'status' => $status, 'written_count' => $written,
                'files' => json_encode([
                    'resources' => $this->importFiles, 'failures' => $this->failures,
                    'archive_store' => config('consumption.archive_store'),
                ], JSON_THROW_ON_ERROR),
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Ecrit les lignes, en les reconnaissant a leur empreinte.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function enregistrer(array $lignes): int
    {
        $uniques = [];
        foreach ($lignes as $ligne) {
            $uniques[$ligne['cle_source']] = $ligne;
        }
        foreach (array_chunk(array_values($uniques), 500) as $paquet) {
            DB::transaction(function () use ($paquet) {
                $existing = Motorisation::whereIn('cle_source', array_column($paquet, 'cle_source'))->get()->keyBy('cle_source');
                foreach ($paquet as $ligne) {
                    if ($reference = $existing->get($ligne['cle_source'])) {
                        $this->reparerPreuveArchive($reference, $ligne['provenance']);
                    }
                }
                $nouvelles = array_filter($paquet, fn ($ligne) => ! $existing->has($ligne['cle_source']));
                if ($nouvelles === []) {
                    return;
                }
                // Retrieval order is not publication order. Unknown chronology or
                // conflicting values from one revision remain visible alternatives.
                foreach (array_unique(array_column($nouvelles, 'identity_key')) as $identity) {
                    $current = Motorisation::where('identity_key', $identity)->where('is_current', true)->get();
                    $candidates = $current->map(fn ($r) => ['id' => $r->id, 'provenance' => $r->provenance])->all();
                    foreach ($nouvelles as $key => $row) {
                        if ($row['identity_key'] === $identity) {
                            $candidates[] = ['key' => $key, 'provenance' => $row['provenance']];
                        }
                    }
                    $dates = [];
                    foreach (['resource_modified_at', 'published_at'] as $field) {
                        $dates = [];
                        foreach ($candidates as $candidate) {
                            $raw = $candidate['provenance'][$field] ?? null;
                            $date = is_string($raw) ? strtotime($raw) : false;
                            if ($date === false) {
                                $dates = [];
                                break;
                            }
                            $dates[] = $date;
                        }
                        if ($dates !== []) {
                            break;
                        }
                    }
                    if ($dates !== []) {
                        $newest = max($dates);
                        foreach ($candidates as $i => $candidate) {
                            if ($dates[$i] < $newest) {
                                if (isset($candidate['id'])) {
                                    Motorisation::whereKey($candidate['id'])->update(['is_current' => false]);
                                } else {
                                    $nouvelles[$candidate['key']]['is_current'] = false;
                                }
                            }
                        }
                    }
                }
                $rows = array_map(function ($row) {
                    $row['provenance'] = json_encode($row['provenance'], JSON_THROW_ON_ERROR);

                    return $row;
                }, array_values($nouvelles));
                // Two startup imports may observe the same missing revision.
                // Resolve that race without updating any published value or proof.
                DB::table('motorisations')->upsert($rows, ['cle_source'], ['cle_source']);
            });
        }

        if ($this->runId !== null) {
            DB::table('consumption_imports')->where('id', $this->runId)->increment('written_count', count($uniques));
        }

        return count($uniques);
    }

    /** Add only a verified storage proof; never rewrite the publication or selected row. */
    private function reparerPreuveArchive(Motorisation $reference, array $sourceProvenance): void
    {
        $provenance = $reference->provenance ?? [];
        $archive = $sourceProvenance['archive'] ?? [];
        $hash = $provenance['file_sha256'] ?? null;
        if (($archive['status'] ?? null) !== 'verified' || ! is_string($hash)
            || ! hash_equals($hash, $archive['sha256'] ?? '')) {
            throw new \RuntimeException('La preuve d\'archive ne correspond pas a la reference existante.');
        }
        $previous = $provenance['archive'] ?? [];
        if (($previous['status'] ?? null) === 'verified'
            && ($previous['store'] ?? null) === 'database'
            && ($previous['sha256'] ?? null) === $hash) {
            return;
        }
        $provenance['archive'] = $archive;
        DB::table('motorisations')->where('id', $reference->id)->update([
            'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Integrity failures must not leave an existing proof looking current. */
    private function invaliderPreuvesArchive(string $hash): void
    {
        Motorisation::where('provenance->file_sha256', $hash)->orderBy('id')->chunkById(500, function ($references) use ($hash) {
            foreach ($references as $reference) {
                $provenance = $reference->provenance;
                $provenance['archive'] = [
                    'status' => 'failed', 'store' => 'database', 'key' => $hash,
                    'sha256' => $hash, 'checked_at' => now()->toIso8601String(),
                ];
                DB::table('motorisations')->where('id', $reference->id)->update([
                    'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    /** Store and read back original bytes before claiming a durable source archive. */
    private function provenance(string $content, array $metadata = []): array
    {
        $hash = hash('sha256', $content);
        $archives = app(ConsumptionSourceArchive::class);
        try {
            $archive = $this->option('dry-run') ? $archives->unknown($hash) : $archives->store($content);
        } catch (\Throwable $exception) {
            $this->invaliderPreuvesArchive($hash);
            throw $exception;
        }

        return array_merge([
            'source_url' => null, 'resource_id' => null, 'resource_revision' => null,
            'published_at' => null, 'retrieved_at' => now()->toIso8601String(),
            'imported_at' => now()->toIso8601String(), 'verified_at' => null,
            'file_sha256' => $hash, 'license_id' => null, 'license_title' => null,
            'license_url' => null, 'method' => 'unknown', 'cycle' => 'unknown',
        ], $metadata, ['archive' => $archive]);
    }

    // ── Sources ──────────────────────────────────────────────────────

    /** @param array<int, string> $chemins */
    private function depuisFichiers(array $chemins): array
    {
        $sources = [];

        foreach ($chemins as $chemin) {
            if (! is_readable($chemin)) {
                $this->warn("Fichier illisible, ignore : $chemin");
                $this->failures[] = $chemin;

                continue;
            }
            $contenu = file_get_contents($chemin);
            $sources[$chemin] = ['content' => $contenu, 'provenance' => $this->provenance($contenu)];
        }

        return $sources;
    }

    /**
     * Resout les fichiers via le catalogue ouvert plutot que de figer des URL.
     *
     * Ressources naturelles Canada republie chaque annee, et les adresses
     * changent : une URL en dur se serait tue silencieusement au prochain
     * millesime.
     */
    private function depuisCanada(): array
    {
        // UN SERVICE INJOIGNABLE N'EST PAS UN SERVICE QUI REPOND MAL, et les
        // deux arrivaient ici par des chemins differents. Un 503 etait dit en
        // une ligne ; une resolution DNS qui lache, un refus de connexion ou
        // un depassement des 60 s levaient une ConnectionException que rien
        // n'attrapait — Laravel la rendait en trace d'exception au milieu du
        // journal de deploiement. Le code de sortie etait bon dans les deux
        // cas, la lisibilite non. `interrogerEea()` attrapait deja la sienne ;
        // c'est la meme discipline, appliquee a la source canadienne.
        try {
            $reponse = Http::timeout(60)->get(self::CATALOGUE_CKAN);
        } catch (ConnectionException $e) {
            $this->error('Le catalogue open.canada.ca est injoignable : '.Str::limit($e->getMessage(), 120));

            return [];
        }

        if (! $reponse->successful()) {
            $this->error('Le catalogue open.canada.ca ne repond pas ('.$reponse->status().').');

            return [];
        }

        $adresses = [];
        foreach ($reponse->json('result.resources') ?? [] as $ressource) {
            $url = $ressource['url'] ?? '';

            // Les fichiers francais portent les memes donnees sous des
            // en-tetes traduits ; on s'en tient a la version anglaise.
            // Les deux-cycles sont l'ancienne methode, moins realiste, et
            // les vehicules electriques ont d'autres colonnes (kWh).
            if (! str_ends_with($url, '.csv')) {
                continue;
            }
            if (! str_contains($url, 'fuel-consumption-ratings')) {
                continue;
            }
            if (str_contains($url, 'original-') || str_contains($url, '2-cycle')) {
                continue;
            }
            $description = mb_strtolower(($ressource['name'] ?? '').' '.($ressource['description'] ?? '').' '.$url);
            $adresses[$ressource['id'] ?? $url] = [
                'url' => $url,
                'resource_id' => $ressource['id'] ?? null,
                'resource_revision' => $ressource['revision_id'] ?? null,
                'resource_modified_at' => $ressource['last_modified'] ?? null,
                'resource_created_at' => $ressource['created'] ?? null,
                'published_at' => $ressource['date_published'] ?? null,
                'license_id' => $reponse->json('result.license_id'),
                'license_title' => $reponse->json('result.license_title'),
                'license_url' => $reponse->json('result.license_url'),
                'method' => str_contains($description, 'adjusted') ? 'adjustment' : 'unknown',
                'cycle' => preg_match('/5[ -]cycle|five[ -]cycle/', $description) ? '5-cycle' : 'unknown',
            ];
        }

        if ($adresses === []) {
            $this->error('Aucun fichier de cotes trouve dans le catalogue.');

            return [];
        }

        $sources = [];
        foreach ($adresses as $nom => $resource) {
            $url = $resource['url'];
            try {
                $fichier = Http::timeout(180)->get($url);
            } catch (ConnectionException $e) {
                // Un fichier perdu n'emporte pas les autres : le jeu canadien
                // en compte quatre, et trois valent mieux que zero. Mais le
                // dire nommement, sinon le total final paraitrait normal.
                $this->warn("Telechargement injoignable, ignore : $nom (".Str::limit($e->getMessage(), 90).')');
                $this->failures[] = $url;

                continue;
            }

            if (! $fichier->successful()) {
                $this->warn("Telechargement echoue, ignore : $nom (".$fichier->status().')');
                $this->failures[] = $url;

                continue;
            }
            $metadata = $resource;
            unset($metadata['url']);
            $metadata['source_url'] = $url;
            $sources[$nom] = ['content' => $fichier->body(), 'provenance' => $this->provenance($fichier->body(), $metadata)];
        }

        return $sources;
    }

    // ── Source europeenne ────────────────────────────────────────────

    /**
     * Service SQL de l'Agence europeenne pour l'environnement.
     *
     * Le registre CO2 europeen couvre ce que le Canada ignore : les
     * utilitaires legers de categorie N1, c'est-a-dire les pick-up. Hilux,
     * Ranger, D-Max, Navara, L200, Land Cruiser — l'ossature du parc
     * burkinabe, absente du marche nord-americain.
     *
     * La piste australienne, qui vend exactement ces vehicules, a ete
     * abandonnee : le Green Vehicle Guide ne publie aucun fichier libre et
     * renvoie vers un service tiers sur demande.
     */
    private const SQL_EEA = 'https://discodata.eea.europa.eu/sql';

    /**
     * Tables annuelles disponibles : co2cars_<annee>Pv<n>, ou n suit l'annee
     * de deux en deux. Seules les annees recentes sont publiees.
     */
    /**
     * Modeles que l'Europe n'immatricule pas sous leur nom de gamme.
     *
     * Le registre ne connait ni « Serie 3 » ni « Classe C » : il enregistre
     * « 320D XDRIVE » et « C 220 D ». Chaque entree donne les debuts de nom
     * qui designent la gamme, et sert dans les deux sens — pour interroger le
     * service, puis pour rattacher la cote au bon modele du catalogue.
     *
     * « C » est suivi d'une espace a dessein : sans elle, la Classe C
     * ramasserait les CLA et les CLS.
     */
    private const ALIAS_EEA = [
        'bmw|serie-1' => ['1'],
        'bmw|serie-2' => ['2'],
        'bmw|serie-3' => ['3'],
        'bmw|serie-4' => ['4'],
        'bmw|serie-5' => ['5'],
        'bmw|serie-6' => ['6'],
        'bmw|serie-7' => ['7'],
        'mercedes-benz|classe-a' => ['A '],
        'mercedes-benz|classe-b' => ['B '],
        'mercedes-benz|classe-c' => ['C '],
        'mercedes-benz|classe-e' => ['E '],
        'mercedes-benz|classe-s' => ['S '],
        'mercedes-benz|classe-v' => ['V-KLASSE', 'CLASSE V'],
        'mercedes-benz|ml-gle' => ['GLE', 'ML '],
        'mercedes-benz|gle' => ['GLE'],
        'mercedes-benz|glc' => ['GLC'],
        'mercedes-benz|gla' => ['GLA'],
    ];

    private const ANNEES_EEA = [
        2021 => 'co2cars_2021Pv23',
        2022 => 'co2cars_2022Pv25',
        2023 => 'co2cars_2023Pv27',
        2024 => 'co2cars_2024Pv29',
        2025 => 'co2cars_2025Pv31',
    ];

    private function importerEurope(): int
    {
        $modeles = $this->modelesSansCote();

        // L'option ne pose pas une requete libre : elle filtre la liste des
        // modeles du catalogue, pour que la marque accompagne toujours le nom.
        if ($filtres = $this->option('modele')) {
            $modeles = array_values(array_filter($modeles, function ($m) use ($filtres) {
                foreach ($filtres as $filtre) {
                    if (str_contains(Str::slug($m['modele']), Str::slug($filtre))) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($modeles === []) {
            $this->info('Aucun modele a chercher : le catalogue est deja couvert.');
            $this->terminerImport('partial', 0);

            return self::SUCCESS;
        }

        $this->line(sprintf('%d modeles a chercher en Europe.', count($modeles)));

        $trouves = 0;
        $ecrites = 0;

        foreach ($modeles as $modele) {
            $lignes = $this->chercherEnEurope($modele['marque'], $modele['modele']);
            $etiquette = $modele['marque'].' '.$modele['modele'];

            if ($lignes === []) {
                $this->line(sprintf('  %-28s —', $etiquette));

                continue;
            }

            $trouves++;
            $consos = array_column($lignes, 'consumption_combined');
            $this->line(sprintf(
                '  %-28s %d motorisations, %.1f a %.1f l/100',
                $etiquette, count($lignes), min($consos), max($consos)
            ));

            if (! $this->option('dry-run')) {
                $ecrites += $this->enregistrer($lignes);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d modeles sur %d trouves en Europe, %d motorisations enregistrees.',
            $trouves, count($modeles), $ecrites
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $this->terminerImport($this->failures !== [] && $ecrites === 0 ? 'failed' : 'partial', $ecrites);
        $this->relier();

        return $this->failures !== [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Les modeles du catalogue qu'aucune cote ne couvre encore, avec leur
     * marque — sans elle, « Ranger » ramenerait aussi bien un Ford qu'un Range
     * Rover.
     *
     * @return array<int, array{marque: string, modele: string}>
     */
    private function modelesSansCote(): array
    {
        $couverts = VehicleModel::query()
            ->whereIn('id', Motorisation::query()->whereNotNull('vehicle_model_id')->distinct()->pluck('vehicle_model_id'))
            ->pluck('name')
            ->map(fn ($n) => Str::slug($n))
            ->unique();

        $europe = VehicleModel::whereIn('id', Motorisation::where('source', 'eea')->whereNotNull('vehicle_model_id')->pluck('vehicle_model_id'))
            ->pluck('name')->map(fn ($name) => Str::slug($name));

        return VehicleModel::query()
            ->with('brand')
            ->get(['id', 'brand_id', 'name'])
            ->reject(fn (VehicleModel $m) => $couverts->contains(Str::slug($m->name)) && ! $europe->contains(Str::slug($m->name)))
            ->map(fn (VehicleModel $m) => ['marque' => $m->brand?->name ?? '', 'modele' => $m->name])
            ->unique(fn (array $m) => Str::slug($m['marque'].'-'.$m['modele']))
            ->values()
            ->all();
    }

    /**
     * Interroge le registre europeen pour un modele.
     *
     * Le service limite ce qu'il accepte — pas d'agregat, pas de table
     * systeme — donc on ramene les lignes brutes et on regroupe ici. Une
     * immatriculation par vehicule vendu : des milliers de lignes pour une
     * poignee de motorisations reelles.
     *
     * @return array<int, array<string, mixed>>
     */
    /** Les debuts de nom qui designent cette gamme dans le registre europeen. */
    private static function aliasEea(string $marque, string $modele): array
    {
        return self::ALIAS_EEA[Str::slug($marque).'|'.Str::slug($modele)] ?? [];
    }

    private function chercherEnEurope(string $marque, string $modele): array
    {
        $motifs = self::aliasEea($marque, $modele) ?: $this->motifs($modele);

        foreach ($motifs as $motif) {
            foreach (array_reverse(self::ANNEES_EEA, true) as $annee => $table) {
                $lignes = $this->interrogerEea($table, $marque, $motif, $annee);

                if ($lignes !== []) {
                    return $lignes;
                }
            }
        }

        return [];
    }

    /**
     * Motifs de recherche, du plus precis au plus large.
     *
     * « Land Cruiser Prado » ne se vend pas sous ce nom en Europe, ou il n'est
     * que « Land Cruiser » : on retente donc en retirant le dernier mot.
     *
     * Mais jamais jusqu'au mot unique quand le nom en compte plusieurs.
     * « Classe C » raccourci en « Classe » ramenait les Classe A, B et E, et
     * un essai sur « Toyota Hilux » reduit a « Toyota » a rendu une fourchette
     * de 3,8 a 5,0 l/100 : des hybrides, prises pour un pick-up diesel. Une
     * recherche trop large ne rend pas moins de resultats, elle en rend de
     * faux.
     */
    private function motifs(string $modele): array
    {
        $mots = preg_split('/\s+/', trim($modele));
        $minimum = count($mots) > 1 ? 2 : 1;
        $motifs = [];

        for ($n = count($mots); $n >= $minimum; $n--) {
            $candidat = implode(' ', array_slice($mots, 0, $n));

            // Deux caracteres suffisent : « C3 », « i20 », « RX », « X1 » sont
            // des noms de modeles entiers. Le seuil etait a trois, et ces
            // modeles n'etaient jamais interroges — la commande n'envoyait
            // aucune requete pour eux, sans rien dire. La requete filtre de
            // toute facon sur la marque, ce qui rend un motif court sur.
            if (mb_strlen($candidat) >= 2) {
                $motifs[] = $candidat;
            }
        }

        return $motifs;
    }

    /** @return array<int, array<string, mixed>> */
    private function interrogerEea(string $table, string $marque, string $motif, int $annee): array
    {
        $motifSql = str_replace("'", "''", mb_strtoupper($motif));

        // La marque du registre est ecrite de mille facons — « MERCEDES-BENZ »,
        // « MERCEDES-BENZ AG » — d'ou la comparaison sur le premier mot seul.
        //
        // Et sans ses accents : le registre enregistre « CITROEN », pas
        // « CITROËN ». Pour ce seul trema, la marque entiere ne remontait
        // rien — six modeles Citroen sont restes sans cote alors que la
        // donnee etait la.
        $premierMot = preg_split('/[\s-]+/', trim($marque))[0] ?? '';
        $marqueSql = str_replace("'", "''", mb_strtoupper(Str::ascii($premierMot)));

        $requete = 'SELECT TOP 400 Mk, Cn, Ct, Ft, [Ec (cm3)] AS cc, [Ep (KW)] AS kw, Fc '
            ."FROM [CO2Emission].[latest].[$table] "
            ."WHERE Mk LIKE '$marqueSql%' AND Cn LIKE '$motifSql%' AND Fc IS NOT NULL AND Fc > 0";

        // Soixante-trois modeles, soixante-trois requetes : un service
        // injoignable ne doit pas emporter l'import entier. On signale et on
        // passe au suivant.
        try {
            // Un import complet lance plusieurs centaines de requetes : la
            // resolution DNS lache par moments, et un echec isole laissait un
            // modele sans cote alors que la donnee existait. Trois essais
            // espaces, et une pause entre chaque appel pour ne pas marteler
            // un service public.
            $reponse = Http::timeout(120)
                ->retry(3, 800, throw: false)
                ->get(self::SQL_EEA, ['query' => $requete]);

            usleep(150_000);
        } catch (ConnectionException $e) {
            $this->warn('  service europeen injoignable : '.Str::limit($e->getMessage(), 90));
            $this->failures[] = $table.':'.$motif;

            return [];
        }

        if (! $reponse->successful() || $reponse->json('errors') !== null) {
            $this->failures[] = $table.':'.$motif;

            return [];
        }

        $results = $reponse->json('results') ?? [];
        $provenance = $this->provenance($reponse->body(), [
            'source_url' => self::SQL_EEA, 'resource_id' => $table,
            'query' => $requete, 'filters' => ['make' => $marque, 'model_pattern' => $motif],
            'method' => 'aggregation', 'cycle' => 'wltp', 'aggregation' => 'median',
            'sample_size' => count($results), 'sample_limit' => 400,
            'truncated' => count($results) >= 400, 'population_complete' => false,
            'registration_year' => $annee,
        ]);
        $this->importFiles[] = ['resource_id' => $table, 'query' => $requete,
            'file_sha256' => $provenance['file_sha256'], 'record_count' => count($results),
            'status' => count($results) >= 400 ? 'truncated_sample' : 'sample'];
        if (count($results) >= 400) {
            $this->warn('  Limite de 400 lignes atteinte : agregation sur un echantillon tronque.');
        }
        $groupes = [];
        $exemples = [];
        foreach ($results as $r) {
            // These observed fields define an aggregation group, not a certified version.
            $cle = implode('|', [$r['Mk'] ?? '', $r['Cn'] ?? '', $r['Ft'] ?? '', $r['cc'] ?? '', $r['kw'] ?? '']);
            $groupes[$cle][] = (float) $r['Fc'];
            $exemples[$cle] = $r;
        }

        $maintenant = now();
        $lignes = [];

        foreach ($groupes as $cle => $consos) {
            $r = $exemples[$cle];

            // Mediane plutot que moyenne : quelques immatriculations portent
            // des valeurs aberrantes, et une seule suffirait a tirer une
            // moyenne.
            sort($consos);
            $middle = intdiv(count($consos), 2);
            $mediane = count($consos) % 2 ? $consos[$middle] : ($consos[$middle - 1] + $consos[$middle]) / 2;

            $ligne = [
                'source' => 'eea',
                'cycle' => 'wltp',
                'market' => 'EU',
                'year_kind' => 'registration_year',
                'method' => 'aggregation',
                'power_kw' => is_numeric($r['kw'] ?? null) ? (float) $r['kw'] : null,
                'displacement_cc' => is_numeric($r['cc'] ?? null) ? (int) $r['cc'] : null,
                'hybrid_type' => null,
                'model_year' => $annee,
                'make_raw' => trim((string) $r['Mk']),
                'model_raw' => trim((string) $r['Cn']),
                'vehicle_class' => $r['Ct'] ?? null,       // M1 : voiture, N1 : utilitaire
                'engine_l' => $r['cc'] ? round(((int) $r['cc']) / 1000, 1) : null,
                'cylinders' => null,                    // absent du registre europeen
                'transmission_code' => null,
                'fuel_code' => $this->carburantEea($r['Ft'] ?? null),
                'consumption_city' => null,                    // le registre ne donne que le mixte
                'consumption_highway' => null,
                'consumption_combined' => round($mediane, 1),
                'co2_g_km' => null,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
            $details = array_merge($provenance, [
                'observation_count' => count($consos),
                'original_values' => ['example' => $r, 'consumptions_l_100km' => $consos],
            ]);
            $lignes[] = $this->qualifier($ligne, $details);
        }

        return $lignes;
    }

    /** Le vocabulaire europeen ramene aux codes deja utilises. */
    private function carburantEea(?string $ft): ?string
    {
        $f = mb_strtolower(trim((string) $ft));

        // A mixed fuel string identifies electrification, not whether it is
        // rechargeable. Preserve it as a separate, unresolved hybrid family.
        $hybride = str_contains($f, 'electric') && (str_contains($f, 'petrol') || str_contains($f, 'diesel'));

        return match (true) {
            $f === '' => null,
            $hybride => 'H',
            str_contains($f, 'diesel') => 'D',
            str_contains($f, 'petrol') => 'X',
            str_contains($f, 'lpg') => 'L',
            str_contains($f, 'e85') => 'E',
            str_contains($f, 'ng') => 'N',
            str_contains($f, 'electric') => 'B',
            default => null,
        };
    }

    // ── Analyse ──────────────────────────────────────────────────────

    /**
     * @param  array{lues: int, retenues: int, ignorees: int}  $total
     * @return array<int, array<string, mixed>>
     */
    private function analyser(string $contenu, array &$total, array $provenance): array
    {
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);

        // Les fichiers annuels sont publies en ISO-8859-1 — « A5 Coupé » y
        // tient l'accent sur un seul octet, que MySQL en utf8mb4 refuse avec
        // « Incorrect string value ». Le fichier 1995-2014, lui, est deja en
        // UTF-8 : on ne convertit donc que ce qui n'est pas valide, sinon on
        // abimerait les accents deja corrects.
        if (! mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        $flux = fopen('php://memory', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        $entete = fgetcsv($flux);
        if (! $entete) {
            fclose($flux);

            return [];
        }

        $colonne = [];
        foreach ($entete as $position => $nom) {
            $colonne[$this->cleColonne((string) $nom)] = $position;
        }

        $indispensables = ['modelyear', 'make', 'model'];
        foreach ($indispensables as $attendue) {
            if (! isset($colonne[$attendue])) {
                $this->warn('  En-tete inattendu, fichier ignore.');
                fclose($flux);

                return [];
            }
        }

        $lignes = [];
        $maintenant = now();

        $rowNumber = 1;
        while (($l = fgetcsv($flux)) !== false) {
            $rowNumber++;
            $total['lues']++;

            $annee = $l[$colonne['modelyear']] ?? '';
            if (! ctype_digit(trim((string) $annee))) {
                $total['ignorees']++;

                continue;   // ligne de notes en fin de fichier
            }

            $mixte = $this->nombre($l, $colonne, 'combinedl100km');
            if ($mixte === null) {
                $total['ignorees']++;

                continue;   // sans cote mixte, la ligne n'apprend rien
            }

            $total['retenues']++;
            $ligne = [
                'source' => 'nrcan',
                'cycle' => $provenance['cycle'],
                'market' => 'CA',
                'year_kind' => 'model_year',
                'method' => $provenance['method'],
                'power_kw' => null,
                'displacement_cc' => null,
                'hybrid_type' => null,
                'model_year' => (int) $annee,
                'make_raw' => trim((string) ($l[$colonne['make']] ?? '')),
                'model_raw' => trim((string) ($l[$colonne['model']] ?? '')),
                'vehicle_class' => $this->texte($l, $colonne, 'vehicleclass'),
                'engine_l' => $this->nombre($l, $colonne, 'enginesizel'),
                'cylinders' => $this->entier($l, $colonne, 'cylinders'),
                'transmission_code' => $this->texte($l, $colonne, 'transmission'),
                'fuel_code' => $this->texte($l, $colonne, 'fueltype'),
                'consumption_city' => $this->nombre($l, $colonne, 'cityl100km'),
                'consumption_highway' => $this->nombre($l, $colonne, 'highwayl100km'),
                'consumption_combined' => $mixte,
                'co2_g_km' => $this->entier($l, $colonne, 'co2emissionsgkm'),
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
            $details = array_merge($provenance, [
                'source_record' => $rowNumber, 'original_headers' => $entete,
                'original_values' => $l, 'original_unit' => 'L/100 km',
                'transformations' => ['decimal_normalization'],
            ]);
            $lignes[] = $this->qualifier($ligne, $details);
        }

        fclose($flux);

        return $lignes;
    }

    /** « City (L/100 km) » et « City (L/100km) » doivent designer la meme colonne. */
    private function cleColonne(string $nom): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($nom));
    }

    private function texte(array $ligne, array $colonne, string $cle): ?string
    {
        $valeur = isset($colonne[$cle]) ? trim((string) ($ligne[$colonne[$cle]] ?? '')) : '';

        return ($valeur === '' || strcasecmp($valeur, 'n/a') === 0) ? null : $valeur;
    }

    private function nombre(array $ligne, array $colonne, string $cle): ?float
    {
        $valeur = $this->texte($ligne, $colonne, $cle);

        return ($valeur === null || ! is_numeric(str_replace(',', '.', $valeur)))
            ? null
            : (float) str_replace(',', '.', $valeur);
    }

    private function entier(array $ligne, array $colonne, string $cle): ?int
    {
        $valeur = $this->nombre($ligne, $colonne, $cle);

        return $valeur === null ? null : (int) round($valeur);
    }

    // ── Rattachement au catalogue ────────────────────────────────────

    /**
     * Relie les cotes importees aux marques et modeles du catalogue.
     *
     * La source ecrit « Camry Hybrid LE », « RAV4 AWD », « Land Cruiser 150 » :
     * le nom du modele est un prefixe, suivi de la variante. On retient donc le
     * modele connu le plus long qui prefixe le libelle — sans quoi « RAV4 » et
     * « RAV4 Limited AWD » se rattacheraient au petit bonheur.
     */
    private function relier(): int
    {
        $marques = Brand::query()->get(['id', 'name'])
            ->keyBy(fn (Brand $m) => Str::slug($m->name));

        if ($marques->isEmpty()) {
            $this->warn('Aucune marque au catalogue : rien a rattacher.');

            return self::SUCCESS;
        }

        $modelesParMarque = VehicleModel::query()
            ->get(['id', 'brand_id', 'name', 'production_start', 'production_end'])
            ->groupBy('brand_id');

        $rattachees = 0;
        $marquesVues = [];

        Motorisation::query()->orderBy('id')->chunkById(1000, function ($lot) use (
            $marques, $modelesParMarque, &$rattachees, &$marquesVues
        ) {
            foreach ($lot as $cote) {
                if ($cote->verified_at !== null || $cote->trim_id !== null || $cote->trim_application_id !== null) {
                    continue;
                }
                $marque = null;
                foreach (self::slugsMarque($cote->make_raw) as $slug) {
                    if ($marque = $marques->get($slug)) {
                        break;
                    }
                }
                if (! $marque) {
                    continue;
                }
                $marquesVues[$marque->id] = true;

                $modeleId = $this->modeleLePlusProche(
                    $cote->model_raw,
                    $cote->year_kind === 'registration_year' ? null : $cote->model_year,
                    $modelesParMarque->get($marque->id) ?? collect(),
                    $marque->name,
                );

                $cote->timestamps = false;
                $cote->forceFill([
                    'brand_id' => $marque->id,
                    'vehicle_model_id' => $modeleId,
                ])->saveQuietly();

                if ($modeleId !== null) {
                    $rattachees++;
                }
            }
        });

        $total = Motorisation::query()->count();
        $this->newLine();
        $this->line(sprintf(
            'Rattachement : %d cotes sur %d reliees a un modele du catalogue (%d marques reconnues).',
            $rattachees, $total, count($marquesVues)
        ));

        $idsCouverts = Motorisation::query()->whereNotNull('vehicle_model_id')->distinct()->pluck('vehicle_model_id');

        $this->line(sprintf(
            'Catalogue : %d generations sur %d disposent d\'au moins une cote.',
            VehicleModel::query()->whereIn('id', $idsCouverts)->count(),
            VehicleModel::query()->count(),
        ));

        // Le decompte par nom de modele parle mieux que celui par generation :
        // c'est ce que l'utilisateur cherche dans l'application.
        $nomsCouverts = VehicleModel::query()->whereIn('id', $idsCouverts)
            ->distinct()->pluck('name')->map(fn ($n) => Str::slug($n))->unique();
        $nomsTotal = VehicleModel::query()->distinct()->pluck('name')->map(fn ($n) => Str::slug($n))->unique();

        $this->line(sprintf(
            '           %d noms de modeles sur %d.',
            $nomsCouverts->count(), $nomsTotal->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Le modele du catalogue qui correspond a ce libelle et a ce millesime.
     *
     * Le catalogue tient une ligne par generation : une Corolla y figure
     * autant de fois qu'elle a connu de refontes. Sans l'annee, toutes les
     * cotes de 1995 a 2026 se seraient rattachees a la meme generation, et les
     * autres seraient apparues « sans donnee » alors que la donnee existait.
     *
     * @param  Collection<int, VehicleModel>  $modeles
     */
    private function modeleLePlusProche(string $libelle, ?int $annee, $modeles, string $marque = ''): ?int
    {
        $cible = Str::slug($libelle);
        $brut = mb_strtoupper(trim($libelle));
        $candidats = [];
        $longueur = 0;

        foreach ($modeles as $modele) {
            $nom = Str::slug($modele->name);
            if ($nom === '') {
                continue;
            }

            // Deux facons de reconnaitre un modele, et elles ne se mesurent
            // pas pareil. Par le nom : « RAV4 AWD » commence par « RAV4 ».
            // Par la table de correspondance : « 320D XDRIVE » ne ressemble
            // en rien a « Serie 3 », seul l'alias fait le lien.
            $score = null;

            if ($cible === $nom || str_starts_with($cible, $nom.'-')) {
                $score = strlen($nom);
            }

            foreach (self::aliasEea($marque, $modele->name) as $prefixe) {
                if (str_starts_with($brut, mb_strtoupper($prefixe))) {
                    // La longueur du prefixe reconnu, pas celle du nom du
                    // modele : sinon « ML / GLE » l'emportait sur « GLE » par
                    // la seule longueur de son nom, et les cotes recentes
                    // allaient a la generation arretee.
                    $score = max($score ?? 0, strlen($prefixe));
                }
            }

            if ($score === null || $score < $longueur) {
                continue;
            }

            // Une reconnaissance plus precise l'emporte : on repart de zero.
            if ($score > $longueur) {
                $candidats = [];
                $longueur = $score;
            }
            $candidats[] = $modele;
        }

        if ($candidats === []) {
            return null;
        }

        // A registration year is not a model year: do not choose a generation
        // from it. A single matching generation remains an unverified name link.
        if ($annee === null) {
            return count($candidats) === 1 ? $candidats[0]->id : null;
        }

        // La generation produite cette annee-la, et elle seule.
        //
        // Ce controle ne s'appliquait qu'a partir de deux candidats : un
        // modele unique etait accepte sans regarder ses annees. Neuf cotes
        // europeennes de 2023 se sont ainsi posees sur un « ML / GLE » arrete
        // en 2018, pendant que le « GLE » de 2018 a aujourd'hui restait vide.
        $sansBornes = [];
        $compatibles = [];

        foreach ($candidats as $modele) {
            $debut = $modele->production_start;
            $fin = $modele->production_end;

            if ($debut === null && $fin === null) {
                $sansBornes[] = $modele;

                continue;
            }
            if (($debut === null || $annee >= $debut) && ($fin === null || $annee <= $fin)) {
                $compatibles[] = $modele;
            }
        }

        // Generations can overlap between markets (Cerato TD/YD in 2012–13)
        // or within a transition year. Query order must not choose a rating's
        // generation. Preserve ambiguity until a more precise identity exists.
        if ($compatibles !== []) {
            return count($compatibles) === 1 ? $compatibles[0]->id : null;
        }

        return count($sansBornes) === 1 ? $sansBornes[0]->id : null;
    }
}
