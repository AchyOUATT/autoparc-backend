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

];
