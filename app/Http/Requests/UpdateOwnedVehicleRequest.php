<?php

namespace App\Http\Requests;

class UpdateOwnedVehicleRequest extends StoreOwnedVehicleRequest
{
    public function rules(): array
    {
        // Memes regles qu'a la creation, mais tout devient optionnel (mise a jour partielle).
        return collect(parent::rules())->map(function (array $rule) {
            return collect($rule)->map(fn ($r) => $r === 'required' ? 'sometimes' : $r)->all();
        })->all();
    }
}
