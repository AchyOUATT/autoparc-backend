<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MotorisationResource;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\Trim;
use App\Services\ConsumptionReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class MotorisationController extends Controller
{
    public function index(Request $request, ConsumptionReference $references): AnonymousResourceCollection
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
        ]);
        // Use the same effective engine/drivetrain as selection validation:
        // an omitted explicit value may fall back to the selected trim.
        $identity = new OwnedVehicle(array_diff_key($validated, ['year' => true]));
        $trim = empty($validated['trim_id']) ? null : Trim::find($validated['trim_id']);
        if ($trim !== null && (int) $trim->vehicle_model_id !== (int) $validated['vehicle_model_id']) {
            throw ValidationException::withMessages([
                'trim_id' => "Cette finition n'appartient pas au modele selectionne.",
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
            ->sortBy(fn (Motorisation $rating) => [
                empty($validated['year']) || $rating->year_kind !== 'model_year'
                    ? PHP_INT_MAX : abs($rating->model_year - $validated['year']),
                (float) ($rating->engine_l ?? 0),
                $rating->source, $rating->cycle, $rating->id,
            ])
            ->values();

        return MotorisationResource::collection($ratings)->additional(['meta' => [
            'complete' => true,
            'scope' => 'stored_current_references',
            'total' => $ratings->count(),
            'requested_year' => $validated['year'] ?? null,
            'automatic_selection' => false,
        ]]);
    }
}
