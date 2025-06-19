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
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'remote_proofs' => [
            'driver' => 'sftp',
            'host' => getenv('SFTP_HOSTNAME'),
            'port' => getenv('SFTP_PORT') ?: 22,
            'username' => getenv('SFTP_USERNAME'),
            'privateKey' => getenv('SFTP_PATHTOPRIVATEKEY'),
            'root' => getenv('SFTP_PROOFSPATH'),
            'throw' => true,
        ],

        'remote_web_images' => [
            'driver' => 'sftp',
            'host' => getenv('SFTP_HOSTNAME'),
            'port' => getenv('SFTP_PORT') ?: 22,
            'username' => getenv('SFTP_USERNAME'),
            'privateKey' => getenv('SFTP_PATHTOPRIVATEKEY'),
            'root' => getenv('SFTP_WEB_IMAGES_PATH'),
            'throw' => true,
        ],

        'remote_highres_images' => [
            'driver' => 'sftp',
            'host' => getenv('SFTP_HOSTNAME'),
            'port' => getenv('SFTP_PORT') ?: 22,
            'username' => getenv('SFTP_USERNAME'),
            'privateKey' => getenv('SFTP_PATHTOPRIVATEKEY'),
            'root' => getenv('SFTP_HIGHRES_IMAGES_PATH'),
            'throw' => true,
        ],

        'fullsize' => [
            'driver' => 'local',
            'root' => getenv('FULLSIZE_HOME_DIR'),
            'throw' => true,
        ],

        'archive' => [
            'driver' => 'local',
            'root' => getenv('ARCHIVE_HOME_DIR'),
            'throw' => true,
        ],

        'testing_images' => [
            'driver' => 'local',
            'root' => getenv('TEST_SOURCE_DIR'),
            'throw' => false,
        ],

        'sample_images' => [
            'driver' => 'local',
            'root' => storage_path('sample_images'),
            'throw' => false,
        ],

        'sample_images_bucket' => [
            'driver' => 's3',
            'key' => env('SAMPLE_IMAGES_S3_KEY'),
            'secret' => env('SAMPLE_IMAGES_S3_SECRET'),
            'region' => env('SAMPLE_IMAGES_S3_REGION', 'us-east-1'),
            'bucket' => env('SAMPLE_IMAGES_S3_BUCKET'),
            'url' => env('SAMPLE_IMAGES_S3_URL'),
            'endpoint' => env('SAMPLE_IMAGES_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('SAMPLE_IMAGES_S3_PATH_STYLE', false),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
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
