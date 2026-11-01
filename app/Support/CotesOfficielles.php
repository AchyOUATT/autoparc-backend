<?php

namespace App\Support;

use App\Models\Motorisation;
use App\Models\Vehicle;
use App\Services\ConsumptionReference;

/**
 * Ce qu'on peut dire de la consommation d'une annonce.
 *
 * Les sources, protocoles et variantes restent des références distinctes.
 * Une plage min/max les réunirait sous une seule attribution trompeuse ;
 * l'annonce ne documente pas le marché et le millésime de sa configuration.
 */
class CotesOfficielles
{
    /**
     * @return list<Motorisation>
     */
    public static function pourVehicule(Vehicle $vehicule): array
    {
        if ($vehicule->vehicle_model_id === null) {
            return [];
        }

        $references = app(ConsumptionReference::class);
        $context = self::context($vehicule);

        // Only the detail controller calls this method. The catalogue list
        // leaves its preloaded reference slot null and performs no query here.
        return Motorisation::query()
            ->where('vehicle_model_id', $vehicule->vehicle_model_id)
            ->where('is_current', true)
            ->where('reference_status', '!=', 'withdrawn')
            ->whereNotNull('consumption_combined')
            ->get()
            ->filter(fn (Motorisation $reference) => $references->contradictions($reference, $context) === [])
            ->sortBy(fn (Motorisation $reference) => [
                $vehicule->manufacturing_year === null || $reference->year_kind !== 'model_year'
                    ? PHP_INT_MAX : abs($reference->model_year - $vehicule->manufacturing_year),
                $reference->source, $reference->cycle, $reference->model_year, $reference->id,
            ])
            ->values()
            ->all();
    }

    public static function context(Vehicle $vehicule): array
    {
        $context = [
            'vehicle_model_id' => $vehicule->vehicle_model_id,
            'trim_id' => $vehicule->trim_id,
            'engine_code' => EngineCode::forCompatibility($vehicule->engine_code),
            'engine_type_id' => $vehicule->engine_type_id,
            'drivetrain_id' => $vehicule->drivetrain_id,
            'transmission' => $vehicule->transmission?->value,
            'gear_count' => $vehicule->gear_count,
            'requested_year' => $vehicule->manufacturing_year,
            // Manufacturing year and import country do not establish either.
            'model_year' => null,
            'specification_market' => null,
        ];

        if ($vehicule->relationLoaded('engineType')) {
            $context['engine_type_code'] = $vehicule->engineType?->code;
        }
        if ($vehicule->relationLoaded('drivetrain')) {
            $context['drivetrain_code'] = $vehicule->drivetrain?->code;
        }

        return app(ConsumptionReference::class)->hydrateContext($context);
    }
}
