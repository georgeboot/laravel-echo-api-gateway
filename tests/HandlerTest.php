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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

it('can broadcast a whisper', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('getConnectionIdsForChannel')->withArgs(function (string $channel): bool {
            return $channel === 'test-channel';
        })->once()
        ->andReturn(collect(['connection-id-1', 'connection-id-2']));
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')->withArgs(function (string $connectionId, string $data): bool {
            return $connectionId === 'connection-id-2' and $data === '{"event":"client-test","channel":"test-channel","data":"whisper"}';
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
        'body' => json_encode(['event' => 'client-test', 'channel' => 'test-channel', 'data'=>'whisper']),
    ], $context);
});

it('leaves presence channels', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('getChannelsSubscribedToByConnectionId')->withArgs(function (string $connectionId): bool {
            return $connectionId === 'connection-id-1';
        })->once()
        ->andReturn(collect([
            [
                'channel'=>'presence-channel',
                'userData'=>json_encode(['user_info'=>['the user info']]),
            ],
            [
                'channel'=>'other-channel',
            ]
        ]));
        $mock->shouldReceive('getConnectionIdsForChannel')->withArgs(function (string $channel) {
            return $channel === 'presence-channel';
        })->once()
        ->andReturn(collect(['connection-id-1', 'connection-id-2']));
        $mock->shouldReceive('clearConnection')->withArgs(function (string $connectionId) {
            return $connectionId === 'connection-id-1';
        })->once();
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')->withArgs(function (string $connectionId, string $data): bool {
            return $connectionId === 'connection-id-2' and $data === '{"event":"member_removed","channel":"presence-channel","data":["the user info"]}';
        })->once();
    }));

    /** @var Handler $handler */
    $handler = app(Handler::class);

    $context = new Context('request-id-1', 50_000, 'function-arn', 'trace-id-1');

    $handler->handle([
        'requestContext' => [
            'routeKey' => 'my-test-route-key',
            'eventType' => 'DISCONNECT',
            'connectionId' => 'connection-id-1',
            'domainName' => 'test-domain',
            'apiId' => 'api-id-1',
            'stage' => 'stage-test',
        ],
        'body' => json_encode(['event' => 'disconnect']),
    ], $context);
});

it('handles dropped connections with HTTP_GONE', function () {
    $mock = new MockHandler();

    $mock->append(function (CommandInterface $cmd, RequestInterface $req) {
        $mock = Mockery::mock(SymfonyResponse::class, function ($mock) {
            $mock->shouldReceive('getStatusCode')
                ->andReturn(SymfonyResponse::HTTP_GONE);
        });
        return new  ApiGatewayManagementApiException('', $cmd, [
            'response' => $mock
        ]);
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

it('handles dropped connections with GoneException', function () {
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
