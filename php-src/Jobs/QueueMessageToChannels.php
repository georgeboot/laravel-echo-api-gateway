<?php

namespace Georgeboot\LaravelEchoApiGateway\Jobs;

use Aws\DynamoDb\DynamoDbClient;
use GuzzleHttp\Promise\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class QueueMessageToChannels implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected DynamoDbClient $dynamoDb;
    protected string $table;
    protected array $channels;
    protected string $data;
    protected ?string $skipConnectionId;

    public function __construct(array $channels, string $data, string $skipConnectionId = null)
    {
        $config = config('laravel-echo-api-gateway');

        $this->dynamoDb = $this->getDynamoDbClient($config);
        $this->table = $config['table'];

        $this->channels = $channels;
        $this->data = $data;
        $this->skipConnectionId = $skipConnectionId;
    }

    protected function getDynamoDbClient(array $config): DynamoDbClient
    {
        return new DynamoDbClient(array_merge($config['connection'], [
            'version' => '2012-08-10',
        ]));
    }


    public function handle()
    {
        $this->getConnectionIdsForChannels($this->channels)
            ->reject(fn($connectionId) => $connectionId === $this->skipConnectionId)
            ->each(fn($connectionId) => dispatch(new SendMessageToConnection($connectionId, $this->data)));
    }

    protected function getConnectionIdsForChannels(string ...$channels): Collection
    {
        $promises = collect($channels)->map(fn($channel) => $this->dynamoDb->queryAsync([
            'TableName' => $this->table,
            'IndexName' => 'lookup-by-channel',
            'KeyConditionExpression' => 'channel = :channel',
            'ExpressionAttributeValues' => [
                ':channel' => ['S' => $channel],
            ],
        ]))->toArray();

        $responses = Utils::all($promises)->wait();

        return collect($responses)
            ->flatmap(fn($result) => $result['Items'])
            ->map(fn($item) => $item['connectionId']['S'])
            ->unique();
    }
}
