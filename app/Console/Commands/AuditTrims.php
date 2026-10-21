<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

class AuditTrims extends Command
{
    protected $signature = 'catalog:audit-trims
                            {--format=table : Format de sortie : table, json ou csv}
                            {--json : Alias de --format=json}
                            {--only-missing : Ne montrer que les modeles sans aucune finition}
                            {--pilot : Limiter la selection aux entrees du lot pilote}';

    protected $description = 'Mesure en lecture seule les finitions manquantes et leur documentation par modele';

    public function handle(): int
    {
        $format = $this->option('json') ? 'json' : $this->option('format');

        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error('--format accepte table, json ou csv.');

            return self::FAILURE;
        }

        $targets = null;

        if ($this->option('pilot')) {
            try {
                $backlog = json_decode(
                    file_get_contents(database_path('catalogue/trims-pilot.json')),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                $this->error('Le lot pilote ne contient pas un JSON valide : '.$exception->getMessage());

                return self::FAILURE;
            }

            $targets = collect($backlog['entries'])
                ->map(fn (array $entry) => $entry['brand'].'|'.$entry['model_slug']);
        }

        $trimCounts = DB::table('trims')
            ->select('vehicle_model_id')
            ->selectRaw('COUNT(*) as trims_count')
            ->groupBy('vehicle_model_id');

        // Plusieurs marches ou periodes documentes pour la meme finition ne
        // deviennent pas plusieurs finitions. Cela ne certifie pas la gamme.
        $documentedCounts = DB::table('trims')
            ->join('trim_applications', 'trim_applications.trim_id', '=', 'trims.id')
            ->whereNotNull('trim_applications.verified_at')
            ->select('trims.vehicle_model_id')
            ->selectRaw('COUNT(DISTINCT trims.id) as documented_trims_count')
            ->groupBy('trims.vehicle_model_id');

        $models = DB::table('vehicle_models')
            ->join('brands', 'brands.id', '=', 'vehicle_models.brand_id')
            ->leftJoinSub($trimCounts, 'trim_counts', 'trim_counts.vehicle_model_id', '=', 'vehicle_models.id')
            ->leftJoinSub($documentedCounts, 'documented_counts', 'documented_counts.vehicle_model_id', '=', 'vehicle_models.id')
            ->select([
                'brands.slug as brand_slug',
                'brands.name as brand_name',
                'vehicle_models.id as model_id',
                'vehicle_models.slug as model_slug',
                'vehicle_models.name as model_name',
                'vehicle_models.generation',
                'vehicle_models.production_start',
                'vehicle_models.production_end',
            ])
            ->selectRaw('COALESCE(trim_counts.trims_count, 0) as trims_count')
            ->selectRaw('COALESCE(documented_counts.documented_trims_count, 0) as documented_trims_count')
            ->orderBy('brands.name')
            ->orderBy('vehicle_models.name')
            ->orderBy('vehicle_models.production_start')
            ->orderBy('vehicle_models.id')
            ->get()
            ->map(function (object $model): array {
                $row = (array) $model;

                foreach (['model_id', 'trims_count', 'documented_trims_count'] as $field) {
                    $row[$field] = (int) $row[$field];
                }

                foreach (['production_start', 'production_end'] as $field) {
                    $row[$field] = $row[$field] === null ? null : (int) $row[$field];
                }

                $row['status'] = match (true) {
                    $row['documented_trims_count'] > 0 => 'documente_partiellement',
                    $row['trims_count'] > 0 => 'finitions_non_documentees',
                    default => 'a_documenter',
                };

                return $row;
            });

        $selected = $models
            ->when($targets !== null, fn (Collection $rows) => $rows->filter(
                fn (array $row) => $targets->contains($row['brand_slug'].'|'.$row['model_slug']),
            ))
            ->when($this->option('only-missing'), fn (Collection $rows) => $rows->where('trims_count', 0))
            ->values();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'only_missing' => (bool) $this->option('only-missing'),
                'pilot' => (bool) $this->option('pilot'),
            ],
            'summary' => [
                'catalogue' => array_merge([
                    'brands_total' => DB::table('brands')->count(),
                    'trims_total' => DB::table('trims')->count(),
                ], $this->counts($models)),
                'selection' => $this->counts($selected),
            ],
            'models' => $selected->all(),
        ];

        if ($targets !== null) {
            $existing = $models->map(fn (array $row) => $row['brand_slug'].'|'.$row['model_slug']);
            $report['scope']['pilot_targets_not_found'] = $targets->diff($existing)->values()->all();
        }

        if ($format === 'json') {
            $this->output->write(
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
                false,
                OutputInterface::OUTPUT_RAW,
            );
        } elseif ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            $fields = [
                'brand_slug', 'brand_name', 'model_id', 'model_slug', 'model_name',
                'generation', 'production_start', 'production_end', 'trims_count',
                'documented_trims_count', 'status',
            ];
            fputcsv($stream, $fields, escape: '');

            foreach ($selected as $row) {
                fputcsv($stream, array_map(fn (string $field) => $row[$field], $fields), escape: '');
            }

            rewind($stream);
            $this->output->write(stream_get_contents($stream), false, OutputInterface::OUTPUT_RAW);
            fclose($stream);
        } else {
            $total = $report['summary']['catalogue'];
            $this->line(sprintf(
                '%d marques, %d entrees modele/generation, %d sans finition ; %d entrees selectionnees.',
                $total['brands_total'], $total['models_total'], $total['models_without_trims'], $selected->count(),
            ));
            $this->line('Une documentation partielle ne certifie jamais une gamme complete.');
            $this->table(
                ['Marque', 'Modele', 'Generation', 'De', 'A', 'Finitions', 'Documentees', 'Etat'],
                $selected->map(fn (array $row) => [
                    $row['brand_name'], $row['model_name'], $row['generation'],
                    $row['production_start'], $row['production_end'], $row['trims_count'],
                    $row['documented_trims_count'], $row['status'],
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function counts(Collection $models): array
    {
        return [
            'models_total' => $models->count(),
            'models_with_trims' => $models->where('trims_count', '>', 0)->count(),
            'models_without_trims' => $models->where('trims_count', 0)->count(),
            'models_with_documented_trims' => $models->where('documented_trims_count', '>', 0)->count(),
        ];
    }
}
