<?php

namespace App\Http\Requests;

use App\Enums\PartCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres et pagination des pieces compatibles avec un vehicule donne.
 *
 * Deux routes, un seul jeu de parametres : le garage du client
 * (/my/vehicles/{ownedVehicle}/compatible-parts) et la fiche vehicule du
 * back-office (/vehicles/{vehicle}/compatible-parts). Le code des deux etait
 * le meme au caractere pres, les defauts aussi.
 *
 * Ces parametres partaient bruts vers le moteur de compatibilite, et chacun
 * echouait en silence plutot qu'en erreur.
 *
 * `condition` arrivait tel quel dans un where() : « neuve » au lieu de « new »
 * ne declenchait rien du tout, la requete remontait zero ligne et l'ecran
 * affichait « aucune piece compatible » — le meme rendu qu'un vehicule
 * reellement non couvert. Un filtre casse etait donc indiscernable d'un
 * catalogue vide.
 *
 * `category_id` avait le meme defaut a un niveau au-dessus : un identifiant
 * inexistant produisait une liste de descendantes vide, donc un whereIn vide,
 * donc zero resultat sans le moindre signal.
 *
 * `per_page` n'avait aucun plafond. L'application demande 500 pieces d'un coup
 * pour badger les tuiles de la liste sans une requete par piece ; rien
 * n'empechait d'en demander cent mille, ni le serveur de les hydrater.
 */
class CompatiblePartsRequest extends FormRequest
{
    /**
     * Plafond de pagination.
     *
     * Cale sur le seul appel qui demande beaucoup : GarageRepository
     * .compatiblePartIds() envoie per_page=500 pour recuperer d'un coup les
     * identifiants compatibles. Au-dela, aucun ecran n'en a l'usage.
     */
    public const MAX_PER_PAGE = 500;

    public function rules(): array
    {
        return $this->paginationRules() + [
            'category_id' => ['nullable', 'integer', 'exists:part_categories,id'],
            'in_stock'    => ['nullable', 'boolean'],
            'condition'   => ['nullable', Rule::in(PartCondition::values())],
        ];
    }

    public function messages(): array
    {
        return $this->paginationMessages() + [
            'category_id.integer' => 'La catégorie se désigne par son identifiant.',
            'category_id.exists'  => "Cette catégorie de pièces n'existe pas.",
            'in_stock.boolean'    => 'Le filtre « en stock » vaut 0 ou 1.',
            'condition.in'        => "Cet état de pièce n'existe pas : une pièce est neuve, reconditionnée ou d'occasion.",
        ];
    }

    /** Filtres a passer au moteur de compatibilite, une fois valides. */
    public function filters(): array
    {
        return $this->safe()->only(['category_id', 'in_stock', 'condition']);
    }

    /** Taille de page validee. Vingt par defaut, comme le reste de l'API. */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }

    /** Partagees avec la recherche par criteres, qui pagine la meme collection. */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function paginationMessages(): array
    {
        return [
            'per_page.integer' => 'Le nombre de pièces par page doit être un entier.',
            'per_page.min'     => 'Une page contient au moins une pièce.',
            'per_page.max'     => 'Une page ne peut pas dépasser :max pièces.',
            'page.integer'     => 'Le numéro de page doit être un entier.',
            'page.min'         => 'La numérotation des pages commence à 1.',
        ];
    }
}
