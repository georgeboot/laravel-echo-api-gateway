<?php

return [

    'connection' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'api' => [
        'id' => env('LARAVEL_ECHO_API_GATEWAY_API_ID'),
        'stage' => env('LARAVEL_ECHO_API_GATEWAY_API_STAGE'),
    ],

    'dynamodb' => [
        'endpoint' => env('DYNAMODB_ENDPOINT'),
        'table' => env('LARAVEL_ECHO_API_GATEWAY_DYNAMODB_TABLE', 'connections'),
    ],

];
