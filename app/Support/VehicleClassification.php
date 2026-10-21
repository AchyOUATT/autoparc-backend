<?php

namespace App\Support;

use App\Models\Motorisation;
use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

class VehicleClassification
{
    /** Validate the resulting identity, including values retained by a partial update. */
    public static function validate(Validator $validator, array $data, ?Model $vehicle = null): void
    {
        // Invalid identifiers (including arrays) must never reach the queries below.
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $value = static fn (string $field) => array_key_exists($field, $data)
            ? $data[$field]
            : $vehicle?->getAttribute($field);

        $brandId = $value('brand_id');
        $modelId = $value('vehicle_model_id');

        if ($modelId !== null && ! VehicleModel::whereKey($modelId)->where('brand_id', $brandId)->exists()) {
            $validator->errors()->add('vehicle_model_id', "Ce modele n'appartient pas a la marque selectionnee.");
        }

        foreach (['trim_id' => Trim::class, 'motorisation_id' => Motorisation::class] as $field => $modelClass) {
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
