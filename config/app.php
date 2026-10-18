<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    /*
     * `?:` et non le second argument d'env() : la valeur peut etre PRESENTE ET
     * VIDE, et le defaut d'env() ne couvre que l'absence.
     *
     * C'est le cas que Render fabrique. Un champ declare `sync: false` dans
     * render.yaml et laisse blanc dans le tableau de bord arrive comme une
     * variable definie a la chaine vide. Mesure, serveur demarre avec APP_URL= :
     *
     *     env('APP_URL')    -> ''
     *     config('app.url') -> ''   (var_export : '')
     *
     * Et les pages de partage composent leur adresse depuis cette valeur. La
     * meme mesure sur GET /v/1 rendait HTTP 200 avec
     * <meta property="og:url" content="/v/1">,
     * <link rel="canonical" href="/v/1"> et surtout
     * <meta property="og:image" content="/storage/vehicles/1/photo.jpg">.
     * Un og:image RELATIF, c'est zero vignette pour toujours, sur une page qui
     * rend 200, parait normale et n'ecrit rien dans aucun journal.
     *
     * Ce repli empeche la valeur vide. Il ne suffit pas : « http://localhost »
     * en ligne ne vaut pas mieux qu'une adresse relative, il est seulement
     * visible. C'est AppServiceProvider::exigerUneAdressePubliqueAbsolue() qui
     * refuse de demarrer dans ce cas, et le couple des deux qui tient.
     *
     * Le disque `public` de config/filesystems.php repete le meme `?:` pour la
     * meme raison : il compose son URL publique depuis env('APP_URL') et non
     * depuis cette cle.
     */
    'url' => env('APP_URL') ?: 'http://localhost',

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
