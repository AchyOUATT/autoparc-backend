<?php

namespace App\Console\Commands;

use App\Models\Trim;
use App\Models\TrimApplication;
use App\Models\VehicleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

/** Importe un lot documenté ; la vérification des sources reste humaine. */
class ImportTrims extends Command
{
    protected $signature = 'catalog:import-trims
                            {file : Fichier JSON du lot vérifié}
                            {--dry-run : Valider et afficher le bilan sans écrire}
                            {--update-existing : Autoriser les corrections de code et description existants}
                            {--allow-production-year-mismatch : Accepter un millésime documenté hors des années de production du modèle}';

    protected $description = 'Importe des finitions avec marché, millésimes et sources, sans déduire leur moteur';

    private array $warnings = [];

    public function handle(): int
    {
        $this->warnings = [];

        try {
            $document = $this->readDocument();
            $this->validateDocument($document);

            [$trims, $applications] = DB::transaction(function () use ($document) {
                [$trims, $applications] = $this->plan($document['entries']);

                if (! $this->option('dry-run')) {
                    $this->persist($trims, $applications);
                }

                return [$trims, $applications];
            });
        } catch (JsonException $exception) {
            $this->error('JSON invalide : '.$exception->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
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

        if ($this->option('dry-run')) {
            $this->warn('Simulation : aucune écriture.');
        }

        foreach (array_unique($this->warnings) as $warning) {
            $this->warn($warning);
        }

        $this->summary('Finitions', $trims);
        $this->summary('Applications', $applications);
        $this->line('Sources déclarées dans le fichier ; leur contenu n’a pas été vérifié en ligne par cette commande.');

        return self::SUCCESS;
    }

    private function readDocument(): array
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Fichier absent, illisible ou supérieur à 5 Mio.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException('Lecture du fichier impossible.');
        }

        $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('Le lot doit être un objet JSON contenant version et entries.');
        }

        return $document;
    }

    private function validateDocument(array $document): void
    {
        $slug = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'];
        $year = ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)];
        $date = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];

        $validator = Validator::make($document, [
            'version' => ['required', 'integer', 'in:1'],
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*' => ['required', 'array:brand,model_slug,market,model_year_from,model_year_to,trims,sources,verified_on'],
            'entries.*.brand' => $slug,
            'entries.*.model_slug' => $slug,
            'entries.*.market' => ['required', 'string', 'max:20', 'regex:/^[A-Z][A-Z0-9_-]{1,19}$/D'],
            'entries.*.model_year_from' => $year,
            'entries.*.model_year_to' => $year,
            'entries.*.verified_on' => $date,
            'entries.*.trims' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.trims.*' => ['required', 'array:name,code,description'],
            'entries.*.trims.*.name' => ['required', 'string', 'max:60'],
            'entries.*.trims.*.code' => ['nullable', 'string', 'max:30'],
            'entries.*.trims.*.description' => ['nullable', 'string', 'max:10000'],
            'entries.*.sources' => ['required', 'array', 'min:1', 'max:20'],
            'entries.*.sources.*' => ['required', 'array:url,title,consulted_on'],
            'entries.*.sources.*.url' => ['required', 'string', 'max:2048', 'url:https', function ($attribute, $value, $fail) {
                $parts = is_string($value) ? parse_url($value) : false;

                if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                    $fail('Une source doit avoir une URL HTTPS sans identifiants intégrés.');
                }
            }],
            'entries.*.sources.*.title' => ['required', 'string', 'max:255'],
            'entries.*.sources.*.consulted_on' => $date,
        ]);

        $validator->after(function ($validator) use ($document) {
            if (array_diff(array_keys($document), ['version', 'entries']) !== [] || ($document['version'] ?? null) !== 1) {
                $validator->errors()->add('version', 'Schéma attendu : version entière 1 et entries, sans autre champ.');
            }

            if (! is_array($document['entries'] ?? null) || ! array_is_list($document['entries'])) {
                $validator->errors()->add('entries', 'Une liste JSON est requise.');

                return;
            }

            foreach ($document['entries'] as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                foreach (['model_year_from', 'model_year_to'] as $field) {
                    if (! is_int($entry[$field] ?? null)) {
                        $validator->errors()->add("entries.$index.$field", 'Une année doit être un entier JSON.');
                    }
                }

                if (($entry['model_year_from'] ?? 0) > ($entry['model_year_to'] ?? 0)) {
                    $validator->errors()->add("entries.$index.model_year_to", 'La fin doit être supérieure ou égale au début.');
                }

                foreach (['trims', 'sources'] as $field) {
                    if (! is_array($entry[$field] ?? null) || ! array_is_list($entry[$field])) {
                        $validator->errors()->add("entries.$index.$field", 'Une liste JSON est requise.');
                    }
                }

                $names = [];

                foreach (is_array($entry['trims'] ?? null) ? $entry['trims'] : [] as $trimIndex => $trim) {
                    $name = is_array($trim) ? ($trim['name'] ?? null) : null;

                    if (! is_string($name)) {
                        continue;
                    }

                    if ($name !== trim($name) || in_array(mb_strtolower($name), $names, true)) {
                        $validator->errors()->add("entries.$index.trims.$trimIndex.name", 'Nom dupliqué ou entouré d’espaces.');
                    }

                    $names[] = mb_strtolower($name);
                }

                foreach (is_array($entry['sources'] ?? null) ? $entry['sources'] : [] as $sourceIndex => $source) {
                    $consulted = is_array($source) ? ($source['consulted_on'] ?? null) : null;

                    if (is_string($consulted) && is_string($entry['verified_on'] ?? null) && $consulted > $entry['verified_on']) {
                        $validator->errors()->add("entries.$index.sources.$sourceIndex.consulted_on", 'La consultation ne peut pas être postérieure à la vérification.');
                    }
                }
            }
        });

        $validator->validate();
    }

    /** Résout tout le lot avant la première écriture, y compris les conflits. */
    private function plan(array $entries): array
    {
        $trims = [];
        $applications = [];
        $errors = [];

        foreach ($entries as $index => $entry) {
            $model = VehicleModel::where('slug', $entry['model_slug'])
                ->whereHas('brand', fn ($query) => $query->where('slug', $entry['brand']))
                ->first();

            if ($model === null) {
                $errors["entries.$index.model_slug"][] = 'Modèle absent de cette marque ; le lot ne crée pas de modèles.';

                continue;
            }

            if (($model->production_start !== null && $entry['model_year_from'] < $model->production_start)
                || ($model->production_end !== null && $entry['model_year_to'] > $model->production_end)) {
                if (! $this->option('allow-production-year-mismatch')) {
                    $errors["entries.$index.model_year_from"][] = 'Millésime hors des années de production connues. Ces notions peuvent différer : vérifier la source puis utiliser --allow-production-year-mismatch si nécessaire.';

                    continue;
                }

                $this->warnings[] = "{$entry['brand']}/{$entry['model_slug']} : millésime hors des années de production, dérogation documentaire explicite.";
            }

            foreach ($entry['trims'] as $trimIndex => $definition) {
                $key = $model->id.'|'.$definition['name'];
                $attributes = array_intersect_key($definition, array_flip(['code', 'description']));

                if (! isset($trims[$key])) {
                    $record = Trim::where('vehicle_model_id', $model->id)->where('name', $definition['name'])->lockForUpdate()->first();
                    $record ??= new Trim(['vehicle_model_id' => $model->id, 'name' => $definition['name'], 'rank' => 0]);
                    $trims[$key] = ['record' => $record, 'new' => ! $record->exists, 'changed' => false, 'attributes' => []];
                }

                foreach ($attributes as $field => $value) {
                    if (array_key_exists($field, $trims[$key]['attributes']) && $trims[$key]['attributes'][$field] !== $value) {
                        $errors["entries.$index.trims.$trimIndex.$field"][] = 'Deux définitions contradictoires pour la même finition dans le lot.';
                    }

                    $trims[$key]['attributes'][$field] = $value;
                }

                $record = $trims[$key]['record'];
                $record->fill($trims[$key]['attributes']);
                $trims[$key]['changed'] = $trims[$key]['changed'] || $record->isDirty();

                if ($record->exists && $record->isDirty(array_keys($attributes)) && ! $this->option('update-existing')) {
                    $errors["entries.$index.trims.$trimIndex"][] = 'Correction d’une finition existante : utiliser --update-existing après examen.';
                }

                $applicationKey = json_encode([$key, $entry['market'], $entry['model_year_from'], $entry['model_year_to']], JSON_THROW_ON_ERROR);
                $applicationAttributes = [
                    'market' => $entry['market'],
                    'model_year_from' => $entry['model_year_from'],
                    'model_year_to' => $entry['model_year_to'],
                    'sources' => $entry['sources'],
                    'verified_at' => $entry['verified_on'].' 00:00:00',
                ];

                if (isset($applications[$applicationKey])) {
                    if ($applications[$applicationKey]['attributes'] !== $applicationAttributes) {
                        $errors["entries.$index"][] = 'Sources ou vérification contradictoires pour une même application.';
                    }

                    continue;
                }

                $application = $record->exists
                    ? TrimApplication::where('trim_id', $record->id)
                        ->where('market', $entry['market'])
                        ->where('model_year_from', $entry['model_year_from'])
                        ->where('model_year_to', $entry['model_year_to'])->lockForUpdate()->first()
                    : null;
                $application ??= new TrimApplication;
                $new = ! $application->exists;

                if (! $new && $entry['verified_on'] < $application->verified_at->toDateString()) {
                    $errors["entries.$index.verified_on"][] = 'Une vérification plus récente existe déjà ; un ancien lot ne peut pas la remplacer.';
                }

                $application->fill($applicationAttributes);
                $changed = $application->isDirty();
                $applications[$applicationKey] = ['record' => $application, 'trim_key' => $key, 'new' => $new, 'changed' => $changed, 'attributes' => $applicationAttributes];
                $trims[$key]['changed'] = $trims[$key]['changed'] || $record->isDirty() || $changed;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$trims, $applications];
    }

    private function persist(array $trims, array $applications): void
    {
        foreach ($trims as $plan) {
            if ($plan['record']->isDirty()) {
                $plan['record']->save();
            }
        }

        foreach ($applications as $plan) {
            if ($plan['changed']) {
                $plan['record']->trim_id = $trims[$plan['trim_key']]['record']->id;
                $plan['record']->save();
            }
        }

        foreach ($trims as $plan) {
            if (! $plan['new'] && $plan['changed'] && ! $plan['record']->wasChanged()) {
                // Le contexte imbriqué a changé : réveiller aussi le delta des trims.
                $plan['record']->touch();
            }
        }
    }

    private function summary(string $label, array $plans): void
    {
        $new = count(array_filter($plans, fn ($plan) => $plan['new']));
        $changed = count(array_filter($plans, fn ($plan) => ! $plan['new'] && $plan['changed']));
        $unchanged = count($plans) - $new - $changed;
        $this->line("$label : $new ajout(s), $changed mise(s) à jour, $unchanged inchangée(s).");
    }
}
