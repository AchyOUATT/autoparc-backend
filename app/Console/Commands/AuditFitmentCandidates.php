<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

/** Reads proposed applications without publishing or certifying any fitment. */
class AuditFitmentCandidates extends Command
{
    protected $signature = 'catalog:audit-fitment-candidates
                            {file? : Fichier JSON des applications en attente}
                            {--format=table : Format de sortie : table ou json}
                            {--fail-on-blocked : Retourner un échec si une application reste en attente}';

    protected $description = 'Audite les applications de pièces proposées, sans modifier le catalogue';

    public function handle(): int
    {
        if (! in_array($this->option('format'), ['table', 'json'], true)) {
            $this->error('--format accepte table ou json.');

            return self::FAILURE;
        }

        try {
            $document = $this->readDocument();
            $this->validateDocument($document);
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error($field.' : '.$message);
                }
            }

            return self::FAILURE;
        }

        $models = DB::table('vehicle_models')
            ->join('brands', 'brands.id', '=', 'vehicle_models.brand_id')
            ->where('brands.slug', $document['brand_slug'])
            ->whereIn('vehicle_models.slug', array_column($document['entries'], 'model_slug'))
            ->get(['vehicle_models.id', 'vehicle_models.slug'])
            ->keyBy('slug');

        $entries = [];
        foreach ($document['entries'] as $entry) {
            $model = $models->get($entry['model_slug']);
            $issues = [];
            if ($model === null) {
                $issues[] = $this->issue('missing_model', 'Génération absente du référentiel de cette marque.');
            }
            if ($entry['sources'] === [] || $entry['verified_on'] === null) {
                $issues[] = $this->issue('missing_evidence', 'Sources consultées et vérification documentaire à compléter.');
            }

            $source = $entry['claimed_source'];
            if (($source['year_from'] !== null && $entry['proposed_year_from'] < $source['year_from'])
                || ($source['year_to'] !== null && $entry['proposed_year_to'] > $source['year_to'])) {
                $issues[] = $this->issue('source_bounds_exceeded', 'Les années proposées dépassent les bornes citées dans la note source.');
            }
            foreach ($entry['review_notes'] as $note) {
                $issues[] = $this->issue('review_note', $note);
            }

            // A supplied URL or date is metadata, not proof that an automotive
            // application has been read, resolved by market, or certified.
            $issues[] = $this->issue('pending_review', 'Application en attente ; aucune compatibilité publiée par cet audit.');
            $entries[] = [
                ...$entry,
                'current_model_id' => $model === null ? null : (int) $model->id,
                'publication_status' => 'pending_review',
                'issues' => $issues,
            ];
        }

        $missing = array_filter($entries, fn (array $entry) => $entry['current_model_id'] === null);
        $missingSlugs = array_values(array_unique(array_column($missing, 'model_slug')));
        sort($missingSlugs);
        $report = [
            'version' => 1,
            'status' => 'pending_review',
            'brand_slug' => $document['brand_slug'],
            'part_sku' => $document['part_sku'],
            'summary' => [
                'total_entries' => count($entries),
                'matched_entries' => count($entries) - count($missing),
                'missing_model_entries' => count($missing),
                'missing_model_slugs' => $missingSlugs,
                'source_bounds_exceeded_entries' => count(array_filter($entries, fn (array $entry) => in_array('source_bounds_exceeded', array_column($entry['issues'], 'code'), true))),
                'blocked_entries' => count($entries),
            ],
            'entries' => $entries,
        ];

        if ($this->option('format') === 'json') {
            $this->output->write(
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
                false,
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->line(sprintf('%s : %d applications proposées, %d en attente, %d lignes sans génération.',
                $document['part_sku'], count($entries), count($entries), count($missing)));
            $this->table(['Modèle', 'Moteur', 'Années proposées', 'Modèle trouvé', 'Points à vérifier'], array_map(fn (array $entry) => [
                $entry['model_slug'], $entry['engine_code'],
                $entry['proposed_year_from'].'–'.$entry['proposed_year_to'],
                $entry['current_model_id'] === null ? 'Non' : 'Oui',
                implode('; ', array_column($entry['issues'], 'message')),
            ], $entries));
            $this->line('Lecture seule : aucune application importée. Les métadonnées de source ne certifient pas une compatibilité.');
        }

        return $this->option('fail-on-blocked') && $entries !== [] ? self::FAILURE : self::SUCCESS;
    }

    private function issue(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    private function readDocument(): array
    {
        $path = $this->argument('file') ?? database_path('catalogue/fitment-candidates/toyota-90915-10001.json');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Fichier absent, illisible ou supérieur à 5 Mio.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException('Lecture du fichier impossible.');
        }
        $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('Le document doit être un objet JSON de propositions en attente.');
        }

        return $document;
    }

    private function validateDocument(array $document): void
    {
        $year = ['integer', 'min:1950', 'max:'.(now()->year + 1)];
        $date = ['date_format:Y-m-d', 'before_or_equal:today'];
        $slug = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'];
        $validator = Validator::make($document, [
            'version' => ['required', 'integer', 'in:1'],
            'status' => ['required', 'in:pending_review'],
            'brand_slug' => $slug,
            'part_sku' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/D'],
            'origin' => ['sometimes', 'array:file,received_on'],
            'origin.file' => ['required_with:origin', 'string', 'max:255'],
            'origin.received_on' => ['required_with:origin', ...$date],
            'notes' => ['sometimes', 'array', 'max:100'],
            'notes.*' => ['string', 'max:10000'],
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*' => ['required', 'array:model_slug,engine_code,proposed_year_from,proposed_year_to,claimed_market,claimed_source,sources,verified_on,review_notes'],
            'entries.*.model_slug' => $slug,
            'entries.*.engine_code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9-]+$/D'],
            'entries.*.proposed_year_from' => ['required', ...$year],
            'entries.*.proposed_year_to' => ['required', ...$year],
            'entries.*.claimed_market' => ['present', 'nullable', 'string', 'regex:/^[A-Z][A-Z0-9_-]{1,19}$/D'],
            'entries.*.claimed_source' => ['required', 'array:label,year_from,year_to,note'],
            'entries.*.claimed_source.label' => ['required', 'string', 'max:255'],
            'entries.*.claimed_source.note' => ['required', 'string', 'max:10000'],
            'entries.*.claimed_source.year_from' => ['present', 'nullable', ...$year],
            'entries.*.claimed_source.year_to' => ['present', 'nullable', ...$year],
            'entries.*.sources' => ['present', 'array', 'max:20'],
            'entries.*.sources.*' => ['array:url,title,consulted_on'],
            'entries.*.sources.*.url' => ['required', 'string', 'max:2048', 'url:https', function ($attribute, $value, $fail) {
                $url = is_string($value) ? parse_url($value) : false;
                if ($url === false || ($url['scheme'] ?? null) !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
                    $fail('Une source doit avoir une URL HTTPS sans identifiants intégrés.');
                }
            }],
            'entries.*.sources.*.title' => ['required', 'string', 'max:255'],
            'entries.*.sources.*.consulted_on' => ['required', ...$date],
            'entries.*.verified_on' => ['present', 'nullable', ...$date],
            'entries.*.review_notes' => ['present', 'array', 'max:100'],
            'entries.*.review_notes.*' => ['string', 'max:10000'],
        ]);
        $validator->after(function ($validator) use ($document): void {
            if (array_diff(array_keys($document), ['version', 'status', 'brand_slug', 'part_sku', 'origin', 'notes', 'entries']) !== [] || ($document['version'] ?? null) !== 1) {
                $validator->errors()->add('version', 'Schéma attendu : version entière 1, sans champ inconnu.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->requireList($validator, $document['entries'], 'entries');
            if (isset($document['notes'])) {
                $this->requireList($validator, $document['notes'], 'notes');
            }
            $seen = [];
            foreach ($document['entries'] as $index => $entry) {
                foreach (['sources', 'review_notes'] as $field) {
                    $this->requireList($validator, $entry[$field], "entries.$index.$field");
                }
                foreach (['proposed_year_from', 'proposed_year_to'] as $field) {
                    if (! is_int($entry[$field])) {
                        $validator->errors()->add("entries.$index.$field", 'Une année doit être un entier JSON.');
                    }
                }
                if ($entry['proposed_year_from'] > $entry['proposed_year_to']) {
                    $validator->errors()->add("entries.$index.proposed_year_to", 'La fin doit être supérieure ou égale au début.');
                }
                $source = $entry['claimed_source'];
                foreach (['year_from', 'year_to'] as $field) {
                    if ($source[$field] !== null && ! is_int($source[$field])) {
                        $validator->errors()->add("entries.$index.claimed_source.$field", 'Une année doit être un entier JSON ou null.');
                    }
                }
                if ($source['year_from'] !== null && $source['year_to'] !== null && $source['year_from'] > $source['year_to']) {
                    $validator->errors()->add("entries.$index.claimed_source.year_to", 'La fin citée doit être supérieure ou égale au début.');
                }
                foreach ($entry['sources'] as $sourceIndex => $evidence) {
                    if ($entry['verified_on'] !== null && $entry['verified_on'] < $evidence['consulted_on']) {
                        $validator->errors()->add("entries.$index.sources.$sourceIndex.consulted_on", 'La vérification ne peut précéder la consultation.');
                    }
                }
                $identity = json_encode([$entry['model_slug'], $entry['engine_code'], $entry['proposed_year_from'], $entry['proposed_year_to'], $entry['claimed_market']], JSON_THROW_ON_ERROR);
                if (isset($seen[$identity])) {
                    $validator->errors()->add("entries.$index", 'Application proposée en double.');
                }
                $seen[$identity] = true;
            }
        });
        $validator->validate();
    }

    private function requireList($validator, array $value, string $field): void
    {
        if (! array_is_list($value)) {
            $validator->errors()->add($field, 'Une liste JSON est requise.');
        }
    }
}
