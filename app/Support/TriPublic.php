<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Le tri des listes publiques, borne a une liste blanche.
 *
 * ── LES DEUX DEFAUTS QUE CECI CORRIGE ──────────────────────────────────────
 *
 * `sort_by` et `sort_dir` passaient BRUTS du parametre d'URL a `orderBy()`,
 * sur les vehicules, les pieces et les accessoires. Deux consequences, toutes
 * deux atteignables par un visiteur ANONYME.
 *
 * UN. `?sort_dir=nimportequoi` rend 500. La chaine arrive telle quelle dans la
 * clause SQL, le moteur refuse, et l'exception sort en erreur serveur sur une
 * route publique.
 *
 * DEUX. Trier sur une colonne absente de la reponse est une FUITE. Mesure du
 * 24/10/2026, faite colonne par colonne sur `main` : TROIS colonnes seulement
 * sont dans le schema sans etre dans aucune ressource publique —
 * `vehicles.purchase_price`, `parts.cost_price`, `accessories.cost_price`.
 * C'est-a-dire le prix d'achat. Un visiteur pouvait classer tout le catalogue
 * par prix d'achat croissant, donc par MARGE, sans qu'un seul de ces nombres
 * paraisse dans la reponse : l'ordre des identifiants suffit a les ranger, et
 * une poignee de requetes a les encadrer.
 *
 * Ces trois colonnes sont bien tenues pour secretes, et pas seulement par ce
 * fichier : `PagesProduitTest::test_aucune_donnee_interne_ne_fuit_dans_la_page`
 * cherche deja le prix d'achat ET la marge dans le HTML des pages publiques,
 * sous toutes leurs ecritures. Ce garde protege le CORPS de la reponse.
 * L'INTERFACE DE REQUETE restait, elle, grande ouverte sur les memes
 * colonnes — la meme fuite par l'autre bout.
 *
 * ── OU EXACTEMENT, CAR LA ROUTE COMPTE ─────────────────────────────────────
 *
 * `GET /api/vehicles`, `/api/parts` et `/api/accessories` sont reservees au
 * personnel (`auth:sanctum` + `EnsureStaff`) : la, trier sur le prix d'achat
 * ne divulgue rien, puisque l'appelant y a droit. Mais les MEMES methodes de
 * controleur servent `GET /api/catalog/vehicles`, `/api/catalog/parts` et
 * `/api/catalog/accessories`, qui ne portent que le groupe `api`. Aucune
 * authentification. C'est par la que les deux defauts etaient atteignables,
 * et c'est pourquoi le banc interroge `/api/catalog/...` et non les routes du
 * personnel — les viser aurait rendu 401 et prouve le contraire de ce qu'on
 * voulait montrer.
 *
 * ── CE QUE CE DEUXIEME DEFAUT N'EST PAS ────────────────────────────────────
 *
 * Il faut etre exact sur sa portee. `vin`, `stock_quantity` et
 * `storage_location` sont DEJA dans la reponse publique sur `main`, sans
 * condition d'authentification — `VehicleResource` ligne 17, `PartResource`
 * lignes 51 et 54, `AccessoryResource` lignes 49 et 52. Trier sur elles ne
 * divulgue donc rien aujourd'hui : c'est deja donne. La liste blanche les
 * exclut quand meme, parce que le jour ou une redaction les retirera du corps
 * de la reponse, les laisser triables rendrait cette redaction decorative.
 *
 * ── POURQUOI UNE LISTE BLANCHE, ET NON UN NETTOYAGE ────────────────────────
 *
 * Refuser les caracteres suspects laisserait passer tout nom de colonne
 * reel — c'est-a-dire exactement l'oracle. Seule l'enumeration de ce qui est
 * AUTORISE ferme la porte, parce qu'elle se raisonne sur ce qu'un acheteur a
 * le droit de comparer, pas sur ce qu'un attaquant pourrait ecrire.
 *
 * Le cout de cette severite est nul, mesure : l'application mobile n'envoie
 * JAMAIS `sort_by` ni `sort_dir` — aucune occurrence dans tout le code Dart.
 * Personne ne perd une fonctionnalite ; la liste est ouverte aux colonnes
 * qu'un client pourrait vouloir comparer, et a elles seules.
 *
 * ── CE QUI NE DOIT JAMAIS Y ENTRER ─────────────────────────────────────────
 *
 * Les trois colonnes de prix d'achat ci-dessus, et toute donnee de gestion :
 * `stock_quantity`, `storage_location`, `vin`, `plate_number`. Les ajouter
 * rouvrirait l'oracle, et aucun autre test de la suite ne le verrait tomber —
 * c'est pourquoi `TriPublicTest` les enumere nommement.
 */
final class TriPublic
{
    /** Les deux seuls sens possibles. Tout le reste vaut le defaut. */
    private const SENS = ['asc', 'desc'];

    /**
     * Applique un tri demande, s'il est autorise, sinon le tri par defaut.
     *
     * @param  array<int, string>  $permises  colonnes triables, liste blanche
     */
    public static function appliquer(
        Builder $query,
        Request $request,
        array $permises,
        string $defautColonne,
        string $defautSens = 'desc',
    ): Builder {
        $colonne = (string) $request->input('sort_by', $defautColonne);
        $sens    = strtolower((string) $request->input('sort_dir', $defautSens));

        // Un tri refuse n'est pas une erreur : la liste se rend quand meme,
        // dans son ordre par defaut. Rendre 422 apprendrait a l'appelant
        // quelles colonnes existent — le meme oracle par un autre chemin.
        if (! in_array($colonne, $permises, true)) {
            $colonne = $defautColonne;
        }

        if (! in_array($sens, self::SENS, true)) {
            $sens = $defautSens;
        }

        return $query->orderBy($colonne, $sens);
    }

    /** Les colonnes qu'un acheteur peut comparer sur une annonce de vehicule. */
    public static function vehicules(): array
    {
        return ['created_at', 'sale_price', 'rental_daily_rate', 'manufacturing_year', 'consumption_combined'];
    }

    /** Idem pour une piece ou un accessoire. */
    public static function articles(): array
    {
        return ['name', 'selling_price', 'created_at'];
    }
}
