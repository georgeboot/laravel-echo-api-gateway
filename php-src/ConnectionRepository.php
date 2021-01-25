<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Illuminate\Support\Str;

class ConnectionRepository
{
    protected ApiGatewayManagementApiClient $apiGatewayManagementApiClient;

    public function __construct(array $config)
    {
        $this->apiGatewayManagementApiClient = new ApiGatewayManagementApiClient(array_merge($config['connection'], [
            'version' => '2018-11-29',
            'endpoint' => Str::replaceFirst('wss://', 'https://', $config['endpoint']),
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
