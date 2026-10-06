<?php

namespace Georgeboot\LaravelEchoApiGateway;

class Signature
{
    public static function make(string $socketId, string $channel, ?string $channelData = null): string
    {
        $data = $channelData ? "{$socketId}:{$channel}:{$channelData}" : "{$socketId}:{$channel}";

        return hash_hmac('sha256', $data, static::secret(), false);
    }

    public static function verify(string $signature, string $socketId, string $channel, ?string $channelData = null): bool
    {
        return hash_equals(static::make($socketId, $channel, $channelData), $signature);
    }

    protected static function secret(): string
    {
        $secret = config('laravel-echo-api-gateway.secret') ?: config('app.key');

        return is_string($secret) ? $secret : '';
    }
}
