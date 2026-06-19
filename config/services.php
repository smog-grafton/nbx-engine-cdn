<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'contabo_api' => [
        'base_url' => env('CONTABO_API_BASE_URL', 'https://api.contabo.com'),
        'auth_url' => env('CONTABO_AUTH_URL', 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token'),
        'client_id' => env('CONTABO_API_CLIENT_ID'),
        'client_secret' => env('CONTABO_API_CLIENT_SECRET'),
        'username' => env('CONTABO_API_USERNAME'),
        'password' => env('CONTABO_API_PASSWORD'),
        'user_id' => env('CONTABO_API_USER_ID'),
        'object_storage_id' => env('CONTABO_API_OBJECT_STORAGE_ID'),
        'timeout' => (int) env('CONTABO_API_TIMEOUT', 30),
        'connect_timeout' => (int) env('CONTABO_API_CONNECT_TIMEOUT', 10),
    ],

    'contabo_object_storage' => [
        'path_prefix' => env('CONTABO_OBJECT_STORAGE_PATH_PREFIX', 'videos'),
    ],

];
