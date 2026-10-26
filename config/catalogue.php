<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repertoire des corrections de finitions
    |--------------------------------------------------------------------------
    |
    | `catalog:correct-trims` n'accepte QUE des documents situes sous ce
    | repertoire. Le chemin passe en argument est resolu par `realpath()`, donc
    | ni un lien symbolique ni une remontee par `..` n'en sortent.
    |
    | ── POURQUOI CETTE BORNE ──────────────────────────────────────────────────
    |
    | Le README de `database/catalogue/corrections-finitions` enonce la regle :
    | ces fichiers sont VERSIONNES et ils sont la reference,
    | `CorrectionDesFinitionsTest` les relit et exige que `TrimsSeeder` leur
    | donne raison. Appliquer une correction en base sans corriger le seeder
    | fait donc echouer la suite, et c'est voulu.
    |
    | Rien ne tenait cette regle. La commande acceptait n'importe quel chemin :
    | une correction appliquee depuis `/tmp` passait sans laisser de trace dans
    | le depot, et la suite continuait de donner raison a l'ancienne valeur.
    | La regle etait ecrite, pas appliquee.
    |
    | ── POURQUOI UN REGLAGE, ET NON UN CHEMIN EN DUR ─────────────────────────
    |
    | Parce que les bancs ecrivent des documents jetables, et qu'ils ne doivent
    | pas les poser dans le repertoire versionne : un test interrompu y
    | laisserait un fichier que la regle 4 relirait ensuite comme une reference.
    | Ils surchargent donc cette valeur vers un repertoire temporaire.
    |
    | La production n'a aucune raison de la surcharger. Si elle l'est, c'est un
    | choix explicite, visible dans l'environnement.
    |
    */

    'corrections_finitions' => env(
        'CATALOGUE_CORRECTIONS_FINITIONS',
        database_path('catalogue/corrections-finitions'),
    ),

];
