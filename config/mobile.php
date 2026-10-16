<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plancher de version de l'application mobile
    |--------------------------------------------------------------------------
    |
    | Les correctifs partent par vagues, et plusieurs d'entre eux dependent du
    | serveur. Une version ancienne qui continue d'appeler l'API affiche des
    | ecrans faux — des champs absents lus comme vides, des totaux calcules sur
    | une forme de reponse qui a change — sans que personne, ni l'utilisateur ni
    | nous, ne s'en apercoive. Le plancher permet de couper court : au-dessous
    | de `min_version_code`, l'application exige la mise a jour.
    |
    | Le plancher est servi par l'API plutot que par le flux natif de Play pour
    | trois raisons. Il se releve depuis le tableau de bord de Render, sans
    | reconstruire ni republier l'image. Il ne depend pas du delai de
    | propagation de Play, qui se compte en heures. Et il est verifiable en
    | test, ce que le flux natif n'est pas.
    |
    | Le defaut 0 ne bloque PERSONNE, et c'est volontaire : une installation
    | neuve du serveur, ou un deploiement ou la variable a ete oubliee, ne doit
    | pas mettre tout le parc a l'arret. C'est un reglage qu'on ARME le jour ou
    | une version devient reellement intenable, pas un comportement par defaut.
    |
    */

    'min_version_code' => (int) env('MOBILE_MIN_VERSION_CODE', 0),

    /*
    | La derniere version publiee. Purement informative : elle sert a proposer
    | une mise a jour facultative, jamais a bloquer. Seul `min_version_code`
    | ferme la porte.
    */

    'latest_version_code' => (int) env('MOBILE_LATEST_VERSION_CODE', 0),

    'store_url' => env('MOBILE_STORE_URL', 'https://play.google.com/store/apps/details?id=bf.autoparc.app'),

    /*
    | Le texte affiche a l'utilisateur bloque vient du serveur, et non de
    | l'application. C'est la seule facon d'expliquer POURQUOI la mise a jour
    | est exigee — « la saisie des pleins renvoyait des moyennes fausses »,
    | « les commandes passees depuis cette version n'arrivaient plus » — sans
    | republier l'application, qui par construction ne sera plus mise a jour
    | chez ceux a qui le message s'adresse.
    */

    'update_message' => env(
        'MOBILE_UPDATE_MESSAGE',
        "Cette version n'est plus prise en charge. Mettez à jour AutoParc pour continuer.",
    ),

];
