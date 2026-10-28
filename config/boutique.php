<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coordonnees de la boutique
    |--------------------------------------------------------------------------
    |
    | Le numero AutoParc se modifie maintenant au back-office. Le service
    | AutoParcContact lit cet enregistrement pour l'API mobile et les pages de
    | partage. L'application conserve le dernier numero recu pour les appels
    | hors connexion. Les coordonnees des vendeurs partenaires restent propres
    | a leurs annonces et ne sont pas remplacees par ce parametre.
    |
    | BOUTIQUE_TELEPHONE et BOUTIQUE_WHATSAPP restent les valeurs initiales
    | jusqu'au premier enregistrement au back-office, pour preserver les
    | installations existantes. Ensuite le telephone en base sert aussi a
    | WhatsApp, sans son « + ». Aucune reconstruction du mobile n'est necessaire
    | pour les changements suivants, une fois cette version installee.
    |
    */

    'telephone' => env('BOUTIQUE_TELEPHONE', '+22675570484'),

    /*
    | Ancien format wa.me : indicatif pays, sans « + » ni espaces ni tirets.
    | Ce repli ne sert qu'avant le premier enregistrement du numero au BO.
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
