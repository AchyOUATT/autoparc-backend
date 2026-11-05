<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MotorisationResource;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\Trim;
use App\Services\ConsumptionReference;
use App\Services\ConsumptionReferenceRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class MotorisationController extends Controller
{
    public function index(Request $request, ConsumptionReference $references, ConsumptionReferenceRecommendation $recommendations): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'vehicle_model_id' => ['required', 'integer', 'exists:vehicle_models,id'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'model_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'specification_market' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'trim_id' => ['nullable', 'integer', 'exists:trims,id'],
            'engine_type_id' => ['nullable', 'integer', 'exists:engine_types,id'],
            'drivetrain_id' => ['nullable', 'integer', 'exists:drivetrains,id'],
            'engine_code' => ['nullable', 'string', 'max:20'],
            'engine_displacement_cc' => ['nullable', 'integer', 'min:1', 'max:30000'],
            'engine_displacement_l' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:30'],
            'cylinders' => ['nullable', 'integer', 'min:1', 'max:24'],
            'power_hp' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);
        // Use the same effective engine/drivetrain as selection validation:
        // an omitted explicit value may fall back to the selected trim.
        $identity = new OwnedVehicle(array_diff_key($validated, ['year' => true]));
        $trim = empty($validated['trim_id']) ? null : Trim::find($validated['trim_id']);
        if ($trim !== null && (int) $trim->vehicle_model_id !== (int) $validated['vehicle_model_id']) {
            throw ValidationException::withMessages([
                'trim_id' => __("Cette finition n'appartient pas au modele selectionne."),
            ]);
        }
        $identity->setRelation('trim', $trim);
        $identity->manufacturing_year = $validated['year'] ?? null;
        $context = $references->context($identity);
        $request->attributes->set('consumption_reference_context', $context);

        // All candidates are considered before assessment. A limit before the
        // match would turn an incomplete search into a false unique version.
        // Sources, cycles, model years and powers are never merged together.
        $ratings = Motorisation::query()
            ->where('vehicle_model_id', $validated['vehicle_model_id'])
            ->where('is_current', true)
            ->where('reference_status', '!=', 'withdrawn')
            ->get()
            ->filter(fn (Motorisation $rating) => $references->contradictions($rating, $context) === [])
            ->values();
        $ranked = $recommendations->rank($ratings, $context);
        $request->attributes->set('consumption_reference_recommendations', $ranked['recommendations']);

        return MotorisationResource::collection($ranked['ratings'])->additional(['meta' => [
            'complete' => true,
            'scope' => 'stored_current_references',
            'total' => $ratings->count(),
            'requested_year' => $validated['year'] ?? null,
            'automatic_selection' => false,
            'ranked' => true,
            'recommended_ids' => $ranked['recommended_ids'],
        ]]);
    }
}
