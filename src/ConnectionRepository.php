<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;

class ConnectionRepository
{
    protected ApiGatewayManagementApiClient $apiGatewayManagementApiClient;

    public function __construct(?array $config)
    {
        if (! $config) {
            return;
        }
        
        $this->apiGatewayManagementApiClient = new ApiGatewayManagementApiClient(array_merge($config['connection'], [
            'version' => '2018-11-29',
            'endpoint' => "https://{$config['api']['id']}.execute-api.{$config['connection']['region']}.amazonaws.com/{$config['api']['stage']}/",
        ]));
    }

    public function sendMessage(string $connectionId, string $data): void
    {
        $this->apiGatewayManagementApiClient->postToConnection([
            'ConnectionId' => $connectionId,
            'Data' => $data,
        ]);
    }
}
