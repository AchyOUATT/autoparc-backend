<?php

namespace App\Http\Requests\Partner;

use App\Support\MargePartenaire;
use App\Support\PartnerOwnership;
use App\Support\PromotionPartenaire;
use Illuminate\Validation\Validator;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesPartnerCatalog
{
    abstract protected function partnerRouteParameter(): string;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        PartnerOwnership::accountId($user);
        $product = $this->route($this->partnerRouteParameter());
        if ($product instanceof Model) {
            PartnerOwnership::authorize($product, $user);
        }

        return true;
    }

    /**
     * Les champs qu'un partenaire ne choisit PAS.
     *
     * `missing` refuse meme un null explicite : c'est le serveur, et lui seul,
     * qui decide de l'appartenance, de la publication, et des deux champs
     * ajoutes le 25/10/2026 — `reference` et `deal_type`.
     *
     * ── POURQUOI `reference` : UN PARTENAIRE POUVAIT BLOQUER LE PARC ───────
     *
     * Le plus grave des deux, et il se declenche sans mauvaise intention. La
     * chaine complete, mesuree :
     *
     * `Partner\VehicleController::store()` delegue au controleur du PERSONNEL,
     * qui ecrit `$data['reference'] ?? $this->references->next('VEH')` — la
     * valeur fournie par le client gagne. Et `ReferenceGenerator::next()` lit
     * le maximum par `orderByDesc` sur une colonne TEXTE, puis incremente.
     *
     * Qu'un partenaire enregistre `VEH-2026-999999` et la suite est mecanique.
     * REJOUE le 25/10/2026, garde retire, et voici les codes observes :
     *
     *   partenaire pose `VEH-2026-999999`  -> 201
     *   creation personnel 1               -> 201, `VEH-2026-1000000`
     *   creation personnel 2               -> 500
     *   creation personnel 3               -> 500
     *
     * La creation 1 passe parce que `str_pad` ne tronque pas. Les suivantes
     * echouent parce que le maximum par tri de CHAINES reste
     * `VEH-2026-999999` — '9' > '1' — de sorte que `next()` reproduit
     * indefiniment `VEH-2026-1000000`, deja pris. Et ce n'est pas une erreur
     * de validation rattrapee : c'est 500, la violation de contrainte
     * remontant nue.
     *
     * A partir de la, plus personne ne cree de vehicule pour le reste de
     * l'annee. Une seule ecriture, par n'importe quel partenaire approuve, et
     * il suffit d'avoir tape une reference maison.
     *
     * Le generateur reste fragile en soi : un membre du personnel qui saisit
     * une reference a la main produirait le meme blocage. Ce garde ferme la
     * porte PARTENAIRE, qui est celle d'un tiers ; le reste est un defaut a
     * part, note comme tel.
     *
     * ── POURQUOI `deal_type` : LA VITRINE EST A LA BOUTIQUE ───────────────
     *
     * `?deal=1` sur le catalogue public rend toutes les annonces dont
     * `deal_type` n'est pas nul, et c'est ce qui alimente les mises en avant.
     * Sans ce garde, un partenaire se placait lui-meme dans les promotions de
     * la boutique. `deal_label` en decoule (`VehicleResource:107` le calcule
     * depuis `deal_type`), donc fermer l'un ferme les deux.
     *
     * ── CE QUI RESTE OUVERT, ET C'EST VOULU ───────────────────────────────
     *
     * `vin` : c'est le numero de serie de SON vehicule, et le saisir est son
     * travail, pas le notre.
     *
     * `sku` sur les pieces et accessoires : une reference d'equipementier —
     * « 462-1171 », « RE504836 » — qu'un vendeur de pieces doit pouvoir
     * declarer. Aucun generateur ne s'en nourrit, donc aucun blocage de suite
     * possible. Il reste qu'elle est unique a l'echelle de toute la base : un
     * partenaire peut accaparer un `sku` que la boutique voudra stocker. Le
     * remede n'est pas `missing` mais une unicite portee par proprietaire, et
     * c'est un changement de conception, pas un garde.
     */
    protected function partnerOwnershipRules(): array
    {
        return array_fill_keys([
            'owner_partner_account_id', 'partner_published_at', 'published_at',
            'created_by', 'user_id', 'location_id', 'partner_id',
            'reference', 'deal_type',

            // La marge est calculee par le serveur, jamais recue. Sans ce
            // garde, un partenaire enverrait `partner_pricing` lui-meme et
            // declarerait le taux qu'il veut — y compris un taux nul affiche
            // comme si la boutique l'avait consenti.
            'partner_pricing',
        ], ['missing']);
    }

    /**
     * Les prix que la boutique majore de sa marge.
     *
     * Chaque requete partenaire dit lesquels : un vehicule a un prix de vente
     * et trois tarifs de location, un article n'a qu'un prix de vente.
     *
     * @return list<string>
     */
    abstract protected function champsDePrix(): array;

    /**
     * Le prix normal qu'une promotion remise.
     *
     * `sale_price` pour un vehicule, `selling_price` pour un article. La
     * promotion ne touche PAS les tarifs de location : une remise sur trois
     * tarifs n'a pas de sens unique a afficher, et la bande des bonnes
     * affaires comme la carte montrent un prix de vente. Un vehicule en
     * location seule ne peut donc pas etre promu, et la validation le dit
     * plutot que d'enregistrer une promotion invisible.
     */
    abstract protected function champDePrixNormal(): string;

    /**
     * Les regles de la promotion, a fusionner dans celles de la requete.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function reglesDePromotion(): array
    {
        return PromotionPartenaire::regles();
    }

    /**
     * Le plafond de remise, verifie sur les prix que le vendeur a TAPES.
     *
     * Avant la marge, donc : voir `PromotionPartenaire` pour la raison. Et
     * avant `validated()`, qui est ce qui applique la marge — ici on lit
     * encore l'entree brute.
     *
     * A la MODIFICATION, le prix normal peut ne pas etre renvoye : on le
     * reprend alors sur l'annonce enregistree, sans quoi une promotion
     * seule serait refusee faute de point de comparaison.
     */
    protected function verifierLaPromotion(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $annonce = $this->route($this->partnerRouteParameter());
            $enregistre = $annonce instanceof Model
                ? $annonce->getAttribute($this->champDePrixNormal())
                : null;

            // La promotion DEJA posee, pour que le garde porte sur l'etat
            // final : une requete qui ne parle que du prix de vente peut
            // creuser la remise d'une promotion en cours jusqu'a 73 %, et
            // rien ne la verifierait. Voir `PromotionPartenaire::verifier`.
            $promotion = $annonce instanceof Model
                ? $annonce->getAttribute('deal_price')
                : null;

            PromotionPartenaire::verifier(
                $validator,
                $this->all(),
                $this->champDePrixNormal(),
                $enregistre === null ? null : (float) $enregistre,
                $promotion === null ? null : (float) $promotion,
            );
        });
    }

    /**
     * Les donnees validees, PRIX MAJORES.
     *
     * ── POURQUOI ICI, ET NON DANS LE CONTROLEUR ───────────────────────────
     *
     * Les controleurs partenaires delegent au controleur du PERSONNEL, qui
     * fait `$data = $request->validated()`. Majorer ici, c'est donc majorer
     * une fois pour la creation comme pour la modification, sans toucher au
     * chemin du personnel — dont les prix ne doivent evidemment rien porter.
     *
     * ── POURQUOI LE PRIX MAJORE VA EN COLONNE ─────────────────────────────
     *
     * Parce que toutes les routes publiques qui trient ou filtrent par prix
     * (`?price_max=`, le tri, la bande des bonnes affaires) lisent cette
     * colonne. Y laisser le prix propose ferait repondre « moins de
     * 5 000 000 » avec des annonces affichees a 5 500 000.
     *
     * Le prix propose et le taux partent dans `partner_pricing`, qui est ce
     * qu'on reaffiche au partenaire — et qui reste juste quand le reglage
     * change, puisqu'il est fige.
     */
    public function validated($key = null, $default = null)
    {
        $donnees = MargePartenaire::appliquer(parent::validated(), $this->champsDePrix());

        // On applique la marge AVANT d'extraire la clef, et non l'inverse :
        // `validated('sale_price')` doit rendre le meme nombre que
        // `validated()['sale_price']`, sans quoi l'appelant obtient le prix
        // propose en croyant lire le prix enregistre.
        return $key === null ? $donnees : data_get($donnees, $key, $default);
    }
}
