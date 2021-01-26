<?php

use Bref\Context\Context;
use Georgeboot\LaravelEchoApiGateway\Handler;
use Georgeboot\LaravelEchoApiGateway\SubscriptionRepository;
use Mockery\Mock;

it('can subscribe to open channels', function () {
    $mock = Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('subscribeToChannel')->withArgs(function (string $connectionId, string $channel): bool {
            return $connectionId === 'connection-id-1' and $channel === 'test-channel';
        })->once();
    });

    app()->instance(SubscriptionRepository::class, $mock);

    /** @var Handler $handler */
    $handler = app(Handler::class);

    $context = new Context('request-id-1', 50_000, 'function-arn', 'trace-id-1');

    $response = $handler->handle([
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

    expect($response['body'])->toBeJson()->toEqual('{"event":"subscription_succeeded","channel":"test-channel","data":[]}');
});

it('can unsubscribe from a channel', function () {
    $mock = Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('unsubscribeFromChannel')->withArgs(function (string $connectionId, string $channel): bool {
            return $connectionId === 'connection-id-1' and $channel === 'test-channel';
        })->once();
    });

    app()->instance(SubscriptionRepository::class, $mock);

    /** @var Handler $handler */
    $handler = app(Handler::class);

    $context = new Context('request-id-1', 50_000, 'function-arn', 'trace-id-1');

    $response = $handler->handle([
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

    expect($response['body'])->toBeJson()->toEqual('{"event":"unsubscription_succeeded","channel":"test-channel","data":[]}');
});
