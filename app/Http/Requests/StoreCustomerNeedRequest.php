<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerNeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firebase_uid'     => ['nullable', 'string', 'max:128'],
            'type'             => ['nullable', Rule::in(['vehicle', 'part', 'accessory'])],

            'description'      => ['required', 'string', 'max:1000'],
            'budget_max'       => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'size:3'],

            // ── Critères véhicule (tous optionnels) ───────────────────
            'brand_id'         => ['nullable', 'exists:brands,id'],
            'vehicle_model_id' => ['nullable', 'exists:vehicle_models,id'],
            'vehicle_type'     => ['nullable', Rule::in(['passenger', 'utility', 'heavy'])],
            'body_style'       => ['nullable', Rule::in([
                'sedan', 'hatchback', 'suv', 'estate', 'coupe', 'convertible',
                'pickup', 'van', 'minibus', 'bus', 'truck', 'other',
            ])],
            'year_min'         => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'year_max'         => ['nullable', 'integer', 'min:1950', 'max:'.now()->year, 'gte:year_min'],

            // ── Critères pièce détachée (tous optionnels) ─────────────
            'part_category_id'    => ['nullable', 'exists:part_categories,id'],
            'oem_number'          => ['nullable', 'string', 'max:100'],

            // ── Critères accessoire (tous optionnels) ─────────────────
            'accessory_category'  => ['nullable', Rule::in([
                'esthetique', 'confort', 'securite', 'multimedia', 'utilitaire',
            ])],

            // ── Commun pièce + accessoire ─────────────────────────────
            'need_manufacturer_id' => ['nullable', 'exists:manufacturers,id'],

            // ── DE QUOI RAPPELER, ET POURQUOI CE N'ETAIT PAS LA ─────────────
            //
            // Ces trois champs existaient en base, le modele Flutter les
            // LISAIT et la liste du personnel les AFFICHAIT — mais aucun
            // n'etait valide ici. `CustomerNeed::create($request->validated())`
            // les jetait donc en silence : un numero envoye n'arrivait jamais
            // en base.
            //
            // QUI EST INJOIGNABLE, EXACTEMENT. Pas « un visiteur anonyme » :
            // l'ecran de depot exige une connexion. Mais son garde,
            // `!auth.isAuthenticated`, laisse passer le PERSONNEL autant que
            // les clients, et le `firebase_uid` ne part que `if (isClient)`.
            // Un membre du personnel qui saisit la demande de quelqu'un au
            // comptoir n'envoyait donc aucun contact — et c'est le cas le plus
            // courant. S'y ajoute l'appel direct a l'API, cette route n'ayant
            // aucun middleware d'authentification.
            'contact_name'  => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'string', 'email', 'max:180'],

            // PLUSIEURS numeros, parce qu'au Burkina Faso un acheteur donne
            // volontiers deux lignes de deux operateurs : l'une passe quand
            // l'autre ne passe pas.
            //
            // `required` seulement quand le drapeau est arme. L'application
            // PUBLIEE n'envoie aucun contact : l'exiger aujourd'hui ferait
            // repondre 422 a chaque depot venant d'un telephone deja installe,
            // et un besoin est une demande d'acheteur — exactement ce qu'on ne
            // veut pas perdre. Voir `config/boutique.php` pour l'ordre
            // d'armement.
            'contact_phones' => [
                config('boutique.besoin_telephone_obligatoire') ? 'required' : 'nullable',
                'array', 'min:1', 'max:3',
            ],
            'contact_phones.*' => ['required', 'string', 'min:6', 'max:30'],
        ];
    }
}
