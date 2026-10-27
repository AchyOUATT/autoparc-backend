<?php

namespace App\Support;

// `PrixEffectif` et `PromotionPartenaire` vivent dans le meme espace de noms :
// rien a importer.
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class PartnerListingPresentation
{
    public static function isPartnerListing(Model $listing): bool
    {
        return $listing->getAttribute('owner_partner_account_id') !== null;
    }

    public static function canSeePrivateData(Model $listing, Request $request): bool
    {
        if (! self::isPartnerListing($listing)) {
            return true;
        }

        $user = $request->user();

        return $user !== null && ($user->isStaff()
            || (int) $listing->sellerAccount?->user_id === (int) $user->id);
    }

    public static function seller(Model $listing): ?array
    {
        if (! self::isPartnerListing($listing)) {
            return null;
        }

        $seller = $listing->sellerAccount;

        return $seller === null ? null : [
            'id' => $seller->id,

            // ── LE NOM PUBLIC NE PEUT ETRE NI NUL NI VIDE ──────────────────
            //
            // `company_name` est facultatif a l'inscription : un revendeur
            // particulier n'a pas de raison sociale. Mais l'application
            // PUBLIEE lit ce champ par un transtypage dur
            // (`json['company_name'] as String`, dans `ProductSeller` comme
            // dans `PartnerSeller`) et compose l'initiale du vendeur par
            // `companyName[0]`. Un null y leve un `TypeError`, une chaine vide
            // un `RangeError` — et les deux font tomber l'ecran de detail de
            // l'annonce, celui-la meme qui porte le numero a appeler.
            //
            // Le repli est donc tenu ICI, par le serveur, et non par une
            // future version de l'application : les telephones deja installes
            // ne se mettent pas a jour parce qu'on l'aurait souhaite.
            //
            // `contact_name` reste obligatoire, donc le repli aboutit
            // toujours. Et c'est le nom sous lequel ce vendeur se presente :
            // rien n'est invente.
            'company_name' => $seller->company_name ?: $seller->contact_name,
            'phone' => $seller->phone,
            'whatsapp' => $seller->whatsapp,
        ];
    }

    /**
     * Le detail de la marge, pour le vendeur et le personnel SEULS.
     *
     * ── POURQUOI IL N'EST PAS DANS LA RESSOURCE ───────────────────────────
     *
     * Parce qu'alors il faudrait penser a le retirer, et le retrait serait
     * une liste blanche de plus a tenir a jour. Ici, il n'entre dans la
     * reponse qu'apres `canSeePrivateData` : un acheteur ne peut pas le voir,
     * meme si quelqu'un oublie une liste quelque part.
     *
     * Et ce qu'il contient n'est pas anodin : le prix que le vendeur a
     * propose, donc la marge de la boutique, donc sa negociation.
     *
     * ── CE QU'IL REND QUAND IL N'Y A RIEN ─────────────────────────────────
     *
     * `null`. Les annonces d'avant ce lot n'ont pas de detail, et leur prix
     * EST le prix propose : leur inventer un taux de 0 % laisserait croire
     * qu'un calcul a eu lieu. Le vendeur voit son prix, sans ligne de marge.
     *
     * @return array{taux: float, prix_proposes: array<string, float>, total: array<string, float>}|null
     */
    private static function marge(Model $listing): ?array
    {
        $detail = $listing->getAttribute('partner_pricing');

        if (! is_array($detail) || ! isset($detail['prix_proposes'])) {
            return null;
        }

        // Le TOTAL vient de la colonne et non d'un calcul refait ici : c'est
        // le prix reellement enregistre, celui que l'acheteur voit et sur
        // lequel le catalogue trie. S'ils divergeaient — prix corrige a la
        // main en base, par exemple — le vendeur doit voir le vrai.
        $detail['total'] = [];
        foreach (array_keys($detail['prix_proposes']) as $champ) {
            $valeur = $listing->getAttribute($champ);

            if ($valeur !== null) {
                $detail['total'][$champ] = (float) $valeur;
            }
        }

        return $detail;
    }

    /**
     * Celui-ci REECRIT-IL le prix, ou se contente-t-il de le lire ?
     *
     * ── POURQUOI UN PREDICAT A PART DE `canSeePrivateData` ────────────────
     *
     * Parce que celui-la rend VRAI pour toute annonce qui n'est pas d'un
     * partenaire — y compris a un visiteur anonyme. Il repond « cette ligne
     * n'a rien de prive », ce qui est juste, mais n'est pas la question
     * posee ici.
     *
     * La question est : ce lecteur va-t-il RENVOYER ce prix ? Le formulaire
     * d'annonce prerremplit son champ avec ce nombre et le repose tel quel au
     * prochain enregistrement. Lui donner le prix promotionnel ferait
     * enregistrer la remise comme prix normal a la premiere correction de
     * description, la marge se rappliquant par-dessus.
     *
     * Le confondre avec `canSeePrivateData` ferait voir le prix normal a un
     * acheteur anonyme sur une annonce promue de la boutique, pendant que le
     * filtre et le tri, eux, la retiendraient au prix promotionnel.
     */
    private static function peutEditerLePrix(Model $listing, Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ($user->isStaff()
            || (int) $listing->sellerAccount?->user_id === (int) $user->id);
    }

    /** « 20 » et non « 20.0 » ; « 37.5 » quand la virgule compte. */
    private static function pourcentage(float $valeur): string
    {
        return $valeur == (int) $valeur ? (string) (int) $valeur : (string) $valeur;
    }

    /**
     * La promotion en cours, ou null.
     *
     * Rendue a TOUT LE MONDE : c'est une offre, elle est faite pour se voir.
     * Elle porte le prix normal barre, le prix du jour, la remise et la date
     * de fin — de quoi afficher « 3 900 000 au lieu de 4 500 000, jusqu'au 31 »
     * sans que l'application ait a recalculer quoi que ce soit.
     *
     * `null` quand la fenetre est fermee, et c'est ainsi qu'une promotion
     * EXPIRE : il n'y a rien a executer, la date suffit. L'hebergement n'ayant
     * ni shell ni tache planifiee fiable, aucune autre mecanique ne tiendrait.
     *
     * @return array{price: float, normal_price: float, discount_percent: float, starts_at: ?string, ends_at: ?string}|null
     */
    private static function promotion(Model $listing, string $type): ?array
    {
        if (! PrixEffectif::enCours($listing)) {
            return null;
        }

        $colonne = $type === 'vehicle' ? 'sale_price' : 'selling_price';
        $normal = $listing->getAttribute($colonne);

        if ($normal === null) {
            return null;
        }

        $normal = (float) $normal;
        $promo = (float) $listing->getAttribute('deal_price');

        return [
            'price' => $promo,
            'normal_price' => $normal,
            // Arrondie au dixieme : « -40 % » se lit, « -39,99999 % » non.
            'discount_percent' => round(PromotionPartenaire::remise($normal, $promo), 1),
            'starts_at' => $listing->getAttribute('deal_starts_at')?->toIso8601String(),
            'ends_at' => $listing->getAttribute('deal_ends_at')?->toIso8601String(),
        ];
    }

    /**
     * Le prix du jour a la place du prix normal, pour un acheteur.
     *
     * Le bloc de prix differe selon le type : `commercial.sale_price` pour un
     * vehicule, `pricing.selling_price` et `pricing.price_including_vat` pour
     * un article — ce dernier etant ce que le PANIER facture.
     */
    private static function prixDuJour(array $data, Model $listing, string $type): array
    {
        // ── LA MEME CONDITION QUE LE BLOC `deal`, ET C'EST VOULU ──────────
        //
        // `promotion()` rend null quand le prix normal a disparu — un
        // vehicule passe en location seule, par exemple. Substituer quand
        // meme afficherait alors un prix promotionnel SANS rien qui dise
        // qu'il l'est : l'acheteur lirait un prix remise comme un prix
        // ordinaire, et aucun prix barre ne viendrait le corriger.
        if (! isset($data['deal'])) {
            return $data;
        }

        $promo = (float) $listing->getAttribute('deal_price');

        if ($type === 'vehicle') {
            if (isset($data['commercial'])) {
                $data['commercial']['sale_price'] = $promo;

                // ── LE BADGE DE L'APPLICATION DEJA PUBLIEE ────────────────
                //
                // Elle ne connait pas le bloc `deal`, mais elle affiche depuis
                // toujours `deal_label` — sur la carte du catalogue comme dans
                // la bande d'accueil. Lui donner ce libelle fait apparaitre la
                // promotion sur les telephones deja installes, sans nouveau
                // binaire.
                //
                // `deal_label` et NON `deal_type` : mesure faite, `deal_label`
                // n'est jamais RENVOYE par l'application — contrairement a
                // `deal_type`, que le formulaire de vehicule prerremplit puis
                // repose. Fabriquer `deal_type` rendrait tout vehicule promu
                // ineditable, sa valeur inventee ne figurant pas dans
                // l'enumere que la validation accepte.
                $remise = $data['deal']['discount_percent'] ?? null;
                if ($remise !== null && $remise > 0) {
                    $data['commercial']['deal_label'] = 'Promo -'
                        .self::pourcentage((float) $remise).' %';
                }
            }

            return $data;
        }

        if (isset($data['pricing'])) {
            $data['pricing']['selling_price'] = $promo;
            // La TVA se recalcule sur le prix du jour : la laisser sur le prix
            // normal ferait payer au panier une taxe assise sur un montant que
            // l'acheteur ne verse pas.
            $data['pricing']['price_including_vat'] = round(
                $promo * (1 + (float) $listing->getAttribute('vat_rate') / 100),
                2,
            );
        }

        return $data;
    }

    public static function present(array $data, Model $listing, Request $request, string $type): array
    {
        // Historical listings retain their existing response contract.
        if (! self::isPartnerListing($listing)) {
            return $data;
        }

        $data['seller'] = self::seller($listing);

        $data['deal'] = self::promotion($listing, $type);

        if (self::peutEditerLePrix($listing, $request)) {
            $data['owner_partner_account_id'] = $listing->owner_partner_account_id;
            $data['partner_published_at'] = $listing->partner_published_at?->toIso8601String();
            $data['partner_pricing'] = self::marge($listing);

            // ── LE VENDEUR VOIT SON PRIX NORMAL, L'ACHETEUR LE PRIX DU JOUR ──
            //
            // Le bloc de prix reste celui de la ressource, c'est-a-dire la
            // COLONNE : le prix normal. C'est volontaire, et ce n'est pas un
            // detail d'affichage.
            //
            // Le formulaire d'annonce preremplit son champ « prix de vente »
            // avec ce nombre. Lui donner le prix promotionnel ferait
            // enregistrer la remise comme prix normal a la premiere correction
            // de description — la promotion deviendrait definitive, et le prix
            // d'origine serait perdu sans qu'un mot ne soit dit.
            //
            // Le bloc `deal` ci-dessus porte la promotion, et le vendeur y lit
            // les deux nombres.
            return $data;
        }

        // ── CE QUE L'ACHETEUR PAIE EST DANS LE CHAMP DU PRIX ──────────────
        //
        // L'application DEJA PUBLIEE lit `commercial.sale_price` et
        // `pricing.selling_price`, et ne connait pas le bloc `deal`. Y laisser
        // le prix normal lui ferait afficher un prix que les filtres et le tri
        // ne retiennent plus — une annonce rendue par « moins de 4 000 000 »
        // s'y afficherait a 4 500 000.
        //
        // Elle montre donc le prix promotionnel sans savoir qu'il l'est : sans
        // prix barre, mais juste. Les versions qui connaissent `deal` barrent
        // le prix normal et annoncent la fin.
        $data = self::prixDuJour($data, $listing, $type);

        if ($type === 'vehicle') {
            $data = Arr::only($data, [
                'id', 'designation', 'vehicle_type', 'body_style', 'registration_status',
                'identity', 'technical', 'consumption', 'commercial', 'features',
                'optional_feature_count', 'is_full_option', 'media', 'description',
                'published_at', 'created_at', 'share_version', 'seller', 'deal',
            ]);
            $data['commercial'] = Arr::only($data['commercial'], [
                'condition', 'status', 'status_label', 'availability', 'sale_price',
                'rental_daily_rate', 'rental_weekly_rate', 'rental_monthly_rate',
                'rental_deposit', 'currency', 'price_negotiable', 'deal_type', 'deal_label',
            ]);
        } else {
            $data = Arr::only($data, [
                'id', 'name', 'description', 'compatibility_assessment', 'type', 'condition',
                'category', 'category_id', 'manufacturer', 'manufacturer_id',
                'manufacturer_reference', 'oem_numbers', 'pricing', 'stock', 'media',
                'warranty_months', 'weight_kg', 'dimensions', 'is_active',
                'compatibility', 'fitments', 'share_version', 'seller', 'deal',
            ]);
            $data['stock'] = Arr::only($data['stock'], ['is_available']);

            if (isset($data['fitments']) && is_iterable($data['fitments'])) {
                $data['fitments'] = collect($data['fitments'])
                    ->map(fn (array $fitment) => Arr::except($fitment, ['notes']))
                    ->values();
            }
        }

        return $data;
    }
}
