<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

            // `report` passe à true le 2026-10-16, après une panne silencieuse.
            //
            // Avec les deux à false, Flysystem avalait toute erreur d'écriture :
            // ni exception, ni ligne de journal. `storeAs()` rendait simplement
            // `false`, que MediaController enregistrait tel quel — la base
            // stockait `path = 0`, l'API construisait une URL en « .../0 », et
            // l'application affichait « photo ajoutée » sur un 201 sincère.
            // Deux photos ont été perdues ainsi, sans la moindre trace.
            //
            // `throw` reste à false : MediaController vérifie désormais le
            // retour de storeAs() et rend un message clair. Une exception
            // donnerait un 500 moins utile à l'utilisateur.
            //
            // Pour que ce `report` serve à quelque chose en ligne, LOG_CHANNEL
            // doit valoir `stderr` (posé dans render.yaml) : le canal par défaut
            // écrit dans storage/logs/, à l'intérieur d'un conteneur éphémère
            // que le plan gratuit de Render n'ouvre pas.
            'throw' => false,
            'report' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
