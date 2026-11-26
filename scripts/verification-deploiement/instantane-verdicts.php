<?php

/**
 * Instantané des verdicts de compatibilité, en LECTURE SEULE.
 *
 * Usage, depuis la racine de l'application (n'importe quelle version à partir
 * de main au 26/11/2026 : n'utilise que des API présentes avant et après #42) :
 *
 *     php scripts/verification-deploiement/instantane-verdicts.php sortie.json [instantane-precedent.json]
 *
 * Le second argument (facultatif, recommandé pour l'instantané « après »)
 * reprend aussi les échantillons catalogue de l'instantané précédent : les
 * deux fichiers évaluent alors les mêmes recherches, et une différence est un
 * vrai changement de verdict, pas un échantillon apparu avec une ligne neuve.
 *
 * Contenu : pour chaque véhicule réel (garage `owned_vehicles`, parc
 * `vehicles`, hors supprimés) × chaque pièce portant au moins une
 * compatibilité, le verdict calculé par le service de l'application, avec les
 * critères que l'API utilise pour ce véhicule ; plus un échantillon de
 * recherches catalogue tiré de chaque ligne de compatibilité (année de début,
 * année de fin, avec et sans code moteur). Les verdicts `unknown` ne sont pas
 * écrits (clé absente = unknown).
 *
 * Garanties : toute la lecture se fait dans une transaction annulée à la fin,
 * en `READ ONLY` sous PostgreSQL ; aucune écriture n'est possible.
 */

use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use App\Services\PartCompatibilityAssessment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$racine = getcwd();
require $racine.'/vendor/autoload.php';
$app = require $racine.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sortie = $argv[1] ?? 'verdicts.json';
$precedent = isset($argv[2]) ? json_decode(file_get_contents($argv[2]), true) : null;

DB::beginTransaction();
if (DB::getDriverName() === 'pgsql') {
    DB::statement('SET TRANSACTION READ ONLY');
}

try {
    $evaluation = app(PartCompatibilityAssessment::class);
    $service = app(CompatibilityService::class);
    $eager = defined(PartCompatibilityAssessment::class.'::EAGER')
        ? constant(PartCompatibilityAssessment::class.'::EAGER')
        : ['fitments', 'oemNumbers.fitments'];

    $preparer = function (array $criteres) use ($evaluation): array {
        $criteres += ['include_inactive' => false];

        return method_exists($evaluation, 'withModelState') ? $evaluation->withModelState($criteres) : $criteres;
    };

    $pieces = Part::query()
        ->where(fn ($q) => $q->has('fitments')->orHas('oemNumbers.fitments'))
        ->with($eager)->orderBy('id')->get();
    $slugs = VehicleModel::query()->pluck('slug', 'id');

    $verdicts = [];
    $noter = function (string $cle, Part $piece, array $criteres, array $contexte) use (&$verdicts, $evaluation) {
        $v = $evaluation->assess($piece, $criteres);
        // « unknown » banal (aucune ligne ne correspond) : non écrit. Un
        // « unknown » motivé autrement (modèle inactif…) l'est.
        if (($v['status'] ?? 'unknown') === 'unknown'
            && in_array($v['reason'] ?? 'no_matching_fitment', ['no_matching_fitment', 'missing_vehicle_model'], true)) {
            return;
        }
        $verdicts[$cle] = $contexte + [
            'part_id' => $piece->id,
            'sku' => $piece->sku,
            'status' => $v['status'],
            'reason' => $v['reason'] ?? null,
            'missing_criteria' => array_values($v['missing_criteria'] ?? []),
            'excluded_criteria' => array_values($v['excluded_criteria'] ?? []),
        ];
    };

    $vehicules = [];
    foreach ([['garage', OwnedVehicle::query()->orderBy('id')->get(), 'ownedVehicleCriteria'],
              ['parc', Vehicle::query()->orderBy('id')->get(), 'vehicleCriteria']] as [$type, $liste, $methode]) {
        foreach ($liste as $vehicule) {
            $criteres = $preparer($service->{$methode}($vehicule));
            $contexte = [
                'type' => $type,
                'vehicle_id' => $vehicule->id,
                'model' => $slugs[$vehicule->vehicle_model_id] ?? null,
                'year' => $vehicule->manufacturing_year,
                'engine_code' => $criteres['engine_code'] ?? null,
            ];
            $vehicules[] = $contexte + ['criteria' => $criteres];
            foreach ($pieces as $piece) {
                $noter("$type:{$vehicule->id}|{$piece->sku}#{$piece->id}", $piece, $criteres, $contexte);
            }
        }
    }

    $echantillons = [];
    foreach ($pieces as $piece) {
        foreach ($piece->fitments as $ligne) {
            foreach (array_unique(array_filter([$ligne->year_from, $ligne->year_to])) ?: [null] as $annee) {
                foreach (array_unique([$ligne->engine_code ?: null, null]) as $moteur) {
                    $slug = $slugs[$ligne->vehicle_model_id] ?? (string) $ligne->vehicle_model_id;
                    $echantillons["$slug|$annee|$moteur"] = ['vehicle_model_id' => $ligne->vehicle_model_id, 'year' => $annee, 'engine_code' => $moteur, 'model' => $slug];
                }
            }
        }
    }
    $slugVersId = $slugs->flip();
    foreach ($precedent['echantillons'] ?? [] as $cle => $e) {
        if (! isset($echantillons[$cle]) && isset($slugVersId[$e['model']])) {
            $echantillons[$cle] = ['vehicle_model_id' => $slugVersId[$e['model']]] + $e;
        }
    }
    ksort($echantillons);
    foreach ($echantillons as $cle => $e) {
        $criteres = $preparer(['vehicle_model_id' => $e['vehicle_model_id'], 'year' => $e['year'], 'trim_id' => null,
            'engine_type_id' => null, 'drivetrain_id' => null, 'engine_code' => $e['engine_code']]);
        foreach ($pieces as $piece) {
            $noter("catalogue:$cle|{$piece->sku}#{$piece->id}", $piece, $criteres,
                ['type' => 'catalogue', 'model' => $e['model'], 'year' => $e['year'], 'engine_code' => $e['engine_code']]);
        }
    }
    ksort($verdicts);

    $meta = [
        'pris_le' => now()->toIso8601String(),
        'commit' => env('RENDER_GIT_COMMIT') ?: trim((string) @shell_exec('git -C '.escapeshellarg($racine).' rev-parse --short HEAD 2>/dev/null')),
        'base' => DB::getDriverName(),
        'migrations' => DB::table('migrations')->count(),
        'derniere_migration' => DB::table('migrations')->max('migration'),
        'pieces_evaluees' => $pieces->count(),
        'vehicules' => count($vehicules),
        'echantillons_catalogue' => count($echantillons),
        'verdicts_non_unknown' => count($verdicts),
    ];
    $listeEchantillons = array_map(fn ($e) => ['model' => $e['model'], 'year' => $e['year'], 'engine_code' => $e['engine_code']], $echantillons);
    file_put_contents($sortie, json_encode(['meta' => $meta, 'vehicules' => $vehicules, 'echantillons' => $listeEchantillons, 'verdicts' => $verdicts],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, json_encode($meta, JSON_UNESCAPED_UNICODE)."\n");
} finally {
    DB::rollBack();
}
