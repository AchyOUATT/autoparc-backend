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

];
