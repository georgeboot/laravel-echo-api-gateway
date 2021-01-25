<?php

namespace Georgeboot\LaravelEchoApiGateway\Jobs;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient;
use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SendMessageToConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected ApiGatewayManagementApiClient $apiGatewayManagementApiClient;
    protected string $connectionId;
    protected string $data;

    public function __construct(string $connectionId, string $data)
    {
        $config = config('laravel-echo-api-gateway');

        $this->apiGatewayManagementApiClient = new ApiGatewayManagementApiClient(array_merge($config['connection'], [
            'version' => '2018-11-29',
            'endpoint' => Str::replaceFirst('wss://', 'https://', $config['endpoint']),
        ]));

        $this->connectionId = $connectionId;
        $this->data = $data;
    }

    public function handle()
    {
        try {
            $this->apiGatewayManagementApiClient->postToConnection([
                'ConnectionId' => $this->connectionId,
                'Data' => $this->data,
            ]);
        } catch (ApiGatewayManagementApiException $exception) {
            // $exception->getErrorCode() is one of:
            // GoneException
            // LimitExceededException
            // PayloadTooLargeException
            // ForbiddenException

            // otherwise: call $exception->getPrevious() which is a guzzle exception
        }
    }
}
