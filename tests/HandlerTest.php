<?php

use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Bref\Context\Context;
use Georgeboot\LaravelEchoApiGateway\ConnectionRepository;
use Georgeboot\LaravelEchoApiGateway\Handler;
use Georgeboot\LaravelEchoApiGateway\SubscriptionRepository;
use GuzzleHttp\Psr7\Response;
use Mockery\Mock;
use Psr\Http\Message\RequestInterface;

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

it('handles dropped connections', function () {
    $mock = new MockHandler();

    $mock->append(function (CommandInterface $cmd, RequestInterface $req) {
        return new  ApiGatewayManagementApiException('', $cmd, ['code' => 'GoneException']);
    });

    /** @var SubscriptionRepository */
    $subscriptionRepository = Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('clearConnection')->withArgs(function (string $connectionId): bool {
            return $connectionId === 'dropped-connection-id-1234';
        })->once();
    });

    $config = config('laravel-echo-api-gateway');

    /** @var ConnectionRepository */
    $connectionRepository = new ConnectionRepository($subscriptionRepository, array_merge_recursive(['connection' => ['handler' => $mock]], $config));

    $connectionRepository->sendMessage('dropped-connection-id-1234', 'test-message');
});
