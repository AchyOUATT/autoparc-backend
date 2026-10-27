<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Le prix qu'un acheteur paie AUJOURD'HUI, promotion comprise.
 *
 * ── POURQUOI UN CALCUL ET NON UNE COLONNE ─────────────────────────────────
 *
 * Une promotion a une fin, et rien ici ne peut l'executer : l'hebergement n'a
 * ni shell ni tache planifiee fiable. Une colonne « prix courant » qu'on
 * ecrirait au debut de la promotion resterait basse pour toujours, faute de
 * quelqu'un pour la remettre.
 *
 * Calcule, le prix effectif expire TOUT SEUL : passee la date de fin, la
 * condition est fausse et le prix normal ressort. L'expiration n'est plus une
 * action, c'est un fait de lecture.
 *
 * ── POURQUOI IL FAUT L'EMPLOYER PARTOUT, ET PAS SEULEMENT A L'AFFICHAGE ───
 *
 * Parce qu'un prix affiche qui ne correspond ni au filtre ni au tri est pire
 * qu'une absence de promotion. Mesure faite sur le catalogue actuel :
 *
 *   — `?price_max=` et `?price_min=` comparent `sale_price` / `selling_price` ;
 *   — le tri par prix classe sur ces memes colonnes (voir `TriPublic`) ;
 *   — le panier des pieces et accessoires facture `price_including_vat`,
 *     derive de `selling_price` (`Part::getPriceIncludingVatAttribute`).
 *
 * Laisser l'un de ces trois sur la colonne brute, c'est annoncer une remise
 * qu'on ne retrouve pas dans la liste filtree — ou pire, qu'on n'encaisse pas.
 *
 * ── POURQUOI DES LIAISONS ET NON UNE DATE INTERPOLEE ─────────────────────
 *
 * `now()` ne vient pas de l'utilisateur, donc l'interpoler ne serait pas une
 * faille aujourd'hui. Mais une expression SQL assemblee par concatenation est
 * un gabarit : le jour ou quelqu'un y glissera une borne venue d'une requete,
 * rien dans le code ne l'arretera. Les liaisons ferment cette porte avant
 * qu'elle ne s'ouvre.
 */
final class PrixEffectif
{
    /**
     * L'expression SQL du prix effectif, avec ses deux marques de liaison.
     *
     * Les bornes sont INCLUSIVES des deux cotes : une promotion annoncee
     * « jusqu'au 31 » doit valoir le 31. Exclure la fin ferait disparaitre la
     * remise un jour plus tot que ce que le vendeur a annonce a ses acheteurs.
     *
     * Un `deal_starts_at` nul vaut « deja commencee » et un `deal_ends_at` nul
     * « sans fin connue » : la validation exige les deux, mais une ligne
     * ecrite directement en base ne doit pas faire disparaitre un prix.
     */
    public static function sql(string $colonne): string
    {
        // ── POURQUOI CE `CAST`, ET CE QU'IL COUTAIT DE L'OUBLIER ──────────
        //
        // Un `CASE` n'a PAS d'affinite de type sous SQLite : son resultat est
        // compare comme du TEXTE, meme quand les deux branches sont des
        // colonnes decimales. La comparaison devient alors lexicographique.
        //
        // Mesure faite, garde retire : un filtre « moins de 4 500 000 »
        // trouvait bien un vehicule a 4 000 000 — « 4000000 » < « 4500000 »
        // par accident, les deux nombres ayant la meme longueur — mais
        // EXCLUAIT un vehicule a 900 000, puisque « 9 » > « 4 ». Le defaut se
        // cachait derriere les cas ou il ne se voyait pas.
        //
        // Le `CAST` rend l'expression numerique, et SQLite applique alors
        // cette affinite a l'autre operande. Sous MySQL les colonnes sont deja
        // DECIMAL et le `CASE` garde leur type : le cast n'y change rien, et
        // c'est bien ainsi — la justesse ne doit pas dependre du moteur.
        return '(CAST(CASE WHEN deal_price IS NOT NULL'
            .' AND (deal_starts_at IS NULL OR deal_starts_at <= ?)'
            .' AND (deal_ends_at IS NULL OR deal_ends_at >= ?)'
            ." THEN deal_price ELSE $colonne END AS DECIMAL(14,2)))";
    }

    /** Les deux bornes de l'expression ci-dessus, dans l'ordre. */
    public static function liaisons(): array
    {
        $maintenant = now();

        return [$maintenant, $maintenant];
    }

    /**
     * `?price_min=` / `?price_max=` sur le prix effectif.
     *
     * Sans cela, un acheteur qui demande « moins de 4 000 000 » ne verrait pas
     * un vehicule a 4 500 000 promu a 3 900 000 : la remise serait affichee
     * partout sauf la ou elle sert, c'est-a-dire dans la recherche.
     */
    public static function filtrer(Builder $query, string $colonne, string $operateur, $valeur): Builder
    {
        return $query->whereRaw(
            self::sql($colonne)." $operateur ?",
            [...self::liaisons(), $valeur],
        );
    }

    /**
     * Le tri par prix, sur le prix effectif.
     *
     * Classer sur la colonne brute placerait une annonce promue a 3 900 000
     * apres une annonce a 4 200 000, dans une liste rangee par prix croissant.
     */
    public static function trier(Builder $query, string $colonne, string $sens): Builder
    {
        return $query->orderByRaw(
            self::sql($colonne).' '.($sens === 'asc' ? 'asc' : 'desc'),
            self::liaisons(),
        );
    }

    /**
     * La condition « une promotion est active maintenant ».
     *
     * Employee par `?deal=1`, qui doit rendre les promotions EN COURS — pas
     * celles programmees pour la semaine prochaine, ni celles d'avant-hier.
     */
    public static function fenetreOuverte(Builder $query): Builder
    {
        return $query->whereNotNull('deal_price')
            ->where(fn ($q) => $q->whereNull('deal_starts_at')->orWhere('deal_starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('deal_ends_at')->orWhere('deal_ends_at', '>=', now()));
    }

    /**
     * Le prix effectif d'un modele deja charge.
     *
     * Le meme verdict que l'expression SQL, en PHP : les deux doivent toujours
     * s'accorder, sans quoi la carte afficherait un prix que le filtre ignore.
     * C'est pour cela qu'un banc les compare l'un a l'autre sur les memes
     * donnees.
     */
    public static function de(object $modele, string $colonne): ?float
    {
        if (! self::enCours($modele)) {
            $prix = $modele->getAttribute($colonne);

            return $prix === null ? null : (float) $prix;
        }

        return (float) $modele->getAttribute('deal_price');
    }

    /** La fenetre est-elle ouverte sur ce modele, maintenant ? */
    public static function enCours(object $modele): bool
    {
        if ($modele->getAttribute('deal_price') === null) {
            return false;
        }

        $debut = $modele->getAttribute('deal_starts_at');
        $fin = $modele->getAttribute('deal_ends_at');

        return ($debut === null || $debut <= now())
            && ($fin === null || $fin >= now());
    }
}
