<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class LaravelEchoApiGatewayServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @param BroadcastManager $broadcastManager
     *
     * @return void
     */
    public function boot(BroadcastManager $broadcastManager)
    {
        $broadcastManager->extend('laravel-echo-api-gateway', function (Application $app, array $config): Broadcaster {
            return new LaravelEchoApiGatewayDriver($config);
        });
    }
}
