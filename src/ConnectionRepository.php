<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;

class ConnectionRepository
{
    protected ApiGatewayManagementApiClient $apiGatewayManagementApiClient;

    public function __construct(
        protected SubscriptionRepository $subscriptionRepository,
        array $config
    ) {
        $this->apiGatewayManagementApiClient = new ApiGatewayManagementApiClient(array_merge($config['connection'], [
            'version' => '2018-11-29',
            'endpoint' => "https://{$config['api']['id']}.execute-api.{$config['connection']['region']}.amazonaws.com/{$config['api']['stage']}/",
        ]));
    }

    public function sendMessage(string $connectionId, string $data): void
    {
        try {
            $this->apiGatewayManagementApiClient->postToConnection([
                'ConnectionId' => $connectionId,
                'Data' => $data,
            ]);
        } catch (ApiGatewayManagementApiException $e) {
            // GoneException: The connection with the provided id no longer exists.
            if ($e->getAwsErrorCode() === 'Gone') {
                $this->subscriptionRepository->clearConnection($connectionId);

                return;
            }

            throw $e;
        }
    }
}
