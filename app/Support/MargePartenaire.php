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
     * @param  array<string, mixed>|null  $detailExistant  le calcul conserve sur l'annonce
     * @return array<string, mixed> les memes donnees, prix majores, plus `partner_pricing`
     */
    public static function appliquer(array $donnees, array $champs, ?array $detailExistant = null): array
    {
        $modifies = array_intersect($champs, array_keys($donnees));

        // A reduced form may edit a description or a deposit without changing
        // a price. Preserve the complete stored calculation in that case.
        if ($modifies === []) {
            return $donnees;
        }

        $taux = self::taux();
        $proposes = array_intersect_key($detailExistant['prix_proposes'] ?? [], array_flip($champs));
        $tauxParChamp = [];
        foreach (array_keys($proposes) as $champ) {
            $tauxParChamp[$champ] = (float) ($detailExistant['taux_par_champ'][$champ] ?? $detailExistant['taux'] ?? 0);
        }

        foreach ($modifies as $champ) {
            // `array_key_exists` et non `isset` : un prix explicitement nul
            // est une REPONSE — « ce vehicule n'est pas a vendre » — et doit
            // traverser sans qu'on lui invente une majoration.
            if ($donnees[$champ] === null) {
                unset($proposes[$champ], $tauxParChamp[$champ]);

                continue;
            }

            $propose = (float) $donnees[$champ];
            $proposes[$champ] = $propose;
            $tauxParChamp[$champ] = $taux;
            $donnees[$champ] = self::majorer($propose, $taux);
        }

        // Explicit nulls remove only their own proposed prices. If the last
        // tracked price is cleared, do not leave stale pricing metadata.
        if ($proposes === []) {
            if ($detailExistant !== null) {
                $donnees['partner_pricing'] = null;
            }

            return $donnees;
        }

        // Older clients display the global rate next to the main sale price.
        // Keep that rate truthful when only a rental price changes at today's
        // rate; the per-field map preserves both calculations independently.
        $principal = array_values(array_intersect($champs, array_keys($proposes)))[0];
        $donnees['partner_pricing'] = [
            'taux' => $tauxParChamp[$principal],
            'taux_par_champ' => $tauxParChamp,
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
