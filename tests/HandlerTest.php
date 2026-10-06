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

it('can broadcast a whisper to the other members of a private channel', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('isSubscribed')->with('connection-id-1', 'private-test-channel')->once()->andReturn(true);
        $mock->shouldReceive('getConnectionIdsForChannel')->with('private-test-channel')->once()
            ->andReturn(collect(['connection-id-1', 'connection-id-2']));
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-2', '{"event":"client-test","channel":"private-test-channel","data":"whisper"}')
            ->once();
    }));

    app(Handler::class)->handle(
        websocketMessage(['event' => 'client-test', 'channel' => 'private-test-channel', 'data' => 'whisper']),
        lambdaContext(),
    );
});

it('forwards whisper payloads without encoding them twice', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('isSubscribed')->andReturn(true);
        $mock->shouldReceive('getConnectionIdsForChannel')->andReturn(collect(['connection-id-2']));
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-2', '{"event":"client-typing","channel":"private-test-channel","data":{"name":"Ana"}}')
            ->once();
    }));

    app(Handler::class)->handle(
        websocketMessage(['event' => 'client-typing', 'channel' => 'private-test-channel', 'data' => ['name' => 'Ana']]),
        lambdaContext(),
    );
});

it('rejects whispers from connections that are not subscribed to the channel', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('isSubscribed')->with('connection-id-1', 'private-test-channel')->once()->andReturn(false);
        $mock->shouldNotReceive('getConnectionIdsForChannel');
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"error","channel":"private-test-channel","data":{"message":"Client events can only be sent to subscribed private or presence channels"}}')
            ->once();
    }));

    app(Handler::class)->handle(
        websocketMessage(['event' => 'client-test', 'channel' => 'private-test-channel', 'data' => 'whisper']),
        lambdaContext(),
    );
});

it('rejects whispers on public channels', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldNotReceive('getConnectionIdsForChannel');
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"error","channel":"test-channel","data":{"message":"Client events can only be sent to subscribed private or presence channels"}}')
            ->once();
    }));

    app(Handler::class)->handle(
        websocketMessage(['event' => 'client-test', 'channel' => 'test-channel', 'data' => 'whisper']),
        lambdaContext(),
    );
});

it('subscribes to private channels with a valid signature', function () {
    $auth = hash_hmac('sha256', 'connection-id-1:private-orders', config('app.key'));

    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('subscribeToChannel')->with('connection-id-1', 'private-orders')->once();
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"subscription_succeeded","channel":"private-orders","data":[]}')
            ->once();
    }));

    app(Handler::class)->handle(
        websocketMessage(['event' => 'subscribe', 'data' => ['channel' => 'private-orders', 'auth' => $auth]]),
        lambdaContext(),
    );
});

it('rejects private channel subscriptions with an invalid signature', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldNotReceive('subscribeToChannel');
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"error","channel":"private-orders","data":{"message":"Invalid auth signature"}}')
            ->once();
    }));

    app(Handler::class)->handle(
        websocketMessage(['event' => 'subscribe', 'data' => ['channel' => 'private-orders', 'auth' => 'forged']]),
        lambdaContext(),
    );
});

it('verifies private channel subscriptions with the dedicated secret when configured', function () {
    config(['laravel-echo-api-gateway.secret' => 'dedicated-secret']);

    $signedWithAppKey = hash_hmac('sha256', 'connection-id-1:private-orders', config('app.key'));
    $signedWithSecret = hash_hmac('sha256', 'connection-id-1:private-orders', 'dedicated-secret');

    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('subscribeToChannel')->with('connection-id-1', 'private-orders')->once();
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"error","channel":"private-orders","data":{"message":"Invalid auth signature"}}')
            ->once()
            ->ordered();
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"subscription_succeeded","channel":"private-orders","data":[]}')
            ->once()
            ->ordered();
    }));

    $handler = app(Handler::class);

    $handler->handle(websocketMessage(['event' => 'subscribe', 'data' => ['channel' => 'private-orders', 'auth' => $signedWithAppKey]]), lambdaContext());
    $handler->handle(websocketMessage(['event' => 'subscribe', 'data' => ['channel' => 'private-orders', 'auth' => $signedWithSecret]]), lambdaContext());
});

it('answers malformed subscribe messages with an error', function () {
    app()->instance(SubscriptionRepository::class, Mockery::mock(SubscriptionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldNotReceive('subscribeToChannel');
    }));

    app()->instance(ConnectionRepository::class, Mockery::mock(ConnectionRepository::class, function ($mock) {
        /** @var Mock $mock */
        $mock->shouldReceive('sendMessage')
            ->with('connection-id-1', '{"event":"error","data":{"message":"Invalid subscribe message"}}')
            ->once();
    }));

    app(Handler::class)->handle(websocketMessage(['event' => 'subscribe']), lambdaContext());
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
