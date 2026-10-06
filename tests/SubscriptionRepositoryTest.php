<?php

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Georgeboot\LaravelEchoApiGateway\SubscriptionRepository;

function subscriptionRepository(MockHandler $handler): SubscriptionRepository
{
    $config = config('laravel-echo-api-gateway');
    $config['connection'] = array_merge($config['connection'], [
        'handler' => $handler,
        'credentials' => ['key' => 'fake', 'secret' => 'fake'],
    ]);

    return new SubscriptionRepository($config);
}

function connectionItems(int $from, int $to, string $channel = 'private-orders'): array
{
    return array_map(fn (int $i) => [
        'connectionId' => ['S' => "connection-{$i}"],
        'channel' => ['S' => $channel],
    ], range($from, $to));
}

it('reads every page of connections subscribed to a channel', function () {
    $handler = new MockHandler();
    $handler->append(new Result(['Items' => connectionItems(1, 2), 'LastEvaluatedKey' => ['connectionId' => ['S' => 'connection-2']]]));
    $handler->append(new Result(['Items' => connectionItems(3, 3)]));

    $connectionIds = subscriptionRepository($handler)->getConnectionIdsForChannel('private-orders');

    expect($connectionIds->values()->all())->toBe(['connection-1', 'connection-2', 'connection-3']);
});

it('clears every subscription of a connection in batches of 25', function () {
    $batchSizes = [];

    $handler = new MockHandler();
    $handler->append(new Result(['Items' => connectionItems(1, 20), 'LastEvaluatedKey' => ['connectionId' => ['S' => 'connection-20']]]));
    $handler->append(new Result(['Items' => connectionItems(21, 30)]));
    $recordBatch = function (CommandInterface $command) use (&$batchSizes) {
        $batchSizes[] = count($command['RequestItems']['connections']);

        return new Result([]);
    };
    $handler->append($recordBatch, $recordBatch);

    subscriptionRepository($handler)->clearConnection('connection-1');

    expect($batchSizes)->toBe([25, 5]);
});

it('knows whether a connection is subscribed to a channel', function () {
    $handler = new MockHandler();
    $handler->append(new Result(['Item' => connectionItems(1, 1)[0]]));
    $handler->append(new Result([]));

    $repository = subscriptionRepository($handler);

    expect($repository->isSubscribed('connection-1', 'private-orders'))->toBeTrue()
        ->and($repository->isSubscribed('connection-2', 'private-orders'))->toBeFalse();
});
