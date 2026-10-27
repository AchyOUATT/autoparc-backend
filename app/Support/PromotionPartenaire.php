<?php

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * Ce qu'une promotion partenaire doit respecter pour etre enregistree.
 *
 * ── LE CADRE, DECIDE PAR LE PROPRIETAIRE ──────────────────────────────────
 *
 * Un partenaire cree ses promotions LIBREMENT : pas d'approbation prealable,
 * le personnel est notifie. Faire valider chaque promotion reviendrait a n'en
 * avoir aucune — l'approbation arriverait apres l'occasion qu'elle visait.
 *
 * Ce choix laisse un trou que ces regles ferment : sans plafond, un partenaire
 * affiche son vehicule a 1 franc et occupe le haut de la bande des bonnes
 * affaires, la notification ne l'apprenant qu'APRES coup.
 *
 * ── SUR QUELS NOMBRES LE PLAFOND SE MESURE ────────────────────────────────
 *
 * Sur les prix que le VENDEUR a tapes, avant la marge de la boutique.
 *
 * Ce n'est pas indifferent. La marge etant un pourcentage uniforme, le rapport
 * se conserve presque exactement — 4 500 000 et 2 700 000 proposes deviennent
 * 4 950 000 et 2 970 000 a 10 % de marge, soit 40 % de remise dans les deux
 * cas. Presque : les arrondis au franc font deriver le rapport de quelques
 * millemes, assez pour qu'un refus tombe d'un cote et pas de l'autre sur une
 * remise pile a 40 %.
 *
 * On mesure donc sur les nombres que le vendeur voit et comprend. Un refus qui
 * porte sur des chiffres qu'il n'a pas tapes serait incomprehensible.
 *
 * ── CE QUE LE PLAFOND NE FAIT PAS ─────────────────────────────────────────
 *
 * Il ne juge pas le prix NORMAL. Un partenaire qui gonfle son prix habituel
 * pour afficher « -40 % » reste possible, et aucun pourcentage ne l'empeche :
 * c'est au personnel, notifie, de le voir. Le plafond borne l'ECART, pas la
 * sincerite du point de depart.
 */
final class PromotionPartenaire
{
    /** Le plafond du jour, en pourcentage de remise. */
    public static function remiseMaximale(): float
    {
        return max(0.0, (float) config('partenaires.promotion_remise_max_pourcentage', 40));
    }

    /** La remise d'une promotion, en pourcentage du prix normal. */
    public static function remise(float $normal, float $promotionnel): float
    {
        if ($normal <= 0) {
            return 0.0;
        }

        return ($normal - $promotionnel) / $normal * 100;
    }

    /**
     * Les regles de validation, a fusionner dans celles de la requete.
     *
     * `deal_price` est nullable : l'envoyer a null RETIRE la promotion, ce qui
     * doit rester possible sans attendre sa date de fin.
     *
     * Les deux bornes sont exigees DES QU'il y a un prix : une promotion sans
     * fin n'est pas une promotion, c'est un prix — et elle ne pourrait plus
     * expirer d'elle-meme, ce qui est tout l'interet de la mecanique.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function regles(): array
    {
        return [
            'deal_price' => ['nullable', 'numeric', 'min:0'],
            'deal_starts_at' => ['nullable', 'required_with:deal_price', 'date'],
            'deal_ends_at' => ['nullable', 'required_with:deal_price', 'date', 'after:deal_starts_at'],
        ];
    }

    /**
     * Les verifications croisees, une fois les champs valides un a un.
     *
     * @param  string  $champPrixNormal  `sale_price` ou `selling_price`
     * @param  float|null  $prixNormalEnregistre  le prix deja en base, pour une
     *                     modification qui ne renvoie pas le prix
     */
    public static function verifier(
        Validator $validator,
        array $entrees,
        string $champPrixNormal,
        ?float $prixNormalEnregistre = null,
        ?float $promotionEnregistree = null,
    ): void {
        // ── LE GARDE PORTE SUR L'ETAT FINAL, PAS SUR LE CORPS DE LA REQUETE ──
        //
        // Sortir des que `deal_price` est absent ouvrait le plafond en DEUX
        // requetes, et le trou a ete rejoue :
        //
        //   POST { sale_price: 4 500 000, deal_price: 2 700 000 }  -> 201
        //          (remise de 40 % pile, acceptee)
        //   PUT  { sale_price: 10 000 000 }                        -> 200
        //
        // La seconde ne parle pas de promotion, donc rien ne la verifiait. La
        // carte affichait alors « 10 000 000 barre / 2 700 000 », soit 73 % de
        // remise, et `?price_max=3000000` rendait un vehicule dont le prix
        // normal est de dix millions.
        //
        // Le meme trou dans l'autre sens : baisser le prix normal SOUS la
        // promotion en cours donne un prix effectif SUPERIEUR au prix normal,
        // une remise negative, et un vehicule EXCLU d'un filtre que son prix
        // normal satisfait.
        //
        // On compare donc ce que la ligne VAUDRA, en reprenant de l'annonce
        // enregistree ce que la requete ne dit pas.
        $promo = array_key_exists('deal_price', $entrees)
            ? $entrees['deal_price']
            : $promotionEnregistree;

        if ($promo === null || $promo === '') {
            return;
        }

        $promo = (float) $promo;
        $normal = array_key_exists($champPrixNormal, $entrees) && $entrees[$champPrixNormal] !== null
            ? (float) $entrees[$champPrixNormal]
            : $prixNormalEnregistre;

        // ── UNE PROMOTION SUPPOSE UN PRIX NORMAL ──────────────────────────
        //
        // Un vehicule en location seule n'a pas de prix de vente : il n'y a
        // rien a remettre. Le dire franchement vaut mieux que d'enregistrer
        // une promotion qui ne s'afficherait nulle part.
        if ($normal === null || $normal <= 0) {
            $validator->errors()->add('deal_price',
                'Indiquez d abord un prix de vente : une promotion s applique a un prix.');

            return;
        }

        // Le champ sur lequel l'erreur se pose : celui que la requete a
        // ENVOYE. Signaler `deal_price` sur une requete qui ne parlait que du
        // prix de vente laisserait le vendeur chercher un champ absent de son
        // formulaire.
        $champ = array_key_exists('deal_price', $entrees) ? 'deal_price' : $champPrixNormal;
        $enCours = $champ !== 'deal_price';

        if ($promo >= $normal) {
            $validator->errors()->add($champ, $enCours
                ? 'Ce prix passerait sous votre promotion en cours. Terminez-la d abord.'
                : 'Le prix promotionnel doit etre inferieur au prix normal.');

            return;
        }

        $remise = self::remise($normal, $promo);
        $plafond = self::remiseMaximale();

        // ── UN PLAFOND A ZERO INTERDIT, IL N'OUVRE PAS ────────────────────
        //
        // La condition etait `$plafond > 0 && …`, de sorte que
        // `PARTNER_PROMO_MAX_DISCOUNT_PERCENT=0` desarmait le garde au lieu
        // de fermer les promotions. Un reglage lu comme « aucune remise
        // autorisee » les autorisait TOUTES, 99 % comprise — et le seul
        // moment ou quelqu'un le taperait serait justement pour les arreter.
        if ($remise > $plafond + 0.0001) {
            $validator->errors()->add($champ, $enCours
                ? sprintf(
                    'Avec votre promotion en cours, ce prix ferait une remise de %s %%, '
                    .'au-dela des %s %% autorises. Terminez la promotion avant de relever '
                    .'votre prix.',
                    self::nombre($remise),
                    self::nombre($plafond),
                )
                : sprintf(
                    'La remise ne peut pas depasser %s %% : celle-ci est de %s %%.',
                    self::nombre($plafond),
                    self::nombre($remise),
                ));
        }
    }

    /** « 40 » et non « 40.0 » ; « 42.5 » quand il le faut. */
    private static function nombre(float $valeur): string
    {
        $arrondi = round($valeur, 1);

        return $arrondi == (int) $arrondi ? (string) (int) $arrondi : (string) $arrondi;
    }
}
