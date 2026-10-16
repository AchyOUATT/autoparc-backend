<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * L'envoi d'un passage a la pompe.
 *
 * `is_full` est REQUIS, et c'est la regle la plus importante du formulaire. Le
 * serveur ne devine pas la nature du passage : un defaut cote serveur
 * fermerait les mesures sur des demi-pleins, chaque intervalle se bornerait sur
 * un niveau de reservoir inconnu, et le l/100 affiche serait faux sans qu'aucune
 * erreur ne soit levee. Un client qui oublie le champ doit se faire refuser
 * bruyamment plutot que produire un chiffre credible et faux. La table est
 * d'ailleurs sans valeur par defaut pour la meme raison ; le defaut
 * d'interface, lui, est le demi-plein.
 *
 * Les bornes de litres et de montant ne sont pas de la mefiance de principe :
 * la saisie se fait debout a la station, au clavier d'un telephone, et le
 * chiffre du compteur voisine celui des litres. 200 litres attrape le
 * kilometrage tape dans le champ des litres, et 1 000 000 FCFA attrape le
 * compteur tape dans le champ du montant.
 *
 * `filled_at` accepte le passe sans limite, contrairement aux trente jours du
 * controle avant voyage : on saisit un carnet de pompe en retard, parfois de
 * plusieurs mois, et le calcul s'en accommode puisqu'il se classe au compteur
 * et non a la date. Le futur, lui, est toujours une faute de frappe.
 */
class StoreFuelFillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy 'update' du vehicule verifiee dans le controleur
    }

    public function rules(): array
    {
        return [
            // La borne haute est celle de la colonne (unsignedInteger), pas une
            // regle metier : sans elle un compteur aberrant passerait la
            // validation pour tomber en erreur de base, et le telephone
            // recevrait un 500 la ou un 422 lui dit quoi corriger.
            'mileage_km' => ['required', 'integer', 'min:0', 'max:4294967295'],

            'litres' => ['required', 'numeric', 'min:1', 'max:200'],

            'is_full' => ['required', 'boolean'],

            'amount_fcfa'     => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'price_per_litre' => ['nullable', 'numeric', 'min:1', 'max:5000'],

            'filled_at' => ['nullable', 'date', 'before_or_equal:today'],

            // 64 caracteres, la largeur exacte de la colonne : une cle plus
            // longue serait tronquee en base, et deux envois distincts
            // deviendraient le meme passage.
            'client_reference' => ['nullable', 'string', 'max:64'],
            'note'             => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_full.required' => 'Il faut dire si la pompe s’est arrêtée toute seule : sans cela, la consommation ne peut pas être calculée.',
            'litres.required'  => 'Le nombre de litres est obligatoire.',
            'litres.min'       => 'Un passage à la pompe fait au moins 1 litre.',
            'litres.max'       => 'Plus de 200 litres : c’est sans doute le kilométrage qui a été saisi ici.',
            'mileage_km.required' => 'Le kilométrage relevé à la pompe est obligatoire : c’est lui qui ordonne les passages.',
            'filled_at.before_or_equal' => 'La date du passage ne peut pas être dans le futur.',
            'amount_fcfa.max'  => 'Plus de 1 000 000 FCFA : c’est sans doute le kilométrage qui a été saisi ici.',
        ];
    }
}
