<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disque de stockage des photos
    |--------------------------------------------------------------------------
    |
    | Ou atterrissent les photos de vehicules, de pieces et d'accessoires.
    |
    | Le defaut `public` convient en developpement : les fichiers vivent dans
    | storage/app/public et se servent par le lien symbolique de `storage:link`.
    |
    | Il ne convient PAS en ligne. Le disque de Render est ephemere : tout ce
    | qui y est ecrit disparait au deploiement suivant, et une migration de
    | donnees en declenche un. Le commercant chargerait ses photos, elles
    | s'evaporeraient a la mise a jour d'apres — sans message, sans trace, avec
    | des lignes `media` pointant sur des fichiers absents.
    |
    | En production, ce reglage vaut `s3`, pointe sur un stockage objet
    | compatible S3 (Cloudflare R2). Les variables correspondantes sont decrites
    | dans docs/stockage-images.md.
    |
    | Le disque est enregistre LIGNE PAR LIGNE dans la table `media` : changer
    | ce reglage n'invalide pas les photos deja en place, qui continuent d'etre
    | lues et supprimees depuis le leur.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

];
