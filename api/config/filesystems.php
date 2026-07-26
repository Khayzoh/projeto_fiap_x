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

        /*
         * Storage de objetos dos videos e dos ZIPs (MinIO local, S3 em nuvem).
         * Usado para todas as leituras e escritas feitas pela API.
         */
        's3' => [
            'driver' => 's3',
            'key' => env('STORAGE_ACCESS_KEY'),
            'secret' => env('STORAGE_SECRET_KEY'),
            'region' => env('STORAGE_REGION', 'us-east-1'),
            'bucket' => env('STORAGE_BUCKET', 'fiapx-videos'),
            'endpoint' => env('STORAGE_ENDPOINT_URL', 'http://minio:9000'),
            // MinIO nao usa DNS por bucket: o nome vai no caminho da URL.
            'use_path_style_endpoint' => env('STORAGE_PATH_STYLE', true),
            'throw' => true,
            'report' => false,
        ],

        /*
         * Mesmo bucket, mas com o endpoint que o navegador do usuario alcanca.
         *
         * Dentro da rede do Docker o MinIO atende em "minio:9000"; uma URL
         * assinada com esse host nao resolveria fora dos containers. Este disco
         * existe apenas para assinar os links de download.
         */
        's3_public' => [
            'driver' => 's3',
            'key' => env('STORAGE_ACCESS_KEY'),
            'secret' => env('STORAGE_SECRET_KEY'),
            'region' => env('STORAGE_REGION', 'us-east-1'),
            'bucket' => env('STORAGE_BUCKET', 'fiapx-videos'),
            'endpoint' => env('STORAGE_PUBLIC_ENDPOINT_URL', 'http://localhost:9000'),
            'use_path_style_endpoint' => env('STORAGE_PATH_STYLE', true),
            'throw' => true,
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
