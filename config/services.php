<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
	
	'firebase' => [
		'credentials' => env('FIREBASE_CREDENTIALS') ?: storage_path('app/firebase/service-account.json'),
	],

    /*
     * Secret partage du declencheur de taches planifiees.
     *
     * Volontairement sans valeur par defaut : une chaine vide ferme la route
     * (voir ScheduledTaskController). Un defaut quelconque ferait tourner la
     * tache sur une valeur devinable, et un defaut vide ouvrirait la porte a
     * tout le monde si la comparaison n'y prenait pas garde.
     */
    'tasks' => [
        'secret' => env('TASKS_SECRET'),
    ],

];
