<?php

namespace App\Support;

/**
 * La marge de la boutique sur les prix proposes par un partenaire.
 *
 * ── CE QUE CE SERVICE FAIT, ET CE QU'IL NE FAIT PAS ───────────────────────
 *
 * Il prend les prix qu'un partenaire vient de proposer, les augmente du taux
 * courant, et rend les DEUX : le prix a enregistrer, et le detail a conserver
 * avec l'annonce — le prix propose, le taux applique, la date.
 *
 * Il ne relit jamais le reglage pour AFFICHER. Le taux est fige dans
 * `partner_pricing` au moment de l'enregistrement, et c'est lui qu'on montre
 * ensuite. Sans cela, changer le reglage ferait bouger le prix de toutes les
 * annonces existantes — un acheteur verrait un prix changer sans que personne
 * n'y ait touche, et un lien de partage deja envoye deviendrait faux. Ce depot
 * a deja paye une page de partage qui annoncait un prix pour un vehicule
 * vendu ; on ne recommence pas.
 *
 * ── LES CHAMPS CONCERNES ─────────────────────────────────────────────────
 *
 * Tous les prix de vente et de location. PAS `rental_deposit` : une caution
 * est remboursee au locataire. En prendre une marge reviendrait a facturer une
 * somme qu'on rend, et le locataire recuperait moins qu'il n'a verse.
 *
 * `rental_mileage_limit_per_day` n'est pas un prix mais un kilometrage ; il
 * n'a rien a faire ici non plus.
 */
final class MargePartenaire
{
    /**
     * Les prix d'un vehicule, caution exclue.
     *
     * `deal_price` en fait partie : la boutique garde sa marge sur le prix
     * PROMOTIONNEL comme sur le prix normal. L'exclure reviendrait a lui faire
     * payer la remise du vendeur — et, le taux etant uniforme, la remise que
     * l'acheteur voit resterait la meme a quelques millemes pres.
     */
    public const CHAMPS_VEHICULE = [
        'sale_price', 'rental_daily_rate', 'rental_weekly_rate', 'rental_monthly_rate',
        'deal_price',
    ];

    /** Le prix d'une piece ou d'un accessoire, promotion comprise. */
    public const CHAMPS_ARTICLE = ['selling_price', 'deal_price'];

    /**
     * Le taux du jour, jamais negatif.
     *
     * `PARTNER_MARGIN_PERCENT=-10` se tape aussi vite que `10`, et sans ce
     * plancher il ferait vendre SOUS le prix que le partenaire a propose —
     * silencieusement, sur toutes les annonces enregistrees ensuite, et fige
     * dans chacune. La boutique paierait la difference sans qu'une seule ligne
     * ne le dise.
     *
     * Une remise deliberee existe deja, et elle a son propre outil : la
     * promotion, qui porte une duree et se lit sur la carte.
     */
    public static function taux(): float
    {
        return max(0.0, (float) config('partenaires.marge_pourcentage', 0));
    }

    /**
     * Les prix augmentes, et le detail a enregistrer avec l'annonce.
     *
     * @param  array<string, mixed>  $donnees  les champs valides
     * @param  list<string>  $champs  les prix a majorer
     * @return array<string, mixed>  les memes donnees, prix majores, plus
     *                               `partner_pricing`
     */
    public static function appliquer(array $donnees, array $champs): array
    {
        $taux = self::taux();
        $proposes = [];

        foreach ($champs as $champ) {
            // `array_key_exists` et non `isset` : un prix explicitement nul
            // est une REPONSE — « ce vehicule n'est pas a vendre » — et doit
            // traverser sans qu'on lui invente une majoration.
            if (! array_key_exists($champ, $donnees) || $donnees[$champ] === null) {
                continue;
            }

            $propose = (float) $donnees[$champ];
            $proposes[$champ] = $propose;
            $donnees[$champ] = self::majorer($propose, $taux);
        }

        // Aucun prix dans cette requete : on ne touche pas a
        // `partner_pricing`. Une modification qui ne porte que sur la
        // description ne doit pas effacer le detail de l'annonce, ni le
        // reecrire au taux du jour alors que le prix, lui, n'a pas bouge.
        if ($proposes === []) {
            return $donnees;
        }

        $donnees['partner_pricing'] = [
            'taux' => $taux,
            'prix_proposes' => $proposes,
            'applique_le' => now()->toIso8601String(),
        ];

        return $donnees;
    }

    /**
     * Le prix majore, arrondi au franc.
     *
     * Le franc CFA n'a pas de subdivision en usage : un prix a deux decimales
     * ne s'affiche nulle part et ne se paie pas. On arrondit donc a l'unite,
     * et au PLUS PROCHE plutot qu'au superieur — majorer systematiquement
     * ajouterait une pichenette que personne n'a decidee.
     */
    public static function majorer(float $prix, float $taux): float
    {
        return round($prix * (1 + $taux / 100));
    }
}
