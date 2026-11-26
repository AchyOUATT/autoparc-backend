<?php

namespace App\Services;

use App\Enums\FitmentSource;
use App\Models\OemNumberFitment;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Support\EngineCode;
use App\Support\FitmentEvidence;
use App\Support\OemFitmentInheritance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qualifies a match against the catalogue; it does not certify a manufacturer.
 *
 * Verdicts :
 *  - confirmed : une ligne déclarée, à preuve suffisante, dont tous les
 *                critères exigés sont connus et satisfaits ;
 *  - candidate : au moins une ligne non exclue, mais un critère exigé est
 *                inconnu, ou la preuve est insuffisante, ou la ligne est
 *                fabriquée — jamais présenté comme certain ;
 *  - excluded  : le modèle a des lignes pour cette pièce, et chacune est
 *                contredite par un critère connu du véhicule ;
 *  - unknown   : aucune ligne pour ce modèle (le catalogue ne sait pas ;
 *                ce n'est pas une incompatibilité).
 *
 * Seul confirmed met `compatible` et `compatibility_ready` à vrai.
 */
class PartCompatibilityAssessment
{
    public const MISSING_EVIDENCE = 'evidence';

    public function __construct(private readonly FitmentRestrictions $restrictions = new FitmentRestrictions()) {}

    /** Les relations à charger pour assess(), sans N+1. */
    public const EAGER = ['fitments', 'oemNumbers.fitments', 'oemNumbers.replacementRelations'];

    /** Filter before pagination: legacy clients use returned IDs as confirmation. */
    public function constrainConfirmed(Builder $parts, array $criteria): Builder
    {
        if (empty($criteria['vehicle_model_id'])) {
            return $parts->whereRaw('1 = 0');
        }

        if (! $this->modelIsSearchable($criteria)) {
            return $parts->whereRaw('1 = 0');
        }

        $engineCode = EngineCode::forCompatibility($criteria['engine_code'] ?? null);

        // Chassis, marche, mois : juges en PHP par FitmentRestrictions. Les
        // lignes restrictives qui ne seraient pas confirmees sont ecartees
        // par identifiant, avant la pagination.
        $modelId = (int) $criteria['vehicle_model_id'];
        $dropPart = $this->restrictions->idsToDrop(PartFitment::class, $modelId, $criteria, 'not_confirmed');
        $dropOem = $this->restrictions->idsToDrop(OemNumberFitment::class, $modelId, $criteria, 'not_confirmed');

        $apply = function (Builder $fitment) use ($criteria, $engineCode): void {
            $fitment->where('vehicle_model_id', $criteria['vehicle_model_id']);
            $year = $criteria['year'] ?? null;
            if ($year === null || $year === '') {
                $fitment->whereNull('year_from')->whereNull('year_to');
            } else {
                $fitment->where(fn ($q) => $q->whereNull('year_from')->orWhere('year_from', '<=', $year))
                    ->where(fn ($q) => $q->whereNull('year_to')->orWhere('year_to', '>=', $year));
            }

            foreach (['trim_id', 'engine_type_id', 'drivetrain_id'] as $field) {
                $value = $criteria[$field] ?? null;
                if ($value === null || $value === '') {
                    $fitment->whereNull($field);
                } else {
                    $fitment->where(fn ($q) => $q->whereNull($field)->orWhere($field, $value));
                }
            }

            if ($engineCode === null) {
                $fitment->where(fn ($q) => $q->whereNull('engine_code')->orWhereRaw("TRIM(engine_code) = ''"));
            } else {
                $fitment->where(fn ($q) => $q->whereNull('engine_code')
                    ->orWhereRaw("TRIM(engine_code) = ''")
                    ->orWhereRaw('UPPER(TRIM(engine_code)) = ?', [$engineCode]));
            }

            // Preuve suffisante (voir FitmentEvidence).
            $fitment->where(fn ($q) => $q->whereNull('evidence_level')
                ->orWhereIn('evidence_level', FitmentEvidence::SUFFICIENT));
        };

        return $parts->where(function (Builder $query) use ($apply, $dropPart, $dropOem) {
            $query->whereHas('fitments', function (Builder $fitment) use ($apply, $dropPart) {
                $fitment->where('source', FitmentSource::Declared->value);
                $apply($fitment);
                if ($dropPart !== []) {
                    $fitment->whereNotIn('part_fitments.id', $dropPart);
                }
            })->orWhereHas('oemNumbers', function (Builder $oem) use ($apply, $dropOem) {
                OemFitmentInheritance::constrain($oem);
                $oem->whereHas('fitments', function (Builder $fitment) use ($apply, $dropOem) {
                    $apply($fitment);
                    if ($dropOem !== []) {
                        $fitment->whereNotIn('oem_number_fitments.id', $dropOem);
                    }
                });
            });
        });
    }

    public function assess(Part $part, array $criteria): array
    {
        if (empty($criteria['vehicle_model_id'])) {
            return $this->result('unknown', ['vehicle_model_id'], 'missing_vehicle_model');
        }

        // L'etat du modele est fourni par l'appelant (withModelState) : aucune
        // requete ici, l'evaluation reste sans acces base une fois les
        // relations prechargees.
        if (($criteria['vehicle_model_active'] ?? true) === false && empty($criteria['include_inactive'])) {
            return $this->result('unknown', [], 'inactive_vehicle_model');
        }

        // A one-character VIN marker is not a resolved engine code. Do not
        // infer an engine or drivetrain from a trim's defaults here.
        $criteria['engine_code'] = EngineCode::forCompatibility($criteria['engine_code'] ?? null);
        $part->loadMissing(self::EAGER);
        $candidates = [];
        $excluded = [];
        $reasons = [];

        $lignes = [];
        foreach ($part->fitments as $fitment) {
            $lignes[] = [$fitment, $fitment->source === FitmentSource::Declared, 'matching_declared_fitment'];
        }
        foreach ($part->oemNumbers as $oem) {
            // Un numéro remplacé ne transmet pas ses compatibilités.
            if (! OemFitmentInheritance::passes($oem)) {
                continue;
            }
            foreach ($oem->fitments as $fitment) {
                $lignes[] = [$fitment, true, 'matching_oem_fitment'];
            }
        }

        foreach ($lignes as [$fitment, $declared, $reasonIfConfirmed]) {
            $verdict = $this->evaluateRow($fitment, $criteria);

            if ($verdict === null) {
                continue; // autre modèle
            }

            if ($verdict['excluded'] !== null) {
                $excluded[] = $verdict['excluded'];

                continue;
            }

            $missing = $verdict['missing'];
            $preuve = FitmentEvidence::isSufficient($fitment->evidence_level, $declared ? FitmentSource::Declared : FitmentSource::Generated);

            if ($missing === [] && $preuve) {
                return $this->result('confirmed', [], $reasonIfConfirmed);
            }

            if (! $declared) {
                $reasons[] = 'generated_fitment';
            } elseif (! $preuve) {
                $missing[] = self::MISSING_EVIDENCE;
                $reasons[] = 'insufficient_evidence';
            } else {
                $reasons[] = 'missing_vehicle_criteria';
            }
            $candidates[] = $missing;
        }

        if ($candidates !== []) {
            $missing = array_values(array_unique(array_merge(...$candidates)));
            // La raison la plus actionnable : un critère du véhicule à renseigner.
            $reason = in_array('missing_vehicle_criteria', $reasons, true) ? 'missing_vehicle_criteria'
                : (in_array('insufficient_evidence', $reasons, true) ? 'insufficient_evidence' : 'generated_fitment');

            return $this->result('candidate', $missing, $reason);
        }

        if ($excluded !== []) {
            return $this->result('excluded', [], 'incompatible_vehicle_criteria', array_values(array_unique($excluded)));
        }

        // No matching declaration means that the catalogue cannot answer;
        // it is not evidence that this part cannot physically fit.
        return $this->result('unknown', [], 'no_matching_fitment');
    }

    /**
     * Un modèle inactif (génération retirée) n'entre pas dans une recherche
     * courante ; `include_inactive` le rouvre pour la consultation historique.
     */
    /** @var array<int, bool> */
    private array $activeModels = [];

    /** Ajoute `vehicle_model_active` aux criteres (une requete par modele). */
    public function withModelState(array $criteria): array
    {
        $id = (int) ($criteria['vehicle_model_id'] ?? 0);
        if ($id !== 0) {
            $criteria['vehicle_model_active'] = $this->modelIsSearchable(['vehicle_model_id' => $id]);
        }

        return $criteria;
    }

    public function modelIsSearchable(array $criteria): bool
    {
        if (! empty($criteria['include_inactive'])) {
            return true;
        }
        $id = (int) ($criteria['vehicle_model_id'] ?? 0);
        if ($id === 0) {
            return true;
        }

        if (! array_key_exists($id, $this->activeModels)) {
            $actif = VehicleModel::query()->whereKey($id)->value('is_active');
            $this->activeModels[$id] = $actif === null || (bool) $actif;
        }

        return $this->activeModels[$id];
    }

    /**
     * Null : autre modèle. Sinon le critère qui exclut la ligne, ou les
     * critères à renseigner (liste vide = tous satisfaits).
     *
     * @return array{excluded: ?string, missing: list<string>}|null
     */
    private function evaluateRow(PartFitment|OemNumberFitment $fitment, array $criteria): ?array
    {
        if ((int) $fitment->vehicle_model_id !== (int) $criteria['vehicle_model_id']) {
            return null;
        }

        $missing = [];
        $year = $criteria['year'] ?? null;

        if ($year === null || $year === '') {
            if ($fitment->year_from !== null || $fitment->year_to !== null) {
                $missing[] = 'year';
            }
        } elseif (! $fitment->coversYear((int) $year)) {
            return ['excluded' => 'year', 'missing' => []];
        }

        foreach (['trim_id', 'engine_type_id', 'drivetrain_id'] as $field) {
            $expected = $fitment->{$field};

            if ($expected === null) {
                continue;
            }

            $actual = $criteria[$field] ?? null;

            if ($actual === null || $actual === '') {
                $missing[] = $field;
            } elseif ((int) $actual !== (int) $expected) {
                return ['excluded' => $field, 'missing' => []];
            }
        }

        $expectedCode = EngineCode::normalize($fitment->engine_code);

        if ($expectedCode !== null) {
            if ($criteria['engine_code'] === null) {
                $missing[] = 'engine_code';
            } elseif ($criteria['engine_code'] !== $expectedCode) {
                return ['excluded' => 'engine_code', 'missing' => []];
            }
        }

        // Criteres restrictifs : chassis, marche de destination, mois aux
        // bornes. Une colonne vide ne change rien.
        $restrictions = $this->restrictions->evaluateDetailed($fitment, $criteria);
        if ($restrictions['excluded'] !== null) {
            return $restrictions;
        }

        return ['excluded' => null, 'missing' => array_values(array_unique(array_merge($missing, $restrictions['missing'])))];
    }

    private function result(string $status, array $missing, string $reason, array $excluded = []): array
    {
        return [
            'status' => $status,
            'compatible' => $status === 'confirmed',
            'compatibility_ready' => $status === 'confirmed',
            'missing_criteria' => $missing,
            'excluded_criteria' => $excluded,
            'reason' => $reason,
        ];
    }
}
