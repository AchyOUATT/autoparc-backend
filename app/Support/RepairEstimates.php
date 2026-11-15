<?php

namespace App\Support;

use App\Enums\FaultStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Public buyer-facing repair range, never mixed with staff estimated_repair_cost. */
final class RepairEstimates
{
    /**
     * Compatibility with published apps v131–v133: omitting `repair_estimate`
     * or `repair_necessity` on an update keeps the stored columns (and any
     * attached quotes). Only an explicit JSON null on `repair_estimate`
     * clears the public range. Quotes are managed by their own endpoints
     * and are never cleared by a declaration update.
     */

    public const CURRENCY = 'XOF';

    public const STALE_MONTHS = 6;

    public const SOURCES = ['seller', 'workshop', 'autoparc'];

    public const PUBLIC_SOURCES = ['seller', 'workshop'];

    public const NECESSITIES = ['necessary', 'optional'];

    public const COLUMNS = [
        'repair_cost_min', 'repair_cost_max', 'repair_estimate_source', 'repair_estimated_at',
    ];

    public static function rules(string $prefix = 'faults.*'): array
    {
        $estimate = $prefix.'.repair_estimate';

        return [
            $prefix.'.repair_necessity' => ['sometimes', 'nullable', Rule::in(self::NECESSITIES)],
            $estimate => ['sometimes', 'nullable', 'array:cost_min,cost_max,source,estimated_at'],
            $estimate.'.cost_min' => ['required_with:'.$estimate, 'integer', 'min:0', 'max:100000000000'],
            $estimate.'.cost_max' => ['required_with:'.$estimate, 'integer', 'min:0', 'max:100000000000', 'gte:'.$estimate.'.cost_min'],
            $estimate.'.source' => ['required_with:'.$estimate, Rule::in(self::SOURCES)],
            $estimate.'.estimated_at' => ['required_with:'.$estimate, 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public static function rejectStaffOnlySource(Validator $validator, mixed $rows, bool $staff): void
    {
        if ($staff || ! is_array($rows)) {
            return;
        }
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $source = data_get($row, 'repair_estimate.source');
            if ($source === 'autoparc') {
                $validator->errors()->add(
                    "faults.$index.repair_estimate.source",
                    __('Seul le personnel AutoParc peut attribuer un chiffrage AutoParc.')
                );
            }
        }
    }

    /**
     * @return array{repair_cost_min: int|null, repair_cost_max: int|null, repair_estimate_source: string|null, repair_estimated_at: string|null}
     */
    public static function columnsFromInput(mixed $estimate): array
    {
        if (! is_array($estimate)) {
            return [
                'repair_cost_min' => null,
                'repair_cost_max' => null,
                'repair_estimate_source' => null,
                'repair_estimated_at' => null,
            ];
        }

        return [
            'repair_cost_min' => isset($estimate['cost_min']) ? (int) $estimate['cost_min'] : null,
            'repair_cost_max' => isset($estimate['cost_max']) ? (int) $estimate['cost_max'] : null,
            'repair_estimate_source' => isset($estimate['source']) ? (string) $estimate['source'] : null,
            'repair_estimated_at' => isset($estimate['estimated_at']) ? (string) $estimate['estimated_at'] : null,
        ];
    }

    /** @param  object{repair_cost_min?: mixed, repair_cost_max?: mixed, repair_estimate_source?: mixed, repair_estimated_at?: mixed}  $fault */
    public static function columnsFromModel(object $fault): array
    {
        $date = $fault->repair_estimated_at ?? null;

        return [
            'repair_cost_min' => $fault->repair_cost_min === null ? null : (int) $fault->repair_cost_min,
            'repair_cost_max' => $fault->repair_cost_max === null ? null : (int) $fault->repair_cost_max,
            'repair_estimate_source' => $fault->repair_estimate_source,
            'repair_estimated_at' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date,
        ];
    }

    public static function present(object $fault): ?array
    {
        if ($fault->repair_cost_min === null || $fault->repair_cost_max === null
            || $fault->repair_estimate_source === null || $fault->repair_estimated_at === null) {
            return null;
        }
        $date = $fault->repair_estimated_at instanceof \DateTimeInterface
            ? $fault->repair_estimated_at->format('Y-m-d')
            : (string) $fault->repair_estimated_at;

        return [
            'cost_min' => (int) $fault->repair_cost_min,
            'cost_max' => (int) $fault->repair_cost_max,
            'currency' => self::CURRENCY,
            'source' => $fault->repair_estimate_source,
            'estimated_at' => $date,
            'is_stale' => self::isStale($date),
        ];
    }

    public static function isStale(?string $date, ?CarbonImmutable $today = null): bool
    {
        if ($date === null || $date === '') {
            return false;
        }
        $estimated = CarbonImmutable::createFromFormat('Y-m-d', $date);
        if ($estimated === false) {
            return false;
        }
        $today ??= CarbonImmutable::today();

        return $estimated->startOfDay()
            ->addMonthsNoOverflow(self::STALE_MONTHS)
            ->lte($today->startOfDay());
    }

    /**
     * Totals only necessary, still-open, publicly visible faults.
     *
     * @param  Collection<int, object>  $visible
     * @return array<string, mixed>
     */
    public static function budget(Collection $visible): array
    {
        $necessary = $visible->filter(function ($fault) {
            $status = $fault->status instanceof FaultStatus ? $fault->status : FaultStatus::tryFrom((string) $fault->status);

            return ($fault->repair_necessity ?: 'necessary') === 'necessary'
                && $status !== null && $status->isOpen();
        });
        $optional = $visible->filter(function ($fault) {
            $status = $fault->status instanceof FaultStatus ? $fault->status : FaultStatus::tryFrom((string) $fault->status);

            return ($fault->repair_necessity ?: 'necessary') === 'optional'
                && $status !== null && $status->isOpen();
        });

        $estimated = $necessary->filter(fn ($fault) => self::present($fault) !== null);
        $unestimated = $necessary->count() - $estimated->count();
        $status = 'none';
        if ($necessary->isNotEmpty()) {
            $status = $estimated->isEmpty() ? 'to_estimate' : ($unestimated === 0 ? 'complete' : 'partial');
        }

        return [
            'currency' => self::CURRENCY,
            'status' => $status,
            'cost_min' => $estimated->isEmpty() ? null : (int) $estimated->sum(fn ($fault) => (int) $fault->repair_cost_min),
            'cost_max' => $estimated->isEmpty() ? null : (int) $estimated->sum(fn ($fault) => (int) $fault->repair_cost_max),
            'estimated_count' => $estimated->count(),
            'unestimated_count' => $unestimated,
            'optional' => $optional->map(fn ($fault) => [
                'id' => $fault->id,
                'title' => $fault->title,
                'repair_estimate' => self::present($fault),
            ])->values()->all(),
        ];
    }
}
