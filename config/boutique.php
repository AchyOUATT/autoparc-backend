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

];
