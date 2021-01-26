<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;
use Throwable;

class Handler extends WebsocketHandler
{
    protected ExceptionHandler $exceptionHandler;
    protected SubscriptionRepository $connectionRepository;

    public function __construct(ExceptionHandler $exceptionHandler, SubscriptionRepository $connectionRepository)
    {
        $this->exceptionHandler = $exceptionHandler;
        $this->connectionRepository = $connectionRepository;
    }

    public function handleWebsocket(WebsocketEvent $event, Context $context): HttpResponse
    {
        try {
            $method = Str::camel('handle_' . Str::lower($event->getEventType() ?? ''));

            if (! method_exists($this, $method)) {
                throw new \InvalidArgumentException("Event type {$event->getEventType()} has no handler implemented.");
            }

            return $this->$method($event, $context);
        } catch (Throwable $throwable) {
            $this->exceptionHandler->report($throwable);

            throw $throwable;
        }
    }

    protected function handleDisconnect(WebsocketEvent $event, Context $context): HttpResponse
    {
        $this->connectionRepository->clearConnection($event->getConnectionId());

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
            return $this->jsonResponse([
                'event' => 'pong',
                'channel' => $eventBody['channel'] ?? null,
            ]);
        }

        if ($eventType === 'whoami') {
            return $this->jsonResponse([
                'event' => 'whoami',
                'data' => [
                    'socket_id' => $event->getConnectionId(),
                ],
            ]);
        }

        if ($eventType === 'subscribe') {
            return $this->subscribe($event, $context);
        }

        if ($eventType === 'unsubscribe') {
            return $this->unsubscribe($event, $context);
        }


        return $this->jsonResponse([
            'event' => 'error',
        ]);
    }

    protected function subscribe(WebsocketEvent $event, Context $context): HttpResponse
    {
        $eventBody = json_decode($event->getBody(), true);

        // fill missing values
        $eventBody['data'] += ['auth' => null, 'channel_data' => []];

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
                return $this->jsonResponse([
                    'event' => 'error',
                    'channel' => $channel,
                    'data' => [
                        'message' => 'Invalid auth signature',
                    ],
                ]);
            }
        }

        $this->connectionRepository->subscribeToChannel($event->getConnectionId(), $channel);

        return $this->jsonResponse([
            'event' => 'subscription_succeeded',
            'channel' => $channel,
            'data' => [],
        ]);
    }

    protected function unsubscribe(WebsocketEvent $event, Context $context): HttpResponse
    {
        $eventBody = json_decode($event->getBody(), true);
        $channel = $eventBody['data']['channel'];

        $this->connectionRepository->unsubscribeFromChannel($event->getConnectionId(), $channel);

        return $this->jsonResponse([
            'event' => 'unsubscription_succeeded',
            'channel' => $channel,
            'data' => [],
        ]);
    }

    protected function jsonResponse(array $data): HttpResponse
    {
        return new HttpResponse(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
