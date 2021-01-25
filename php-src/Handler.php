<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\DynamoDb\DynamoDbClient;
use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class Handler extends WebsocketHandler
{
    protected ExceptionHandler $exceptionHandler;
    protected DynamoDbClient $dynamoDb;
    protected string $table;

    public function __construct(ExceptionHandler $exceptionHandler)
    {
        $config = config('laravel-echo-api-gateway');

        $this->exceptionHandler = $exceptionHandler;
        $this->dynamoDb = $this->getDynamoDbClient($config);
        $this->table = $config['table'];
    }

    protected function getDynamoDbClient(array $config): DynamoDbClient
    {
        return new DynamoDbClient(array_merge($config['connection'], [
            'version' => '2012-08-10',
        ]));
    }

    public function handleWebsocket(WebsocketEvent $event, Context $context): HttpResponse
    {
        try {
            switch ($event->getEventType()) {
                case 'DISCONNECT':
                    return $this->handleDisconnect($event, $context);

                default:
                    return $this->handleMessage($event, $context);
            }
        } catch (Throwable $throwable) {
            $this->exceptionHandler->report($throwable);

            throw $throwable;
        }
    }

    protected function handleDisconnect(WebsocketEvent $event, Context $context): HttpResponse
    {
        $response = $this->dynamoDb->query([
            'TableName' => $this->table,
            'IndexName' => 'lookup-by-connection',
            'KeyConditionExpression' => 'connectionId = :connectionId',
            'ExpressionAttributeValues' => [
                ':connectionI' => ['S' => $event->getConnectionId()],
            ],
        ]);

        $this->dynamoDb->batchWriteItem([
            $this->table => collect($response['Items'])->map(fn($item) => [
                'DeleteRequest' => [
                    'Key' => Arr::only($item, ['connectionId', 'channel']),
                ],
            ])->toArray(),
        ]);

        return new HttpResponse('OK');
    }

    protected function handleMessage(WebsocketEvent $event, Context $context): HttpResponse
    {
        $eventBody = json_decode($event->getBody(), true);

        if (! isset($eventBody['event'])) {
            throw new \InvalidArgumentException('event missing or no valid json');
        }

        $eventType = $eventBody['event'];

        if ($eventType === 'ping') {
            return new HttpResponse(json_encode([
                'event' => 'pong',
                'channel' => 'test',
            ]));
        }

        if ($eventType === 'whoami') {
            return new HttpResponse(json_encode([
                'event' => 'whoami',
                'data' => [
                    'socket_id' => $event->getConnectionId(),
                ],
            ]));
        }

        if ($eventType === 'subscribe') {
            return $this->unsubscribe($event, $context);
        }

        if ($eventType === 'unsubscribe') {
            return $this->subscribe($event, $context);
        }


        return new HttpResponse(json_encode([
            'event' => 'error',
        ]));
    }

    protected function subscribe(WebsocketEvent $event, Context $context): HttpResponse
    {
        $eventBody = json_decode($event->getBody(), true);
        [
            'channel' => $channel,
            'auth' => $auth,
            'channel_data' => $channelData,
        ] = $eventBody['data'];

        if (Str::startsWith($channel, ['private-', 'presence-'])) {
            $data = "{$event->getConnectionId()}:{$channel}";

            if ($channelData) {
                $data .= ':' . json_encode($channelData);
            }

            $signature = hash_hmac('sha256', $data, config('app.key'), false);

            if ($signature !== $auth) {
                return new HttpResponse(json_encode([
                    'event' => 'error',
                    'channel' => $channel,
                    'data' => [
                        'message' => 'Invalid auth signature',
                    ],
                ]));
            }
        }

        $this->dynamoDb->putItem([
            'TableName' => $this->table,
            'Item' => [
                'connectionId' => ['S' => $event->getConnectionId()],
                'channel' => ['S' => $channel],
            ],
        ]);

        return new HttpResponse(json_encode([
            'event' => 'subscription_succeeded',
            'channel' => $channel,
            'data' => [],
        ]));
    }

    protected function unsubscribe(WebsocketEvent $event, Context $context): HttpResponse
    {
        $eventBody = json_decode($event->getBody(), true);
        $channel = $eventBody['data']['channel'];

        $this->dynamoDb->deleteItem([
            'TableName' => $this->table,
            'Key' => [
                'connectionId' => ['S' => $event->getConnectionId()],
                'channel' => ['S' => $channel],
            ],
        ]);

        return new HttpResponse(json_encode([
            'event' => 'unsubscription_succeeded',
            'channel' => $channel,
            'data' => [],
        ]));
    }
}
