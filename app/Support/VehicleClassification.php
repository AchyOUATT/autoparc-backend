<?php

namespace App\Support;

use App\Models\Motorisation;
use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

class VehicleClassification
{
    /**
     * Validate classification changes without blocking unrelated updates to
     * historically inconsistent records. Creation still validates every link.
     */
    public static function validate(Validator $validator, array $data, ?Model $vehicle = null): void
    {
        // Invalid identifiers (including arrays) must never reach the queries below.
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $value = static fn (string $field) => array_key_exists($field, $data)
            ? $data[$field]
            : $vehicle?->getAttribute($field);

        // A field resubmitted with the same identifier has not changed. This
        // matters for park clients that send a full payload on every update.
        $changed = static function (string $field) use ($data, $vehicle): bool {
            if ($vehicle === null) {
                return true;
            }

            if (! array_key_exists($field, $data)) {
                return false;
            }

            $previous = $vehicle->getAttribute($field);
            $incoming = $data[$field];

            return ($previous === null || $incoming === null)
                ? $previous !== $incoming
                : (string) $previous !== (string) $incoming;
        };

        $brandId = $value('brand_id');
        $modelId = $value('vehicle_model_id');
        $brandChanged = $changed('brand_id');
        $modelChanged = $changed('vehicle_model_id');

        if (($brandChanged || $modelChanged)
            && $modelId !== null
            && ! VehicleModel::whereKey($modelId)->where('brand_id', $brandId)->exists()) {
            $validator->errors()->add('vehicle_model_id', "Ce modele n'appartient pas a la marque selectionnee.");
        }

        foreach (['trim_id' => Trim::class, 'motorisation_id' => Motorisation::class] as $field => $modelClass) {
            // A preexisting invalid relationship does not block a price or
            // mileage update. Changing the model must, however, revalidate
            // every retained dependent identifier.
            if (! $modelChanged && ! $changed($field)) {
                continue;
            }

            $id = $value($field);

            if ($id === null) {
                continue;
            }

            if ($modelId === null || ! $modelClass::whereKey($id)->where('vehicle_model_id', $modelId)->exists()) {
                $validator->errors()->add($field, $field === 'trim_id'
                    ? "Cette finition n'appartient pas au modele selectionne."
                    : 'Cette motorisation ne correspond pas au modele selectionne.');
            }
        }
    }
}
