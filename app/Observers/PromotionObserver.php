<?php

namespace App\Observers;

use App\Models\AppNotification;
use App\Support\PromotionPartenaire;
use Illuminate\Database\Eloquent\Model;

/**
 * Le personnel apprend qu'un partenaire vient de poser une promotion.
 *
 * ── POURQUOI UNE NOTIFICATION ET NON UNE APPROBATION ──────────────────────
 *
 * Le proprietaire a tranche : un partenaire cree ses promotions LIBREMENT.
 * Faire valider chaque promotion reviendrait a n'en avoir aucune —
 * l'approbation arriverait apres l'occasion qu'elle visait, et une vente flash
 * qui attend deux jours n'est plus une vente flash.
 *
 * Le plafond de remise (voir `PromotionPartenaire`) ferme l'abus mecanique ;
 * cette notification ferme le reste. Le personnel voit ce qui se passe dans sa
 * vitrine, et peut retirer une promotion qu'il juge deplacee.
 *
 * ── POURQUOI UN OBSERVATEUR ET NON UNE LIGNE DANS LE CONTROLEUR ───────────
 *
 * Il y a SIX chemins d'ecriture — trois inventaires, creation et modification
 * — et le controleur partenaire delegue a celui du personnel. Une ligne par
 * chemin serait oubliee une fois sur six, et l'oubli ne se verrait pas : la
 * promotion marcherait, simplement personne ne l'apprendrait.
 *
 * ── CE QUI DECLENCHE, ET CE QUI NE DECLENCHE PAS ──────────────────────────
 *
 * Le PRIX de la promotion change — pose, modifie, ou retire. Pas les dates
 * seules : decaler la fin d'une promotion deja annoncee n'est pas une nouvelle
 * offre, et notifier a chaque retouche noierait le signal.
 *
 * Et seulement sur une annonce de PARTENAIRE. Le personnel n'a pas a se
 * notifier lui-meme de ce qu'il vient de faire sur son propre stock.
 */
class PromotionObserver
{
    public function saved(Model $annonce): void
    {
        if ($annonce->getAttribute('owner_partner_account_id') === null) {
            return;
        }

        // ── POURQUOI LA CREATION SE TRAITE A PART ─────────────────────────
        //
        // `wasChanged()` ne vaut que sur une MODIFICATION : Eloquent n'appelle
        // `syncChanges()` que dans `performUpdate`, de sorte qu'apres un
        // `INSERT` la liste des changements est VIDE. Mesure faite, garde
        // retire : la promotion posee a la creation — le cas le plus courant,
        // puisqu'un vendeur annonce souvent son article en promotion — ne
        // notifiait personne, tandis que son retrait, lui, notifiait bien.
        //
        // A la creation il n'y a rien a comparer : une promotion est neuve des
        // qu'il y en a une.
        $change = $annonce->wasRecentlyCreated
            ? $annonce->getAttribute('deal_price') !== null
            : $annonce->wasChanged('deal_price');

        if (! $change) {
            return;
        }

        // ── SEULEMENT SUR UNE ANNONCE PUBLIEE ─────────────────────────────
        //
        // Une promotion posee sur un BROUILLON n'est dans la vitrine de
        // personne : rien a surveiller tant que l'annonce n'est pas publiee.
        //
        // Et sans cette condition, un vendeur qui compose son annonce en
        // plusieurs passes — pose la promotion, la retire, la repose —
        // produit une notification a chaque fois, pour chaque membre du
        // personnel. Le signal qui compte, « une promotion est en ligne », se
        // noie dans le bruit de la preparation.
        if ($annonce->getAttribute('partner_published_at') === null) {
            return;
        }

        $vendeur = $annonce->sellerAccount;
        $nom = $vendeur?->company_name ?: $vendeur?->contact_name ?: 'Un partenaire';
        $designation = $this->designation($annonce);
        $promo = $annonce->getAttribute('deal_price');

        if ($promo === null) {
            AppNotification::notifyAllStaff(
                'partner_promo_removed',
                'Promotion retiree',
                "$nom a retire la promotion sur $designation.",
                $this->donnees($annonce),
            );

            return;
        }

        $normal = (float) $annonce->getAttribute($this->champDePrixNormal($annonce));
        $remise = round(PromotionPartenaire::remise($normal, (float) $promo));

        AppNotification::notifyAllStaff(
            'partner_promo',
            'Nouvelle promotion partenaire',
            "$nom met $designation en promotion : -$remise % jusqu'au "
                .($annonce->getAttribute('deal_ends_at')?->format('d/m/Y') ?? 'nouvel ordre').'.',
            $this->donnees($annonce) + [
                'deal_price' => (float) $promo,
                'normal_price' => $normal,
                'discount_percent' => $remise,
                'ends_at' => $annonce->getAttribute('deal_ends_at')?->toIso8601String(),
            ],
        );
    }

    /**
     * De quoi ouvrir l'annonce sans chercher.
     *
     * Le personnel doit pouvoir juger et agir depuis la notification : le type
     * et l'identifiant suffisent a y aller d'un geste.
     */
    private function donnees(Model $annonce): array
    {
        return [
            'listing_type' => $this->type($annonce),
            'listing_id' => $annonce->getKey(),
            'partner_account_id' => $annonce->getAttribute('owner_partner_account_id'),
        ];
    }

    private function type(Model $annonce): string
    {
        return match (class_basename($annonce)) {
            'Vehicle' => 'vehicle',
            'Part' => 'part',
            default => 'accessory',
        };
    }

    private function champDePrixNormal(Model $annonce): string
    {
        return $this->type($annonce) === 'vehicle' ? 'sale_price' : 'selling_price';
    }

    /** Le nom lisible de l'annonce, celui que le personnel verra. */
    private function designation(Model $annonce): string
    {
        foreach (['designation', 'name', 'reference', 'sku'] as $champ) {
            $valeur = $annonce->getAttribute($champ);

            if (is_string($valeur) && trim($valeur) !== '') {
                return trim($valeur);
            }
        }

        return 'une annonce';
    }
}
