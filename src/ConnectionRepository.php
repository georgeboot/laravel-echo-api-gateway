<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;

class ConnectionRepository
{
    protected ApiGatewayManagementApiClient $apiGatewayManagementApiClient;
    protected SubscriptionRepository $subscriptionRepository;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        array $config
    ) {
        $this->subscriptionRepository = $subscriptionRepository;

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
             if ($e->getAwsErrorCode() === 'GoneException') {
                $this->subscriptionRepository->clearConnection($connectionId);

                return;
            }

            throw $e;
        }
    }
}
