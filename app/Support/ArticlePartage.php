<?php

namespace App\Support;

/**
 * Ce qu'une page de partage montre d'un article, quel que soit son type.
 *
 * Trois modeles tiennent derriere ces pages — Vehicle, Part, Accessory — et
 * aucun ne nomme son prix, son titre ou ses caracteristiques comme les autres :
 * `sale_price` ici, `selling_price` la, un titre compose de quatre relations
 * pour le vehicule et une simple colonne `name` pour les deux autres.
 *
 * La vue n'en connait donc aucun : elle lit ce seul objet. Le gabarit porte les
 * balises Open Graph, qui sont la raison d'etre de ces pages ; les ecrire trois
 * fois, une par type, c'est accepter qu'elles divergent — et une divergence sur
 * og:image ne se voit pas en relisant le code, elle se voit le jour ou un
 * apercu part sans vignette dans une conversation.
 *
 * L'objet ne porte QUE du public. Ce qu'on ne lui donne pas ne peut pas fuir
 * dans une page que n'importe qui ouvre sans compte : ni prix d'achat, ni
 * emplacement de stockage, ni fournisseur.
 */
final class ArticlePartage
{
    /**
     * @param  array<string, string>  $precisions  libelle => valeur, dans l'ordre d'affichage
     */
    public function __construct(
        public readonly string $titre,
        public readonly ?float $prix,
        public readonly ?string $description,
        public readonly ?PhotoPartage $photo,
        public readonly array $precisions = [],
        /**
         * Le tarif journalier de location, quand l'article en a un.
         *
         * Distinct du prix de vente, et non replie dessus : les deux coexistent
         * sur un vehicule propose « both », et c'est la vente qui porte alors le
         * titre. Voir ligneDePrix().
         */
        public readonly ?float $tarifLocation = null,
        /**
         * Le libelle de l'indisponibilite, quand l'article est servi hors stock.
         *
         * `null` veut dire disponible, et c'est le cas des trois types sauf un :
         * la regle d'ArticleController::catalogueMontre ne sert en rupture que
         * la piece, parce que son catalogue la montre. Un accessoire
         * indisponible n'a pas de page du tout, et un vehicule vendu non plus.
         *
         * Il est passe plutot que calcule ici pour la raison qui vaut a tout cet
         * objet : trois modeles tiennent derriere ces pages et aucun ne nomme la
         * disponibilite comme les autres. Un libelle, pas un booleen : c'est du
         * texte francais lu par le destinataire, et c'est le controleur qui sait
         * quoi dire de chaque type.
         */
        public readonly ?string $indisponibilite = null,
    ) {}

    /** L'article est-il servi alors qu'il n'est pas disponible ? */
    public function estIndisponible(): bool
    {
        return $this->indisponibilite !== null && trim($this->indisponibilite) !== '';
    }

    /**
     * Le prix a la francaise : 3 000 FCFA.
     *
     * Espaces INSECABLES, aux milliers comme devant la devise. C'est la ligne
     * que le destinataire lit dans sa conversation, souvent sur un ecran de
     * telephone : une coupure de ligne y transforme « 3 000 FCFA » en un « 3 »
     * seul suivi de « 000 FCFA ». Pas de decimales — les prix sont en francs
     * CFA, ou le centime n'existe pas.
     *
     * Tout prix NON STRICTEMENT POSITIF est traite comme absent. La methode ne
     * testait que `null` : mesure, sale_price=0 rendait
     * « Toyota Corolla 2015 — 0 FCFA » en og:title, c'est-a-dire une voiture
     * annoncee gratuite dans la conversation. Un zero en base ne veut pas dire
     * « gratuit », il veut dire « pas renseigne » — le formulaire met 0 par
     * defaut dans un champ numerique vide.
     */
    public function prixFormate(): ?string
    {
        return $this->montant($this->prix);
    }

    /**
     * Le tarif de location, suffixe de sa periode.
     *
     * « / jour » n'est pas decoratif : 25 000 FCFA seul, a cote d'un prix de
     * vente de 4 500 000, se lit comme une voiture bradee.
     *
     * Insecable jusqu'au bout, pour la raison qui vaut deja aux milliers : un
     * retour a la ligne avant « jour » laisserait lire « 25 000 FCFA » tout
     * court sur l'ecran du destinataire, soit le malentendu que ce suffixe
     * existe pour eviter.
     */
    public function tarifLocationFormate(): ?string
    {
        $montant = $this->montant($this->tarifLocation);

        return $montant === null ? null : $montant."\u{A0}/\u{A0}jour";
    }

    /**
     * La ligne de prix affichee, qu'il s'agisse d'une vente ou d'une location.
     *
     * La vente d'abord quand les deux existent : c'est l'operation que la page
     * cherche a declencher. A defaut, le tarif de location — mesure,
     * availability=rent avec rental_daily_rate=25000 rendait
     * « Toyota Corolla 2015 — Prix sur demande », et les 25 000 FCFA
     * n'apparaissaient NULLE PART dans la page. Ce tarif n'est ni interne ni
     * confidentiel, et c'est la seule information qui decide le destinataire :
     * le taire pour ecrire « Prix sur demande » lui demande d'ouvrir une
     * conversation pour apprendre ce que la page savait.
     *
     * « Prix sur demande » reste pour le cas ou il n'y a vraiment aucun
     * montant : la ligne le dit, plutot que de laisser croire a un oubli
     * d'affichage.
     */
    public function ligneDePrix(): string
    {
        return $this->prixFormate()
            ?? $this->tarifLocationFormate()
            ?? 'Prix sur demande';
    }

    /**
     * Le nom ET le prix, sur une seule ligne.
     *
     * C'est le contenu d'og:title, donc le texte en gras de l'apercu WhatsApp.
     * Le prix y est indispensable : sans lui, le destinataire doit ouvrir la
     * page pour savoir si l'article l'interesse, et c'est precisement ce qu'un
     * apercu doit lui epargner.
     *
     * SAUF si l'article est servi en rupture. Un prix en gras dans une
     * conversation est une promesse de vente immediate : « Filtre a huile —
     * 3 000 FCFA » fait ecrire un client, attendre, et repartir. L'apercu
     * annonce alors l'indisponibilite a la place du prix — la ou le corps de la
     * page montre les deux, parce que le prix y decide d'attendre le
     * reapprovisionnement ou non.
     */
    public function titreAvecPrix(): string
    {
        return $this->titre.' — '.($this->estIndisponible()
            ? $this->indisponibilite
            : $this->ligneDePrix());
    }

    /**
     * Le montant BRUT de product:price:amount, ou null.
     *
     * og:type vaut « product » et le prix est deja calcule : sans
     * product:price:amount ni product:price:currency, la page se declare fiche
     * produit sans en porter le prix, et les lecteurs qui s'appuient sur ce
     * couple — moteurs, agregateurs — n'en tirent rien.
     *
     * Sans espace insecable ni separateur de milliers, contrairement a
     * l'affichage : la valeur est lue par une machine, et « 4 500 000 » n'y est
     * pas un nombre.
     *
     * Le prix de VENTE seulement. product:price:amount designe ce qu'on paie
     * pour acquerir l'article ; y mettre un tarif journalier de location
     * annoncerait une Corolla a 25 000 FCFA.
     */
    public function montantBrut(): ?string
    {
        // Le MEME critere que l'affichage, et c'est le point : la balise est
        // rendue si et seulement si la page montre un prix. Avec un critere
        // propre, un prix de 0,4 sortait en product:price:amount="0" pendant
        // que la page affichait « Prix sur demande » — deux reponses
        // contradictoires dans la meme page, celle lue par la machine etant la
        // fausse.
        return $this->prixFormate() === null
            ? null
            : number_format((float) $this->prix, 0, '.', '');
    }

    /**
     * Le texte de l'apercu, qui ne doit jamais etre vide.
     *
     * La description est facultative dans le catalogue, et la majorite des
     * pieces n'en a pas. Un og:description absent laisse le robot composer
     * lui-meme — il prend alors le premier texte de la page, souvent le nom du
     * bouton. Les precisions deja calculees font un meilleur resume, et elles
     * sont toujours la.
     */
    public function resume(): string
    {
        if ($this->description !== null && trim($this->description) !== '') {
            return $this->description;
        }

        $details = implode(' · ', array_filter(array_values($this->precisions)));

        return $details !== '' ? $details : $this->titreAvecPrix();
    }

    /**
     * La mise en forme commune au prix de vente et au tarif de location.
     *
     * Le test porte sur la valeur ARRONDIE, et non sur la valeur brute.
     * `<= 0` seul laissait passer 0,4, que number_format rend « 0 FCFA » —
     * c'est-a-dire exactement l'affichage que ce correctif existe pour
     * supprimer, reintroduit par l'arrondi. La regle tenue ici est donc la
     * seule qui se verifie a l'ecran : la page n'ecrit jamais « 0 FCFA ».
     *
     * 0,5 rend « 1 FCFA » et c'est volontaire : le franc CFA n'a pas de
     * centime, et un montant qui s'arrondit a un franc EST un montant d'un
     * franc. Absurde comme prix, mais ce n'est plus un zero deguise — et
     * inventer un seuil au-dela duquel un prix saisi devient « sur demande »
     * serait decider a la place du vendeur.
     */
    private function montant(?float $valeur): ?string
    {
        if ($valeur === null || round($valeur) <= 0) {
            return null;
        }

        return number_format($valeur, 0, ',', "\u{A0}")."\u{A0}FCFA";
    }
}
