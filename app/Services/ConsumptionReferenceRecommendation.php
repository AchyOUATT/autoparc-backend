<?php

namespace App\Services;

use App\Models\Motorisation;
use Illuminate\Support\Collection;

/** Presentation relevance, independent of the reference's applicability level. */
class ConsumptionReferenceRecommendation
{
    private const WEIGHTS = [
        'engine_code' => 100,
        'engine_displacement_cc' => 60,
        'trim_id' => 50,
        'cylinders' => 20,
        'power_hp' => 15,
        'engine_displacement_l' => 10,
        'model_year' => 8,
        'specification_market' => 8,
        'engine_type' => 2,
        'drivetrain' => 2,
    ];

    /**
     * Rank the full candidate set. A stable display order must not break a tie.
     *
     * @param  Collection<int, Motorisation>  $ratings
     * @return array{ratings: Collection, recommendations: array, recommended_ids: array}
     */
    public function rank(Collection $ratings, array $context): array
    {
        $entries = [];
        foreach ($ratings as $rating) {
            $recommendation = $this->assess($rating, $context);
            $score = array_sum(array_intersect_key(self::WEIGHTS, array_flip($recommendation['matched_criteria'])));
            $quality = ($rating->reference_status === 'documented' ? 2 : 0)
                + ($rating->verified_at !== null ? 1 : 0)
                + (in_array($rating->method, ['test', 'adjustment', 'aggregation'], true) ? 1 : 0)
                + (in_array($rating->year_kind, ['model_year', 'registration_year'], true) ? 1 : 0);
            $entries[] = [
                'rating' => $rating,
                'recommendation' => $recommendation,
                'key' => [$recommendation['eligible'] ? 0 : 1, count($recommendation['conflicting_criteria']), -$score, -$quality],
                // Manufacture year is only a weak ordering hint inside a tie;
                // it cannot make a sole winner or count as a model-year match.
                'year_distance' => ! empty($context['requested_year']) && $rating->year_kind === 'model_year'
                    ? abs((int) $rating->model_year - (int) $context['requested_year']) : PHP_INT_MAX,
            ];
        }
        usort($entries, static fn (array $a, array $b) => ($a['key'] <=> $b['key'])
            ?: ($a['year_distance'] <=> $b['year_distance'])
            ?: strcmp((string) $a['rating']->source, (string) $b['rating']->source)
            ?: strcmp((string) $a['rating']->cycle, (string) $b['rating']->cycle)
            ?: ($a['rating']->id <=> $b['rating']->id));

        $recommendations = [];
        $recommendedIds = [];
        $previous = null;
        $rank = 0;
        foreach ($entries as $entry) {
            if ($previous !== $entry['key']) {
                $rank++;
                $previous = $entry['key'];
            }
            $recommendations[$entry['rating']->id] = ['rank' => $rank] + $entry['recommendation'];
            if ($rank === 1 && $entry['recommendation']['eligible']) {
                $recommendedIds[] = $entry['rating']->id;
            }
        }

        return [
            'ratings' => collect($entries)->pluck('rating')->values(),
            'recommendations' => $recommendations,
            'recommended_ids' => $recommendedIds,
        ];
    }

    /** Known mismatches affect the shortlist, never the stored/source values. */
    private function assess(Motorisation $rating, array $context): array
    {
        $matched = [];
        $conflicting = [];
        $missing = [];
        $compare = static function (string $criterion, mixed $known, mixed $source, callable $equal) use (&$matched, &$conflicting, &$missing): void {
            if ($known === null || $known === '') {
                $missing[] = $criterion;
            } elseif ($source !== null && $source !== '') {
                if ($equal($known, $source)) {
                    $matched[] = $criterion;
                } else {
                    $conflicting[] = $criterion;
                }
            }
        };
        $sameText = static fn ($a, $b) => strtoupper(trim((string) $a)) === strtoupper(trim((string) $b));
        $sameInt = static fn ($a, $b) => (int) $a === (int) $b;
        $compare('engine_code', $context['engine_code'] ?? null, $rating->engine_code, $sameText);
        $compare('engine_displacement_cc', $context['engine_displacement_cc'] ?? null, $rating->displacement_cc ?: null, $sameInt);
        $compare('trim_id', $context['trim_id'] ?? null, $rating->trim_id, $sameInt);
        $compare('cylinders', $context['cylinders'] ?? null, $rating->cylinders ?: null, $sameInt);
        $compare('model_year', $context['model_year'] ?? null,
            $rating->year_kind === 'model_year' ? $rating->model_year : null, $sameInt);
        $compare('specification_market', $context['specification_market'] ?? null, $rating->market, $sameText);

        // Exact cc may be rounded for a weak comparison to source litres.
        // The reverse operation must never invent an exact cc value.
        $litres = $context['engine_displacement_l'] ?? null;
        if ($litres === null && ! empty($context['engine_displacement_cc'])) {
            $litres = (float) $context['engine_displacement_cc'] / 1000;
        }
        $sourceLitres = $rating->engine_l !== null && (float) $rating->engine_l > 0 ? $rating->engine_l : null;
        if ($sourceLitres === null && $rating->displacement_cc !== null) {
            $sourceLitres = (float) $rating->displacement_cc / 1000;
        }
        if ($litres === null) {
            $missing[] = 'engine_displacement_l';
        } elseif ($sourceLitres !== null) {
            if (round((float) $litres, 1) === round((float) $sourceLitres, 1)) {
                $matched[] = 'engine_displacement_l';
            } elseif (abs((float) $litres - (float) $sourceLitres) > 0.200001) {
                // A broad 0.2 L margin tolerates commercial/one-decimal labels;
                // clearly different engine sizes remain inspection-only.
                $conflicting[] = 'engine_displacement_l';
            }
        }

        $compare('power_hp', $context['power_hp'] ?? null, $rating->power_kw !== null && (float) $rating->power_kw > 0 ? $rating->power_kw : null,
            // Stored hp is metric horsepower (ch). Allow rounded source power;
            // a positive comparison remains an indication, never certification.
            static fn ($hp, $kw) => abs((float) $hp * 0.73549875 - (float) $kw) <= max(1.0, (float) $kw * 0.02));

        $engine = $context['engine_type_code'] ?? null;
        if ($engine === null) {
            $missing[] = 'engine_type';
        } elseif (match ($engine) {
            'petrol' => in_array($rating->fuel_code, ['X', 'Z', 'E'], true),
            'diesel' => $rating->fuel_code === 'D',
            'electric' => $rating->fuel_code === 'B',
            'hybrid' => $rating->fuel_code === 'H'
                || in_array($rating->hybrid_type, ['hybrid', 'plug_in_hybrid', 'hev', 'phev'], true),
            default => false,
        }) {
            $matched[] = 'engine_type';
        }
        $compare('drivetrain', $context['drivetrain_code'] ?? null, $rating->provenance['drivetrain_code'] ?? null, $sameText);
        $eligible = $conflicting === [];

        return [
            'matched_criteria' => $matched,
            'missing_criteria' => $missing,
            'conflicting_criteria' => $conflicting,
            'eligible' => $eligible,
            'has_discriminating_match' => $eligible && array_diff($matched, ['engine_type', 'drivetrain']) !== [],
        ];
    }
}
