<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Illuminate\Support\Str;
use Throwable;

class Handler extends WebsocketHandler
{
    public function __construct(
        protected SubscriptionRepository $subscriptionRepository,
        protected ConnectionRepository $connectionRepository
    ) {
        $this->subscriptionRepository = $subscriptionRepository;
        $this->connectionRepository = $connectionRepository;
    }

    public function handleWebsocket(WebsocketEvent $event, Context $context): HttpResponse
    {
        try {
            $method = Str::camel('handle_' . Str::lower($event->getEventType() ?? ''));

            if (! method_exists($this, $method)) {
                throw new \InvalidArgumentException("Event type {$event->getEventType()} has no handler implemented.");
            }

            $this->$method($event, $context);

            return new HttpResponse('OK');
        } catch (Throwable $throwable) {
            report($throwable);

            throw $throwable;
        }
    }

    protected function handleDisconnect(WebsocketEvent $event, Context $context): void
    {
        $this->subscriptionRepository->clearConnection($event->getConnectionId());
    }

    protected function handleMessage(WebsocketEvent $event, Context $context): void
    {
        $eventBody = json_decode($event->getBody(), true);

        if (! isset($eventBody['event'])) {
            throw new \InvalidArgumentException('event missing or no valid json');
        }

        $eventType = $eventBody['event'];

        if ($eventType === 'ping') {
            $this->sendMessage($event, $context, [
                'event' => 'pong',
                'channel' => $eventBody['channel'] ?? null,
            ]);
        } elseif ($eventType === 'whoami') {
            $this->sendMessage($event, $context, [
                'event' => 'whoami',
                'data' => [
                    'socket_id' => $event->getConnectionId(),
                ],
            ]);
        } elseif ($eventType === 'subscribe') {
            $this->subscribe($event, $context);
        } elseif ($eventType === 'unsubscribe') {
            $this->unsubscribe($event, $context);
        } else {
            $this->sendMessage($event, $context, [
                'event' => 'error',
            ]);
        }
    }

    protected function subscribe(WebsocketEvent $event, Context $context): void
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
                $data .= ':' . $channelData;
            }

            $signature = hash_hmac('sha256', $data, config('app.key'), false);

            if ($signature !== $auth) {
                $this->sendMessage($event, $context, [
                    'event' => 'error',
                    'channel' => $channel,
                    'data' => [
                        'message' => 'Invalid auth signature',
                    ],
                ]);

                return;
            }
        }

        $this->subscriptionRepository->subscribeToChannel($event->getConnectionId(), $channel);

        $this->sendMessage($event, $context, [
            'event' => 'subscription_succeeded',
            'channel' => $channel,
            'data' => [],
        ]);
    }

    protected function unsubscribe(WebsocketEvent $event, Context $context): void
    {
        $eventBody = json_decode($event->getBody(), true);
        $channel = $eventBody['data']['channel'];

        $this->subscriptionRepository->unsubscribeFromChannel($event->getConnectionId(), $channel);

        $this->sendMessage($event, $context, [
            'event' => 'unsubscription_succeeded',
            'channel' => $channel,
            'data' => [],
        ]);
    }

    public function sendMessage(WebsocketEvent $event, Context $context, array $data): void
    {
        $this->connectionRepository->sendMessage($event->getConnectionId(), json_encode($data, JSON_THROW_ON_ERROR));
    }
}
