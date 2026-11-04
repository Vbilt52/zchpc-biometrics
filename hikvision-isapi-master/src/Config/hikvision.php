<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default ZCHPC Access Control Device
    |--------------------------------------------------------------------------
    |
    | Specify which device should be used by default when not explicitly
    | specified. This should match one of the device keys below.
    |
    */
    'default' => env('ZCHPC_DEFAULT_DEVICE', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | ZCHPC Access Control Devices
    |--------------------------------------------------------------------------
    |
    | Configure multiple Hikvision ISAPI access-control devices here.
    | Each device should have its own IP, credentials, and connection settings.
    |
    | You can add as many devices as needed for your ZCHPC installation.
    | Example: 'entrance', 'exit', 'server_room', 'data_centre', etc.
    |
    */
    'devices' => [
        'primary' => [
            'ip' => env('ZCHPC_DEVICE_IP', '192.168.1.100'),
            'port' => env('ZCHPC_DEVICE_PORT', 80),
            'username' => env('ZCHPC_DEVICE_USERNAME', 'admin'),
            'password' => env('ZCHPC_DEVICE_PASSWORD'),
            'protocol' => env('ZCHPC_DEVICE_PROTOCOL', 'http'), // http or https
            'timeout' => env('ZCHPC_DEVICE_TIMEOUT', 30),
            'verify_ssl' => env('ZCHPC_DEVICE_VERIFY_SSL', false),
        ],

        // Example: Additional ZCHPC site device configurations
        // Uncomment and configure for multiple access points
        /*
        'entrance' => [
            'ip' => env('ZCHPC_ENTRANCE_IP', '192.168.1.101'),
            'port' => env('ZCHPC_ENTRANCE_PORT', 80),
            'username' => env('ZCHPC_ENTRANCE_USERNAME', 'admin'),
            'password' => env('ZCHPC_ENTRANCE_PASSWORD'),
            'protocol' => env('ZCHPC_ENTRANCE_PROTOCOL', 'http'),
            'timeout' => env('ZCHPC_ENTRANCE_TIMEOUT', 30),
            'verify_ssl' => env('ZCHPC_ENTRANCE_VERIFY_SSL', false),
        ],

        'exit' => [
            'ip' => env('ZCHPC_EXIT_IP', '192.168.1.102'),
            'port' => env('ZCHPC_EXIT_PORT', 80),
            'username' => env('ZCHPC_EXIT_USERNAME', 'admin'),
            'password' => env('ZCHPC_EXIT_PASSWORD'),
            'protocol' => env('ZCHPC_EXIT_PROTOCOL', 'http'),
            'timeout' => env('ZCHPC_EXIT_TIMEOUT', 30),
            'verify_ssl' => env('ZCHPC_EXIT_VERIFY_SSL', false),
        ],

        'server_room' => [
            'ip' => env('ZCHPC_SERVER_ROOM_IP', '192.168.1.103'),
            'port' => env('ZCHPC_SERVER_ROOM_PORT', 80),
            'username' => env('ZCHPC_SERVER_ROOM_USERNAME', 'admin'),
            'password' => env('ZCHPC_SERVER_ROOM_PASSWORD'),
            'protocol' => env('ZCHPC_SERVER_ROOM_PROTOCOL', 'http'),
            'timeout' => env('ZCHPC_SERVER_ROOM_TIMEOUT', 30),
            'verify_ssl' => env('ZCHPC_SERVER_ROOM_VERIFY_SSL', false),
        ],
        */
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Format
    |--------------------------------------------------------------------------
    */
    'format' => env('ZCHPC_DEVICE_FORMAT', 'json'), // json or xml

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('ZCHPC_LOGGING', true),
        'channel' => env('ZCHPC_LOG_CHANNEL', 'stack'),
    ],
];
