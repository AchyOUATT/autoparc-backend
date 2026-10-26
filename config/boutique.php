<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coordonnees de la boutique
    |--------------------------------------------------------------------------
    |
    | Le serveur ne connaissait jusqu'ici que les coordonnees des PARTENAIRES,
    | jamais les siennes : l'application mobile les portait en dur et personne
    | d'autre n'en avait besoin. Les pages de partage changent cela — elles
    | affichent un bouton WhatsApp et un bouton d'appel, et elles sont lues par
    | des gens qui n'ont pas l'application.
    |
    | Ces trois valeurs existent donc DESORMAIS A DEUX ENDROITS : ici, et dans
    | lib/core/utils/contact_info.dart de l'application mobile. C'est
    | volontaire, et c'est le seul montage qui tienne : la page web doit
    | repondre quand l'application n'est pas installee, et l'application doit
    | pouvoir appeler quand le reseau ne porte pas jusqu'au serveur. Aucune des
    | deux ne peut etre la source de l'autre.
    |
    | Le prix de ce doublon est qu'il faut CHANGER LES DEUX ENSEMBLE. Un numero
    | modifie ici seulement, et la moitie des clients appelle encore l'ancien
    | sans que rien ne le signale — un bouton d'appel ne rend pas d'erreur, il
    | sonne dans le vide.
    |
    | Pilote par l'environnement, au modele de config/mobile.php : le numero se
    | change depuis le tableau de bord de Render, sans reconstruire l'image. Les
    | defauts sont ceux en service, pour qu'une installation neuve reponde.
    |
    */

    'telephone' => env('BOUTIQUE_TELEPHONE', '+22675570484'),

    /*
    | Format wa.me : indicatif pays, sans « + » ni espaces ni tirets. wa.me
    | n'interprete rien — un numero mal forme ouvre WhatsApp sur une erreur
    | « numero invalide », pas sur la conversation. D'ou cette cle distincte de
    | `telephone`, qui lui garde la forme internationale lisible du lien tel:.
    */

    'whatsapp' => env('BOUTIQUE_WHATSAPP', '22675570484'),

    'nom' => env('BOUTIQUE_NOM', 'AutoParc'),

    /*
    |--------------------------------------------------------------------------
    | Un besoin exige-t-il un numero pour rappeler ?
    |--------------------------------------------------------------------------
    |
    | PAR DEFAUT : NON, et ce defaut est transitoire.
    |
    | ── CE QUE LE DRAPEAU PROTEGE ─────────────────────────────────────────────
    |
    | `submit_need_page.dart` de l'application PUBLIEE n'envoie aucun contact :
    | sa charge se limite a `type`, `description`, `budget_max` et le
    | `firebase_uid` quand il y en a un. Exiger le numero cote serveur
    | aujourd'hui ferait donc repondre 422 a CHAQUE depot de besoin venant d'un
    | telephone deja installe — et un besoin est une demande d'acheteur, donc
    | exactement ce qu'on ne veut pas perdre.
    |
    | Le nouveau client, lui, envoie toujours le numero. Les donnees commencent
    | donc a arriver des sa publication, drapeau ferme ou non.
    |
    | ── L'ORDRE POUR L'ARMER ──────────────────────────────────────────────────
    |
    |   1. une version mobile publiee dont le formulaire de besoin demande le
    |      numero ;
    |   2. `MOBILE_MIN_VERSION_CODE` releve a cette version, pour que les
    |      binaires qui ne l'envoient pas ne soient plus en circulation ;
    |   3. `NEEDS_PHONE_REQUIRED=true`.
    |
    | C'est le meme ordre que pour `PARTNER_SPACE_OPEN`, et pour la meme raison :
    | le serveur ne doit pas exiger d'un client ce que ce client ne sait pas
    | encore donner.
    |
    | `filter_var` et non `(bool)` : `(bool) 'no'` vaut VRAI en PHP, et ecrire
    | `NEEDS_PHONE_REQUIRED=no` dans le tableau de bord de Render est un geste
    | naturel qui aurait donc ARME l'exigence.
    |
    */

    'besoin_telephone_obligatoire' => filter_var(
        env('NEEDS_PHONE_REQUIRED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
];
