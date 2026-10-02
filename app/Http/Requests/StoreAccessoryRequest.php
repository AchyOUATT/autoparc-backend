<?php

namespace App\Http\Requests;

use App\Enums\AccessoryCategory;
use App\Http\Requests\Concerns\RepliSurLaValeurParDefaut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessoryRequest extends FormRequest
{
    use RepliSurLaValeurParDefaut;

    /**
     * Colonnes NOT NULL que la migration dote d'un `default()`.
     *
     * Les regles ci-dessous les declarent facultatives, et c'est bien ainsi :
     * la liste sert a rendre un null explicite equivalent a une cle absente,
     * pour que la base applique son defaut au lieu de refuser l'insertion.
     * Voir {@see RepliSurLaValeurParDefaut}.
     *
     * @var list<string>
     */
    protected const COLONNES_A_VALEUR_PAR_DEFAUT = [
        'currency', 'vat_rate', 'stock_quantity', 'stock_alert_threshold', 'is_active',
    ];

    protected function prepareForValidation(): void
    {
        $this->ignorerLesNulls(self::COLONNES_A_VALEUR_PAR_DEFAUT);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Pour l'update, on ignore le SKU de l'accessoire en cours de modification
        $id = $this->route('accessory')?->id ?? $this->route('accessory');

        return [
            'sku'                   => ['nullable', 'string', 'max:60', Rule::unique('accessories', 'sku')->ignore($id)->whereNotNull('sku')],
            'name'                  => ['required', 'string', 'max:180'],
            'description'           => ['nullable', 'string'],
            'category'              => ['required', Rule::in(AccessoryCategory::values())],
            'manufacturer_id'       => ['nullable', 'exists:manufacturers,id'],
            'weight_kg'             => ['nullable', 'numeric', 'min:0'],
            'dimensions'            => ['nullable', 'string', 'max:80'],
            'warranty_months'       => ['nullable', 'integer', 'min:0', 'max:120'],
            'cost_price'            => ['nullable', 'numeric', 'min:0'],
            'selling_price'         => ['required', 'numeric', 'min:0'],
            'currency'              => ['nullable', 'string', 'size:3'],
            'vat_rate'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity'        => ['nullable', 'integer', 'min:0'],
            'stock_alert_threshold' => ['nullable', 'integer', 'min:0'],
            'storage_location'      => ['nullable', 'string', 'max:60'],
            'location_id'           => ['nullable', 'exists:locations,id'],
            'is_active'             => ['boolean'],

            // Compatibilités déclarées avec des modèles de véhicules
            'fitments'                         => ['nullable', 'array'],
            'fitments.*.vehicle_model_id'      => ['required_with:fitments', 'exists:vehicle_models,id'],
            'fitments.*.trim_id'               => ['nullable', 'exists:trims,id'],
            // La table porte ces trois colonnes et la ressource les rend :
            // les omettre ici les ferait disparaitre a chaque modification.
            'fitments.*.engine_type_id'        => ['nullable', 'exists:engine_types,id'],
            'fitments.*.drivetrain_id'         => ['nullable', 'exists:drivetrains,id'],
            'fitments.*.notes'                 => ['nullable', 'string', 'max:2000'],
            'fitments.*.year_from'             => ['nullable', 'integer', 'min:1950'],
            'fitments.*.year_to'               => ['nullable', 'integer', 'min:1950', 'gte:fitments.*.year_from'],
        ];
    }
}
