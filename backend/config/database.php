<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'postgres'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'lumen_db'),
            'username' => env('DB_USERNAME', 'lumen_user'),
            'password' => env('DB_PASSWORD', 'secret_password'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ],
    ],

    'migrations' => 'migrations',
];
