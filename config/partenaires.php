<?php

return [

    /*
    |--------------------------------------------------------------------------
    | L'espace partenaire est-il ouvert ?
    |--------------------------------------------------------------------------
    |
    | PAR DEFAUT : NON. C'est le seul reglage de ce fichier, et son defaut est
    | ferme a dessein.
    |
    | ── POURQUOI CE DRAPEAU EXISTE ────────────────────────────────────────
    |
    | L'espace partenaire est arrive par les deux bouts en meme temps, et pas
    | dans le bon ordre. Cote mobile il est fusionne, pousse, et sa tuile de
    | tiroir n'est soumise a AUCUNE condition : un visiteur anonyme de
    | l'application deja publiee la voit et peut la toucher. Cote serveur il
    | vient d'etre fusionne et n'est pas encore deploye.
    |
    | Trois consequences, qui tiennent toutes a l'ordre des operations :
    |
    | UN. Rien, dans l'application publiee, ne permet d'APPROUVER un
    | partenaire : l'ecran de validation existe dans le depot mobile mais dans
    | aucun binaire livre. Deployer le serveur sans ce drapeau ouvrirait donc
    | l'inscription a tout le monde pour produire des comptes « pending » que
    | personne ne peut traiter.
    |
    | DEUX. La premiere annonce partenaire rendue PUBLIQUE casse le catalogue
    | de l'application publiee : `PartnerListingPresentation` retire
    | `reference` de la reponse, et ce binaire la lit en cast dur
    | (`json['reference'] as String`). Le depot mobile l'a corrige ; le binaire
    | en circulation, non.
    |
    | TROIS. Aucun drapeau de fonctionnalite n'existe cote mobile — ni
    | configuration distante, ni constante de compilation. L'entree est donc
    | impossible a masquer depuis le telephone. Le seul endroit d'ou l'on
    | puisse encore agir est ICI, cote serveur.
    |
    | ── CE QU'IL FERME, ET CE QU'IL NE FERME PAS ──────────────────────────
    |
    | Ferme : tout le groupe `prefix('partner')` — inscription, connexion, et
    | les quarante routes d'annonces, media et stock. Un appel y rend 503 avec
    | un motif lisible, et non 404 : un 404 serait indiscernable d'un serveur
    | sur lequel la fonctionnalite n'a jamais ete deployee.
    |
    | PAS ferme : `partner-applications`, l'administration du personnel. Elle
    | reste disponible pour que le staff puisse travailler des le deploiement,
    | et c'est par elle que passera l'approbation du premier partenaire.
    |
    | ── COMMENT L'OUVRIR, ET DANS QUEL ORDRE ──────────────────────────────
    |
    | `PARTNER_SPACE_OPEN=true` depuis le tableau de bord de Render. Aucune
    | reconstruction n'est necessaire : `config:cache` tourne au DEMARRAGE du
    | conteneur (voir le CMD du Dockerfile), pas a la construction de l'image,
    | de sorte qu'un changement de variable suffit.
    |
    | A ne faire qu'apres, et dans cet ordre :
    |   1. une version mobile publiee qui porte les deux correctifs de lecture
    |      (`reference` et le stock des pieces) et l'ecran de validation ;
    |   2. `MOBILE_MIN_VERSION_CODE` releve a cette version, pour que les
    |      binaires qui cassent ne soient plus en circulation ;
    |   3. ce drapeau.
    |
    */

    /*
    | ── POURQUOI `filter_var` ET NON UN SIMPLE `(bool)` ────────────────────
    |
    | Mesure du 25/10/2026, valeur par valeur, avant de pousser. `env()` de
    | Laravel traduit bien `false`, `FALSE`, `(false)`, `0` et le vide en
    | booleen faux. Mais il rend les AUTRES chaines telles quelles, et
    | `(bool) 'no'` vaut VRAI en PHP — comme `(bool) 'off'`. Ecrire
    | `PARTNER_SPACE_OPEN=no` dans le tableau de bord de Render, ce qui est un
    | geste parfaitement naturel, aurait donc OUVERT l'espace.
    |
    | `FILTER_VALIDATE_BOOLEAN` n'accepte que `1`, `true`, `on` et `yes`, et
    | rend faux pour tout le reste. C'est exactement la regle que
    | `_booleen()` applique cote Flutter : les deux bouts s'ouvrent sur les
    | memes mots, et sur eux seuls.
    */

    'espace_ouvert' => filter_var(env('PARTNER_SPACE_OPEN', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Marge de la boutique sur les prix proposes par un partenaire
    |--------------------------------------------------------------------------
    |
    | Un pourcentage AJOUTE au prix que le partenaire propose. Il propose
    | 4 500 000 ; a 10 %, le catalogue affiche 4 950 000, et le partenaire voit
    | les trois nombres — son prix, le taux, le total.
    |
    | PAR DEFAUT : 0. Aucune marge n'est prise tant que ce reglage n'est pas
    | arme, et une installation neuve ne doit pas inventer un prix que personne
    | n'a decide.
    |
    | ── CE QUE LE TAUX TOUCHE ─────────────────────────────────────────────────
    |
    | Tous les prix d'un partenaire : le prix de vente d'un vehicule, ses trois
    | tarifs de location — jour, semaine, mois — et le prix de vente d'une piece
    | ou d'un accessoire.
    |
    | PAS la caution de location (`rental_deposit`) : une caution est
    | remboursable, ce n'est pas un prix de vente. En prendre une marge
    | reviendrait a facturer une somme qu'on rend.
    |
    | ── POURQUOI LE TOTAL EST FIGE A L'ENREGISTREMENT ─────────────────────────
    |
    | Le taux applique est stocke AVEC l'annonce, et le total calcule une fois.
    | Changer ce reglage ne reprend donc PAS le catalogue : un acheteur ne voit
    | jamais un prix bouger parce qu'un reglage a bouge, et un lien de partage
    | deja envoye reste exact — ce depot a deja paye une page de partage qui
    | annoncait un prix pour un vehicule vendu.
    |
    | Le taux est stocke et non relu depuis ce fichier pour la meme raison : six
    | mois plus tard, afficher « marge 10 % » sur une annonce calculee a 8 %
    | serait faux. Chaque annonce porte le taux qui l'a faite.
    |
    | Le nouveau taux s'applique aux annonces CREEES ou MODIFIEES ensuite. Une
    | modification de prix recalcule donc au taux courant, ce qui est voulu : le
    | partenaire vient de rouvrir son prix.
    |
    */

    'marge_pourcentage' => (float) env('PARTNER_MARGIN_PERCENT', 0),

    /*
    |--------------------------------------------------------------------------
    | Remise maximale d'une promotion partenaire
    |--------------------------------------------------------------------------
    |
    | Un partenaire cree ses promotions LIBREMENT : pas d'approbation prealable,
    | le personnel est notifie. C'est un choix delibere — faire valider chaque
    | promotion par quelqu'un reviendrait a n'en avoir aucune, l'approbation
    | arrivant apres l'occasion qu'elle visait.
    |
    | Ce choix laisse un trou, et ce reglage le ferme : sans plafond, un
    | partenaire affiche son vehicule a 1 franc et occupe le haut de la bande
    | des bonnes affaires. La notification ne l'apprend qu'APRES coup, quand
    | des acheteurs ont deja vu la vitrine.
    |
    | PAR DEFAUT : 40 %. Une remise au-dela est refusee, et le vendeur le lit
    | dans son formulaire avant l'envoi — pas apres.
    |
    | ── POURQUOI UN POURCENTAGE, ET NON UN PRIX PLANCHER ──────────────────────
    |
    | Un plancher en francs ne veut rien dire sur un catalogue qui va du filtre
    | a huile a 3 000 au vehicule a 15 000 000. Le meme nombre serait inoperant
    | d'un cote et interdirait toute promotion de l'autre.
    |
    | ── CE QUE LE PLAFOND NE FAIT PAS ─────────────────────────────────────────
    |
    | Il ne juge pas le prix NORMAL. Un partenaire qui gonfle son prix habituel
    | pour afficher ensuite « -40 % » reste possible, et aucun pourcentage ne
    | l'empeche : c'est au personnel, notifie, de le voir. Le plafond borne
    | l'ECART, pas la sincerite du point de depart.
    |
    | ── LE CHANGER NE REPREND PAS LES PROMOTIONS EN COURS ────────────────────
    |
    | Comme pour la marge : une promotion deja enregistree garde sa remise. Un
    | acheteur ne doit pas voir un prix bouger parce qu'un reglage a bouge, et
    | une promotion annoncee pour une duree doit tenir cette duree.
    |
    */

    'promotion_remise_max_pourcentage' => (float) env('PARTNER_PROMO_MAX_DISCOUNT_PERCENT', 40),

];
