<?php

namespace Georgeboot\LaravelEchoApiGateway\Jobs;

use Georgeboot\LaravelEchoApiGateway\SubscriptionRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class QueueMessageToChannels implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected array $channels;
    protected string $data;
    protected ?string $skipConnectionId;

    public function __construct(array $channels, string $data, string $skipConnectionId = null)
    {
        $this->channels = $channels;
        $this->data = $data;
        $this->skipConnectionId = $skipConnectionId;
    }

    public function handle(SubscriptionRepository $connectionRepository): void
    {
        $connectionRepository->getConnectionIdsForChannels(...$this->channels)
            ->reject(fn($connectionId) => $connectionId === $this->skipConnectionId)
            ->each(function (string $connectionId): void {
                dispatch(new SendMessageToConnection($connectionId, $this->data));
            });
    }
}
