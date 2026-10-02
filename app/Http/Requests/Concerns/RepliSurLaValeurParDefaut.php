<?php

namespace App\Http\Requests\Concerns;

/**
 * Un null explicite sur une colonne a valeur par defaut vaut une absence.
 *
 * Les colonnes de devise, de taxe, de stock et d'activite du catalogue sont
 * NOT NULL avec un `default()` : la base sait quoi faire quand la cle manque,
 * pas quand elle arrive a null. Les regles les declarent pourtant facultatives
 * — un null traversait donc la validation pour aller heurter la contrainte
 * SQL, et rendre un 500 la ou le client attendait soit la valeur par defaut,
 * soit un message nommant le champ.
 *
 * On retient la premiere lecture. Un client qui serialise son formulaire
 * entier — ce que rend n'importe quel modele a champs optionnels, et ce que
 * `ConvertEmptyStringsToNull` produit de toute facon pour un champ texte
 * laisse vide — n'affirme rien par ce null : il n'a rien a dire. Retirer la
 * cle avant la validation rend ce silence a la base, qui applique son defaut.
 * L'alternative (exiger une valeur, repondre 422) aurait refuse une saisie
 * que personne n'a remplie, et oblige chaque client a elaguer ses nulls avant
 * l'envoi — exactement le contournement que l'application mobile tient a la
 * main aujourd'hui.
 *
 * Deux consequences a connaitre :
 * - a la creation, un null vaut le defaut de la colonne ;
 * - a la modification, un null laisse la valeur en place, puisque `update()`
 *   ne touche que les cles recues. « Je n'ai rien a dire » ne remet pas une
 *   colonne deja renseignee a son defaut.
 */
trait RepliSurLaValeurParDefaut
{
    /**
     * Retire des donnees a valider les colonnes recues a null.
     *
     * @param  list<string>  $colonnes
     */
    protected function ignorerLesNulls(array $colonnes): void
    {
        $source = $this->getInputSource();
        $recus  = $source->all();

        foreach ($colonnes as $colonne) {
            // array_key_exists plutot que isset : c'est le null qu'on cherche.
            if (array_key_exists($colonne, $recus) && $recus[$colonne] === null) {
                $source->remove($colonne);
            }
        }
    }
}
