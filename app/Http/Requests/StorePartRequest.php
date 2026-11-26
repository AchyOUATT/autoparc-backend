<?php

namespace App\Http\Requests;

use App\Enums\PartCondition;
use App\Enums\PartType;
use App\Http\Requests\Concerns\RepliSurLaValeurParDefaut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartRequest extends FormRequest
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
        // An older editor may echo the API's compatibility sentinel.
        // Store absence, not an enum invented from a presentation value.
        if ($this->input('type') === 'unknown') {
            $this->merge(['type' => null]);
        }

        // Codes marche : « us », « JP » → USA, JPN. Un code inconnu reste
        // tel quel pour que la regle `in` le refuse.
        $fitments = $this->input('fitments');
        if (is_array($fitments)) {
            foreach ($fitments as $i => $fitment) {
                if (is_array($fitment) && array_key_exists('market_codes', $fitment)) {
                    $fitments[$i]['market_codes'] = \App\Support\FitmentRestrictionInput::marketCodes($fitment['market_codes']);
                }
            }
            $this->merge(['fitments' => $fitments]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('fitments', []) as $i => $fitment) {
                if (! is_array($fitment)) {
                    continue;
                }
                if (filter_var($fitment['chassis_restricted'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    && trim((string) ($fitment['chassis_code'] ?? '')) === '') {
                    $validator->errors()->add("fitments.$i.chassis_code", __('Un châssis restrictif exige au moins un code châssis.'));
                }
                foreach (['month_from' => 'year_from', 'month_to' => 'year_to'] as $mois => $annee) {
                    if (($fitment[$mois] ?? null) !== null && ($fitment[$annee] ?? null) === null) {
                        $validator->errors()->add("fitments.$i.$mois", __('Un mois de borne exige son année.'));
                    }
                }
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('part')?->id ?? $this->route('part');

        return [
            'sku'                    => ['nullable', 'string', 'max:60', Rule::unique('parts', 'sku')->ignore($id)->whereNotNull('sku')],
            'name'                   => ['required', 'string', 'max:180'],
            'description'            => ['nullable', 'string'],
            'part_category_id'       => ['required', 'exists:part_categories,id'],
            'manufacturer_id'        => ['nullable', 'exists:manufacturers,id'],
            'manufacturer_reference' => ['nullable', 'string', 'max:80'],
            'type'                   => ['sometimes', 'nullable', Rule::in(PartType::values())],
            'condition'              => ['required', Rule::in(PartCondition::values())],
            'donor_vehicle_id'       => ['nullable', 'exists:vehicles,id', 'required_if:condition,used'],
            'weight_kg'              => ['nullable', 'numeric', 'min:0'],
            'dimensions'             => ['nullable', 'string', 'max:80'],
            'warranty_months'        => ['nullable', 'integer', 'min:0', 'max:120'],
            'cost_price'             => ['nullable', 'numeric', 'min:0'],
            'selling_price'          => ['required', 'numeric', 'min:0'],
            'currency'               => ['nullable', 'string', 'size:3'],
            'vat_rate'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity'         => ['nullable', 'integer', 'min:0'],
            'stock_alert_threshold'  => ['nullable', 'integer', 'min:0'],
            'storage_location'       => ['nullable', 'string', 'max:60'],
            'location_id'            => ['nullable', 'exists:locations,id'],
            'city'                   => ['nullable', 'string', 'max:100'],
            'site'                   => ['nullable', 'string', 'max:120'],
            'is_active'              => ['boolean'],

            // Numeros OEM couverts par la piece
            'oem_numbers'              => ['array'],
            'oem_numbers.*.number'     => ['required_with:oem_numbers', 'string', 'max:60'],
            'oem_numbers.*.brand_id'   => ['nullable', 'exists:brands,id'],
            'oem_numbers.*.is_primary' => ['boolean'],

            // Compatibilites directes
            'fitments'                     => ['array'],
            'fitments.*.vehicle_model_id'  => ['required_with:fitments', 'exists:vehicle_models,id'],
            'fitments.*.trim_id'           => ['nullable', 'exists:trims,id'],
            'fitments.*.engine_type_id'    => ['nullable', 'exists:engine_types,id'],
            'fitments.*.drivetrain_id'     => ['nullable', 'exists:drivetrains,id'],
            'fitments.*.engine_code'       => ['nullable', 'string', 'max:50'],
            'fitments.*.chassis_code'      => ['nullable', 'string', 'max:80'],
            'fitments.*.year_from'         => ['nullable', 'integer', 'min:1950'],
            'fitments.*.year_to'           => ['nullable', 'integer', 'min:1950', 'gte:fitments.*.year_from'],
            'fitments.*.position'          => ['nullable', 'string', 'max:60'],
            // Une colonne absente de ces regles est une colonne perdue :
            // `validated()` l'ecarte, et l'ecran d'edition la vide au premier
            // enregistrement. C'est ainsi que les notes disparaissaient.
            'fitments.*.notes'             => ['nullable', 'string', 'max:2000'],

            // Criteres restrictifs (voir FitmentRestrictions). Null = aucune
            // restriction. Le code chassis seul reste informatif.
            'fitments.*.chassis_restricted' => ['nullable', 'boolean'],
            'fitments.*.market_codes'       => ['nullable', 'array'],
            'fitments.*.market_codes.*'     => ['string', Rule::in(\App\Support\Market::codes())],
            'fitments.*.month_from'         => ['nullable', 'integer', 'between:1,12'],
            'fitments.*.month_to'           => ['nullable', 'integer', 'between:1,12'],
            // Preuve de la compatibilite (voir FitmentEvidence).
            // Un niveau qui peut confirmer exige au moins une URL source ;
            // human_verified exige aussi la date de validation, et le compte
            // qui valide est posé par le serveur (jamais par le client).
            'fitments.*.evidence_level'     => ['nullable', Rule::in(\App\Support\FitmentEvidence::LEVELS)],
            'fitments.*.evidence_sources'   => ['nullable', 'array', 'max:20',
                'required_if:fitments.*.evidence_level,'.implode(',', \App\Support\FitmentEvidence::SUFFICIENT)],
            'fitments.*.evidence_sources.*' => ['string', 'url:http,https', 'max:500'],
            'fitments.*.verified_on'        => ['nullable', 'date', 'before_or_equal:today',
                'required_if:fitments.*.evidence_level,'.\App\Support\FitmentEvidence::HUMAN_VERIFIED],
            'fitments.*.verified_by_id'     => ['prohibited'],
        ];
    }
}
