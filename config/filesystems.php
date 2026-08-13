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
            'throw' => false,
            'report' => false,
        ],

        'contabo' => [
            'driver' => 's3',
            // Use the first non-empty alias. An explicitly blank primary variable must not
            // hide a populated Portal-compatible CONTABO_OBJECT_STORAGE_* variable.
            'key' => env('CONTABO_ACCESS_KEY_ID') ?: env('CONTABO_OBJECT_STORAGE_ACCESS_KEY') ?: env('AWS_ACCESS_KEY_ID'),
            'secret' => env('CONTABO_SECRET_ACCESS_KEY') ?: env('CONTABO_OBJECT_STORAGE_SECRET_KEY') ?: env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('CONTABO_REGION') ?: env('CONTABO_OBJECT_STORAGE_REGION') ?: env('AWS_DEFAULT_REGION', 'usc1'),
            'bucket' => env('CONTABO_BUCKET') ?: env('CONTABO_OBJECT_STORAGE_BUCKET') ?: env('AWS_BUCKET'),
            'url' => env('CONTABO_PUBLIC_URL') ?: env('CONTABO_OBJECT_STORAGE_PUBLIC_URL') ?: env('AWS_URL'),
            'endpoint' => env('CONTABO_ENDPOINT') ?: env('CONTABO_OBJECT_STORAGE_ENDPOINT') ?: env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('CONTABO_USE_PATH_STYLE_ENDPOINT', env('CONTABO_OBJECT_STORAGE_USE_PATH_STYLE_ENDPOINT', true)),
            'visibility' => env('CONTABO_OBJECT_STORAGE_VISIBILITY', 'public'),
            'throw' => false,
            'report' => false,
        ],

        // Multi-bucket storage targets (see config/storage_targets.php).
        // "contabo_nbx" is the legacy bucket ("nbx"); shares credentials with
        // the "contabo" disk above by default.
        'contabo_nbx' => [
            'driver' => 's3',
            'key' => env('CONTABO_NBX_ACCESS_KEY_ID') ?: env('CONTABO_ACCESS_KEY_ID') ?: env('CONTABO_OBJECT_STORAGE_ACCESS_KEY'),
            'secret' => env('CONTABO_NBX_SECRET_ACCESS_KEY') ?: env('CONTABO_SECRET_ACCESS_KEY') ?: env('CONTABO_OBJECT_STORAGE_SECRET_KEY'),
            'region' => env('CONTABO_NBX_REGION') ?: env('CONTABO_REGION') ?: env('CONTABO_OBJECT_STORAGE_REGION', 'usc1'),
            'bucket' => env('CONTABO_NBX_BUCKET') ?: env('CONTABO_BUCKET') ?: env('CONTABO_OBJECT_STORAGE_BUCKET', 'nbx'),
            'url' => env('CONTABO_NBX_PUBLIC_URL') ?: env('CONTABO_PUBLIC_URL') ?: env('CONTABO_OBJECT_STORAGE_PUBLIC_URL'),
            'endpoint' => env('CONTABO_NBX_ENDPOINT') ?: env('CONTABO_ENDPOINT') ?: env('CONTABO_OBJECT_STORAGE_ENDPOINT', 'https://usc1.contabostorage.com'),
            'use_path_style_endpoint' => (bool) env('CONTABO_NBX_USE_PATH_STYLE_ENDPOINT', env('CONTABO_USE_PATH_STYLE_ENDPOINT', true)),
            'visibility' => env('CONTABO_OBJECT_STORAGE_VISIBILITY', 'public'),
            'throw' => false,
            'report' => false,
        ],

        // "contabo_nb_nbx" is the new, separate storage service/bucket
        // ("nb-nbx"). Credentials are independent from "contabo_nbx".
        'contabo_nb_nbx' => [
            'driver' => 's3',
            'key' => env('CONTABO_NB_NBX_ACCESS_KEY_ID'),
            'secret' => env('CONTABO_NB_NBX_SECRET_ACCESS_KEY'),
            'region' => env('CONTABO_NB_NBX_REGION', 'usc1'),
            'bucket' => env('CONTABO_NB_NBX_BUCKET', 'nb-nbx'),
            // See config/storage_targets.php for why this needs the
            // tenant-ID-prefixed form.
            'url' => env('CONTABO_NB_NBX_PUBLIC_URL', 'https://usc1.contabostorage.com/5fa286e37e8b403abc5b60ba900a5c3d:nb-nbx'),
            'endpoint' => env('CONTABO_NB_NBX_ENDPOINT', 'https://usc1.contabostorage.com'),
            'use_path_style_endpoint' => (bool) env('CONTABO_NB_NBX_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => env('CONTABO_OBJECT_STORAGE_VISIBILITY', 'public'),
            'throw' => false,
            'report' => false,
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('CLOUDFLARE_R2_ACCESS_KEY_ID', env('R2_ACCESS_KEY_ID')),
            'secret' => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY', env('R2_SECRET_ACCESS_KEY')),
            'region' => env('CLOUDFLARE_R2_REGION', env('R2_REGION', 'auto')),
            'bucket' => env('CLOUDFLARE_R2_BUCKET', env('R2_BUCKET', 'nbx')),
            'url' => env('CLOUDFLARE_R2_PUBLIC_URL', env('R2_PUBLIC_URL', 'https://nbxgen.naraboxtv.com')),
            'endpoint' => env('CLOUDFLARE_R2_ENDPOINT', env('R2_ENDPOINT')),
            'use_path_style_endpoint' => (bool) env('CLOUDFLARE_R2_USE_PATH_STYLE_ENDPOINT', env('R2_USE_PATH_STYLE_ENDPOINT', false)),
            'throw' => false,
            'report' => false,
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
