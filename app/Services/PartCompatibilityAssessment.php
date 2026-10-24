<?php

namespace App\Services;

use App\Enums\FitmentSource;
use App\Models\OemNumberFitment;
use App\Models\Part;
use App\Models\PartFitment;
use App\Support\EngineCode;
use Illuminate\Database\Eloquent\Builder;

/** Qualifies a match against the catalogue; it does not certify a manufacturer. */
class PartCompatibilityAssessment
{
    /** Filter before pagination: legacy clients use returned IDs as confirmation. */
    public function constrainConfirmed(Builder $parts, array $criteria): Builder
    {
        if (empty($criteria['vehicle_model_id'])) {
            return $parts->whereRaw('1 = 0');
        }

        $engineCode = EngineCode::forCompatibility($criteria['engine_code'] ?? null);
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
        };

        return $parts->where(function (Builder $query) use ($apply) {
            $query->whereHas('fitments', function (Builder $fitment) use ($apply) {
                $fitment->where('source', FitmentSource::Declared->value);
                $apply($fitment);
            })->orWhereHas('oemNumbers.fitments', $apply);
        });
    }

    public function assess(Part $part, array $criteria): array
    {
        if (empty($criteria['vehicle_model_id'])) {
            return $this->result('unknown', ['vehicle_model_id'], 'missing_vehicle_model');
        }

        // A one-character VIN marker is not a resolved engine code. Do not
        // infer an engine or drivetrain from a trim's defaults here.
        $criteria['engine_code'] = EngineCode::forCompatibility($criteria['engine_code'] ?? null);
        $part->loadMissing(['fitments', 'oemNumbers.fitments']);
        $candidates = [];
        $declaredCandidate = false;

        foreach ($part->fitments as $fitment) {
            $missing = $this->missingCriteria($fitment, $criteria);

            if ($missing === null) {
                continue;
            }

            $declared = $fitment->source === FitmentSource::Declared;

            if ($declared && $missing === []) {
                return $this->result('confirmed', [], 'matching_declared_fitment');
            }

            $candidates[] = $missing;
            $declaredCandidate = $declaredCandidate || $declared;
        }

        foreach ($part->oemNumbers as $oem) {
            foreach ($oem->fitments as $fitment) {
                $missing = $this->missingCriteria($fitment, $criteria);

                if ($missing === null) {
                    continue;
                }

                if ($missing === []) {
                    return $this->result('confirmed', [], 'matching_oem_fitment');
                }

                $candidates[] = $missing;
                $declaredCandidate = true;
            }
        }

        if ($candidates === []) {
            // No matching declaration means that the catalogue cannot answer;
            // it is not evidence that this part cannot physically fit.
            return $this->result('unknown', [], 'no_matching_fitment');
        }

        $missing = array_values(array_unique(array_merge(...$candidates)));

        return $this->result(
            'candidate',
            $missing,
            $declaredCandidate ? 'missing_vehicle_criteria' : 'generated_fitment',
        );
    }

    /** Null excludes a row; an empty list is a complete match for that row. */
    private function missingCriteria(PartFitment|OemNumberFitment $fitment, array $criteria): ?array
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
            return null;
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
                return null;
            }
        }

        $expectedCode = EngineCode::normalize($fitment->engine_code);

        if ($expectedCode !== null) {
            if ($criteria['engine_code'] === null) {
                $missing[] = 'engine_code';
            } elseif ($criteria['engine_code'] !== $expectedCode) {
                return null;
            }
        }

        return $missing;
    }

    private function result(string $status, array $missing, string $reason): array
    {
        return [
            'status' => $status,
            'compatible' => $status === 'confirmed',
            'compatibility_ready' => $status === 'confirmed',
            'missing_criteria' => $missing,
            'reason' => $reason,
        ];
    }
}
