<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class Handler extends WebsocketHandler
{
    protected SubscriptionRepository $subscriptionRepository;
    protected ConnectionRepository $connectionRepository;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        ConnectionRepository $connectionRepository
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
        } elseif (Str::startsWith($eventType, 'client-')) {
            $this->broadcastToChannel($event, $context);
        } else {
            $this->sendMessage($event, $context, [
                'event' => 'error'
            ]);
        }
    }

    protected function subscribe(WebsocketEvent $event, Context $context): void
    {
        $eventBody = json_decode($event->getBody(), true);
        $data = $eventBody['data'] ?? null;

        if (! is_array($data) || ! is_string($data['channel'] ?? null)) {
            $this->sendMessage($event, $context, [
                'event' => 'error',
                'data' => [
                    'message' => 'Invalid subscribe message',
                ],
            ]);

            return;
        }

        $channel = $data['channel'];
        $auth = is_string($data['auth'] ?? null) ? $data['auth'] : '';
        $channelData = is_string($data['channel_data'] ?? null) ? $data['channel_data'] : null;

        if (Str::startsWith($channel, ['private-', 'presence-'])
            && ! Signature::verify($auth, $event->getConnectionId(), $channel, $channelData)) {
            $this->sendMessage($event, $context, [
                'event' => 'error',
                'channel' => $channel,
                'data' => [
                    'message' => 'Invalid auth signature',
                ],
            ]);

            return;
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

    public function broadcastToChannel(WebsocketEvent $event, Context $context): void
    {
        $senderConnectionId = $event->getConnectionId();
        $eventBody = json_decode($event->getBody(), true);
        $channel = (string) Arr::get($eventBody, 'channel');

        // Like Pusher, only members of private or presence channels may send client events.
        if (! Str::startsWith($channel, ['private-', 'presence-'])
            || ! $this->subscriptionRepository->isSubscribed($senderConnectionId, $channel)) {
            $this->sendMessage($event, $context, [
                'event' => 'error',
                'channel' => $channel,
                'data' => [
                    'message' => 'Client events can only be sent to subscribed private or presence channels',
                ],
            ]);

            return;
        }

        $data = json_encode([
            'event' => Arr::get($eventBody, 'event'),
            'channel' => $channel,
            'data' => Arr::get($eventBody, 'data'),
        ], JSON_THROW_ON_ERROR);

        $this->subscriptionRepository->getConnectionIdsForChannel($channel)
            ->reject(fn ($connectionId) => $connectionId === $senderConnectionId)
            ->each(fn (string $connectionId) => $this->sendMessageToConnection($connectionId, $data));
    }

    public function sendMessage(WebsocketEvent $event, Context $context, array $data): void
    {
        $this->connectionRepository->sendMessage($event->getConnectionId(), json_encode($data, JSON_THROW_ON_ERROR));
    }

    protected function sendMessageToConnection(string $connectionId, string $data): void
    {
        try {
            $this->connectionRepository->sendMessage($connectionId, $data);
        } catch (ApiGatewayManagementApiException $exception) {
            if ($exception->getAwsErrorCode() === 'GoneException') {
                $this->subscriptionRepository->clearConnection($connectionId);
                return;
            }

            throw $exception;
        }
    }
}
