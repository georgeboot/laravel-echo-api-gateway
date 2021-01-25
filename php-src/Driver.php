<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Aws\DynamoDb\DynamoDbClient;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class Driver extends Broadcaster
{
    use UsePusherChannelConventions;

    protected DynamoDbClient $dynamoDb;
    protected ApiGatewayManagementApiClient $apiGatewayManagementApiClient;
    protected string $table;

    public function __construct(array $config)
    {
        $this->dynamoDb = $this->getDynamoDbClient($config);

        $this->apiGatewayManagementApiClient = $this->getApiGatewayManagementApiClient($config);

        $this->table = $config['table'];
    }

    protected function getDynamoDbClient(array $config): DynamoDbClient
    {
        return new DynamoDbClient(array_merge($config['connection'], [
            'version' => '2012-08-10',
        ]));
    }

    protected function getApiGatewayManagementApiClient(array $config): ApiGatewayManagementApiClient
    {
        return new ApiGatewayManagementApiClient(array_merge($config['connection'], [
            'version' => '2018-11-29',
            'endpoint' => Str::replaceFirst('wss://', 'https://', $config['endpoint']),
        ]));
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return mixed
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) || ($this->isGuardedChannel($request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException();
        }

        return parent::verifyUserCanAccessChannel(
            $request, $channelName
        );
    }


    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     *
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        if (! Str::startsWith($request->channel_name, 'presence')) {
            return response()->json(
                $this->generateSignature($request->channel_name, $request->socket_id)
            );
        }

        $channelName = $this->normalizeChannelName($request->channel_name);

        return response()->json(
            $this->generateSignaturePresence(
                $request->channel_name,
                $request->socket_id,
                $this->retrieveUser($request, $channelName)->getAuthIdentifier(),
                $result
            ),
        );
    }

    protected function generateSignature(string $channel, string $socketId, string $customData = null): array
    {
        $data = $customData ? "{$socketId}:{$channel}:{$customData}" : "{$socketId}:{$channel}";

        $signature = hash_hmac('sha256', $data, config('app.key'), false);

        $response = [
            'auth' => $signature,
        ];

        if ($customData) {
            $response['channel_data'] = $customData;
        }

        return $response;
    }

    protected function generateSignaturePresence(string $channel, string $socketId, int $userId, $userInfo = null): array
    {
        $userData = [
            'user_id' => $userId,
        ];

        if ($userInfo) {
            $userData['user_info'] = $userInfo;
        }

        return $this->generateSignature($channel, $socketId, json_encode($userData));
    }

    /**
     * Broadcast the given event.
     *
     * @param  array  $channels
     * @param  string  $event
     * @param  array  $payload
     * @return void
     *
     * @throws \Illuminate\Broadcasting\BroadcastException
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        // load all connections for the channels we want to send to

        // SELECT `connectionId` from websocket_connections where channel IN ('channel-1', 'channel-2');
        // or similar dynamo query

        $connectionIds = [
            'fakeConnectionId1',
            'fakeConnectionId2',
            'fakeConnectionId3',
        ];

        $payload = [
            'event' => $event,
            'data' => json_encode($payload),
        ];

        $optionalConnectionIdToIgnore = Arr::pull($payload, 'socket');

        $promises = collect($connectionIds)
            ->reject(fn($connectionId) => $connectionId === $optionalConnectionIdToIgnore)
            ->keyBy(fn(string $connectionId) => $connectionId)
            ->map(fn(string $connectionId): Promise => $this->apiGatewayManagementApiClient->postToConnectionAsync([
                'ConnectionId' => $connectionId,
                'Data' => json_encode($payload),
            ])->otherwise(function (ApiGatewayManagementApiException $exception) {
                // $exception->getErrorCode() is one of:
                // GoneException
                // LimitExceededException
                // PayloadTooLargeException
                // ForbiddenException

                // otherwise: call $exception->getPrevious() which is a guzzle exception
            }));

        // resolve all promises. results are keyed by connectionId
        $results = Utils::all($promises)->wait();

        return;

        // if ((is_array($response) && $response['status'] >= 200 && $response['status'] <= 299)
        //     || $response === true) {
        //     return;
        // }
        //
        // throw new BroadcastException(
        //     ! empty($response['body'])
        //         ? sprintf('Pusher error: %s.', $response['body'])
        //         : 'Failed to connect to Pusher.'
        // );
    }
}
