<?php

return [

    'connection' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'api' => [
        'id' => env('LARAVEL_ECHO_API_GATEWAY_API_ID'),
        'stage' => env('LARAVEL_ECHO_API_GATEWAY_API_STAGE'),
    ],

    /*
     * Secret used to sign and verify private and presence channel subscriptions. The websocket
     * handler and the app that authorizes channels must share it. Falls back to the app key.
     */
    'secret' => env('LARAVEL_ECHO_API_GATEWAY_SECRET'),

    'dynamodb' => [
        'endpoint' => env('DYNAMODB_ENDPOINT'),
        'table' => env('LARAVEL_ECHO_API_GATEWAY_DYNAMODB_TABLE', 'connections'),
    ],

];
