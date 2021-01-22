<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

class LaravelEchoApiGatewayHandler extends WebsocketHandler
{
    protected ExceptionHandler $exceptionHandler;

    public function __construct(ExceptionHandler $exceptionHandler)
    {
        $this->exceptionHandler = $exceptionHandler;
    }

    public function handleWebsocket(WebsocketEvent $event, Context $context): HttpResponse
    {
        try {
            switch ($event->getEventType()) {
                case 'CONNECT':
                    return $this->handleConnect($event, $context);

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

    protected function handleConnect(WebsocketEvent $event, Context $context): HttpResponse
    {
        return new HttpResponse(json_encode([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $event->getConnectionId(),
                'activity_timeout' => 10 * 60,
            ]),
        ]));
    }

    protected function handleDisconnect(WebsocketEvent $event, Context $context): HttpResponse
    {
        return new HttpResponse('disconnect');
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
            return new HttpResponse(json_encode([
                'event' => 'subscription_succeeded',
                'channel' => $eventBody['data']['channel'],
                'data' => [],
            ]));
        }

        if ($eventType === 'unsubscribe') {
            return new HttpResponse(json_encode([
                'event' => 'unsubscription_succeeded',
                'channel' => $eventBody['data']['channel'],
                'data' => [],
            ]));
        }


        return new HttpResponse(json_encode([
            'event' => 'error',
        ]));
    }
}
