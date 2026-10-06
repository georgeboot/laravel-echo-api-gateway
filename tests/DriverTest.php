<?php

use Georgeboot\LaravelEchoApiGateway\Driver;
use Illuminate\Http\Request;

function authRequest(string $channel, string $socketId = 'connection-id-1'): Request
{
    return Request::create('/broadcasting/auth', 'POST', ['channel_name' => $channel, 'socket_id' => $socketId]);
}

it('signs private channel authorizations with the app key by default', function () {
    $response = app(Driver::class)->validAuthenticationResponse(authRequest('private-orders'), true);

    expect($response->getData(true))->toBe([
        'auth' => hash_hmac('sha256', 'connection-id-1:private-orders', config('app.key')),
    ]);
});

it('signs private channel authorizations with the dedicated secret when configured', function () {
    config(['laravel-echo-api-gateway.secret' => 'dedicated-secret']);

    $response = app(Driver::class)->validAuthenticationResponse(authRequest('private-orders'), true);

    expect($response->getData(true))->toBe([
        'auth' => hash_hmac('sha256', 'connection-id-1:private-orders', 'dedicated-secret'),
    ]);
});
