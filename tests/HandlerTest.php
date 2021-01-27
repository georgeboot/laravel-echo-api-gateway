<?php

use Bref\Context\Context;
use Georgeboot\LaravelEchoApiGateway\ConnectionRepository;
use Georgeboot\LaravelEchoApiGateway\Handler;
use Georgeboot\LaravelEchoApiGateway\SubscriptionRepository;
use Mockery\Mock;

it('can subscribe to open channels', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('subscribeToChannel')->withArgs(function (string $connectionId, string $channel): bool {
            return $connectionId === 'connection-id-1' and $channel === 'test-channel';
        })->once();
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')->withArgs(function (string $connectionId, string $data): bool {
            return $connectionId === 'connection-id-1' and $data === '{"event":"subscription_succeeded","channel":"test-channel","data":[]}';
        })->once();
    }));

    /** @var Handler $handler */
    $handler = app(Handler::class);

    $context = new Context('request-id-1', 50_000, 'function-arn', 'trace-id-1');

    $handler->handle([
        'requestContext' => [
            'routeKey' => 'my-test-route-key',
            'eventType' => 'MESSAGE',
            'connectionId' => 'connection-id-1',
            'domainName' => 'test-domain',
            'apiId' => 'api-id-1',
            'stage' => 'stage-test',
        ],
        'body' => json_encode(['event' => 'subscribe', 'data' => ['channel' => 'test-channel']]),
    ], $context);
});

it('can unsubscribe from a channel', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('unsubscribeFromChannel')->withArgs(function (string $connectionId, string $channel): bool {
            return $connectionId === 'connection-id-1' and $channel === 'test-channel';
        })->once();
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')->withArgs(function (string $connectionId, string $data): bool {
            return $connectionId === 'connection-id-1' and $data === '{"event":"unsubscription_succeeded","channel":"test-channel","data":[]}';
        })->once();
    }));

    /** @var Handler $handler */
    $handler = app(Handler::class);

    $context = new Context('request-id-1', 50_000, 'function-arn', 'trace-id-1');

    $handler->handle([
        'requestContext' => [
            'routeKey' => 'my-test-route-key',
            'eventType' => 'MESSAGE',
            'connectionId' => 'connection-id-1',
            'domainName' => 'test-domain',
            'apiId' => 'api-id-1',
            'stage' => 'stage-test',
        ],
        'body' => json_encode(['event' => 'unsubscribe', 'data' => ['channel' => 'test-channel']]),
    ], $context);
});
