<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
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
        $this->sendPresenceDisconnectNotices($event);
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

        if (Str::startsWith($channel, 'presence-')) {
            $this->subscriptionRepository->subscribeToPresenceChannel(
                $event->getConnectionId(),
                $channelData,
                $channel
            );
            $data = $this->subscriptionRepository->getUserListForPresenceChannel($channel)
                ->transform(function ($user) {
                    $user = json_decode($user, true);
                    return Arr::get($user, 'user_info', json_encode($user));
                })
                ->toArray();
            $this->sendPresenceAdd($event, $channel, Arr::get(json_decode($channelData, true), 'user_info'));
        } else {
            $this->subscriptionRepository->subscribeToChannel($event->getConnectionId(), $channel);
            $data = [];
        }

        $this->sendMessage($event, $context, [
            'event' => 'subscription_succeeded',
            'channel' => $channel,
            'data' => $data,
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

    public function sendPresenceDisconnectNotices(WebsocketEvent $event): void
    {
        $channels = $this->subscriptionRepository->getChannelsSubscribedToByConnectionId($event->getConnectionId());
        $channels->filter(function ($info) {
            return Str::startsWith(Arr::get($info, 'channel'), 'presence-');
        })->each(function ($info) use ($event) {
            $channel = Arr::get($info, 'channel');
            $userData = json_decode(Arr::get($info, 'userData'), true);
            $this->sendPresenceRemove($event, $channel, Arr::get($userData, 'user_info'));
        });
    }

    public function broadcastToChannel(WebsocketEvent $event, Context $context): void
    {
        $skipConnectionId = $event->getConnectionId();
        $eventBody = json_decode($event->getBody(), true);
        $channel = Arr::get($eventBody, 'channel');
        $event = Arr::get($eventBody, 'event');
        $payload = Arr::get($eventBody, 'data');
        if (is_object($payload) || is_array($payload)) {
            $payload = json_encode($payload);
        }
        $data = json_encode([
            'event'=>$event,
            'channel'=>$channel,
            'data'=>$payload,
        ]) ?: '';
        $this->subscriptionRepository->getConnectionIdsForChannel($channel)
            ->reject(fn ($connectionId) => $connectionId === $skipConnectionId)
            ->each(fn (string $connectionId) => $this->sendMessageToConnection($connectionId, $data));
    }

    public function sendPresenceAdd(WebsocketEvent $event, string $channel, array $data): void
    {
        $skipConnectionId = $event->getConnectionId();
        $eventBody = json_decode($event->getBody(), true);
        $data = json_encode([
            'event'=>'member_added',
            'channel'=>$channel,
            'data'=>$data
        ]) ?: '';
        $this->subscriptionRepository->getConnectionIdsForChannel($channel)
            ->reject(fn ($connectionId) => $connectionId === $skipConnectionId)
            ->each(fn (string $connectionId) => $this->sendMessageToConnection($connectionId, $data));
    }

    public function sendPresenceRemove(WebsocketEvent $event, string $channel, array $data): void
    {
        $skipConnectionId = $event->getConnectionId();
        $eventBody = json_decode($event->getBody(), true);
        $data = json_encode([
            'event'=>'member_removed',
            'channel'=>$channel,
            'data'=>$data
        ]) ?: '';
        $this->subscriptionRepository->getConnectionIdsForChannel($channel)
            ->reject(fn ($connectionId) => $connectionId === $skipConnectionId)
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
            if ($exception->getStatusCode() === Response::HTTP_GONE) {
                $this->subscriptionRepository->clearConnection($connectionId);
                return;
            }

            throw $exception;
        }
    }
}
