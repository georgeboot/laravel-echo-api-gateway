<?php

namespace Georgeboot\LaravelEchoApiGateway\Jobs;

use Aws\ApiGatewayManagementApi\Exception\ApiGatewayManagementApiException;
use Georgeboot\LaravelEchoApiGateway\ConnectionRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMessageToConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected string $connectionId;
    protected string $data;

    public function __construct(string $connectionId, string $data)
    {
        $this->connectionId = $connectionId;
        $this->data = $data;
    }

    public function handle(ConnectionRepository $connectionRepository)
    {
        try {
            $connectionRepository->sendMessage($this->connectionId, $this->data);
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
