<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class ServiceProvider extends LaravelServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-echo-api-gateway.php', 'laravel-echo-api-gateway'
        );

        Config::set('broadcasting.connections.laravel-echo-api-gateway', [
            'driver' => 'laravel-echo-api-gateway',
        ]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laravel-echo-api-gateway.php' => config_path('laravel-echo-api-gateway.php'),
            ], 'laravel-echo-api-gateway-config');
        }
    }

    public function boot(BroadcastManager $broadcastManager)
    {
        $broadcastManager->extend('laravel-echo-api-gateway', function (): Broadcaster {
            return new Driver(
                config('laravel-echo-api-gateway')
            );
        });
    }
}
