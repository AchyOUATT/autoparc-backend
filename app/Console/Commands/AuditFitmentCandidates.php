<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Part;
use App\Models\VehicleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Lit des compatibilites PROPOSEES sans rien publier ni certifier.
 *
 * Lecture seule : aucune ecriture en base. `model_exists` signifie seulement
 * que le slug designe un modele du referentiel, PAS que la piece lui est
 * compatible. Les candidats restent « a verifier ».
 */
class AuditFitmentCandidates extends Command
{
    protected $signature = 'catalog:audit-fitment-candidates
                            {sku? : SKU a auditer, tous si omis}
                            {--json : Sortie JSON}';

    protected $description = 'Audite les compatibilites proposees sans les publier (lecture seule)';

    private const SKU_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/';

    public function handle(): int
    {
        $dir = database_path('catalogue/fitment-candidates');

        if (! File::isDirectory($dir)) {
            $this->error("Dossier introuvable : {$dir}");

            return self::FAILURE;
        }

        $sku = $this->argument('sku');

        if ($sku !== null) {
            // Le SKU devient un nom de fichier : refuser toute traversee.
            if (! preg_match(self::SKU_PATTERN, $sku) || str_contains($sku, '..')) {
                $this->error("SKU invalide : {$sku}");

                return self::FAILURE;
            }

            $file = "{$dir}/{$sku}.json";
            if (! File::exists($file)) {
                $this->error("Aucun fichier candidat pour {$sku}.");

                return self::FAILURE;
            }
            $files = [$file];
        } else {
            $files = File::glob("{$dir}/*.json");
            sort($files);
        }

        if ($files === []) {
            $this->warn('Aucun fichier candidat.');

            return self::SUCCESS;
        }

        $results = [];
        $failed = false;

        foreach ($files as $file) {
            $data = json_decode(File::get($file), true);

            $errors = is_array($data) ? $this->validate($data, basename($file, '.json')) : ['JSON invalide'];
            if ($errors !== []) {
                $failed = true;
                foreach ($errors as $error) {
                    $this->error(basename($file).' : '.$error);
                }

                continue;
            }

            $result = $this->auditSku($data);
            $results[] = $result;

            if (! $this->option('json')) {
                $this->displayResult($result);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function validate(array $data, string $fileSku): array
    {
        $errors = [];

        if (! is_string($data['sku'] ?? null) || $data['sku'] === '') {
            $errors[] = 'champ « sku » manquant';
        } elseif ($data['sku'] !== $fileSku) {
            $errors[] = "« sku » ({$data['sku']}) different du nom de fichier ({$fileSku})";
        }
        if (! is_string($data['brand'] ?? null) || $data['brand'] === '') {
            $errors[] = 'champ « brand » (slug de marque) manquant : un slug de modele n\'est unique que par marque';
        }
        if (($data['status'] ?? null) !== 'a_verifier') {
            $errors[] = '« status » doit valoir « a_verifier » : ce fichier ne contient que des propositions';
        }
        if (! is_array($data['candidates'] ?? null) || ! array_is_list($data['candidates'])) {
            $errors[] = '« candidates » doit etre une liste';

            return $errors;
        }

        foreach ($data['candidates'] as $i => $c) {
            $n = $i + 1;
            if (! is_array($c)) {
                $errors[] = "candidat {$n} : objet attendu";

                continue;
            }
            foreach (['slug', 'engine_code', 'provenance'] as $field) {
                if (! is_string($c[$field] ?? null) || trim($c[$field]) === '') {
                    $errors[] = "candidat {$n} : « {$field} » manquant";
                }
            }
            foreach (['year_from', 'year_to'] as $field) {
                if (! is_int($c[$field] ?? null) || $c[$field] < 1900 || $c[$field] > 2100) {
                    $errors[] = "candidat {$n} : « {$field} » doit etre une annee";
                }
            }
            if (is_int($c['year_from'] ?? null) && is_int($c['year_to'] ?? null) && $c['year_from'] > $c['year_to']) {
                $errors[] = "candidat {$n} : year_from > year_to";
            }
        }

        return $errors;
    }

    private function auditSku(array $data): array
    {
        $part = Part::withTrashed()->where('sku', $data['sku'])->first();
        $brand = Brand::where('slug', $data['brand'])->first();

        $result = [
            'sku' => $data['sku'],
            'brand' => $data['brand'],
            'brand_exists' => $brand !== null,
            'status' => $data['status'],
            'certified' => false,
            'part_exists' => $part !== null,
            'part_trashed' => $part?->trashed() ?? false,
            'existing_fitments' => $part ? $part->fitments()->count() : 0,
            'candidates' => [],
            'missing_models' => [],
        ];

        foreach ($data['candidates'] as $candidate) {
            $matches = $brand
                ? VehicleModel::where('brand_id', $brand->id)->where('slug', $candidate['slug'])->count()
                : 0;

            $result['candidates'][] = [
                'slug' => $candidate['slug'],
                'year_from' => $candidate['year_from'],
                'year_to' => $candidate['year_to'],
                'engine_code' => $candidate['engine_code'],
                'provenance' => $candidate['provenance'],
                // Existence du modele dans le referentiel, PAS compatibilite.
                'model_exists' => $matches === 1,
                'model_ambiguous' => $matches > 1,
                'verified' => false,
            ];

            if ($matches === 0) {
                $result['missing_models'][] = $candidate['slug'];
            }
        }

        $result['missing_models'] = array_values(array_unique($result['missing_models']));

        return $result;
    }

    private function displayResult(array $result): void
    {
        $this->newLine();
        $this->info("SKU : {$result['sku']} (marque {$result['brand']}".($result['brand_exists'] ? '' : ', INTROUVABLE').')');
        $this->line("  Statut : {$result['status']} — propositions NON verifiees, rien n'est publie");
        $this->line('  Piece en base : '.($result['part_exists'] ? ($result['part_trashed'] ? 'oui (corbeille)' : 'oui') : 'non'));
        $this->line("  Compatibilites publiees existantes : {$result['existing_fitments']}");
        $this->line('  Candidats : '.count($result['candidates']));

        if ($result['missing_models'] !== []) {
            $this->warn('  Modeles absents du referentiel : '.implode(', ', $result['missing_models']));
        }

        foreach ($result['candidates'] as $c) {
            $etat = $c['model_ambiguous'] ? 'modele ambigu' : ($c['model_exists'] ? 'modele connu' : 'modele absent');
            $this->line("    [{$etat}] {$c['slug']} ({$c['year_from']}-{$c['year_to']}) {$c['engine_code']} — a verifier");
        }
    }
}
