<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\DynamoDb\DynamoDbClient;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SubscriptionRepository
{
    protected DynamoDbClient $dynamoDb;
    protected string $table;

    public function __construct(array $config)
    {
        $this->dynamoDb = new DynamoDbClient(array_merge($config['connection'], [
            'version' => '2012-08-10',
            'endpoint' => $config['dynamodb']['endpoint'],
        ]));

        $this->table = $config['dynamodb']['table'];
    }

    public function getConnectionIdsForChannel(string ...$channels): Collection
    {
        $promises = collect($channels)->map(fn ($channel) => $this->dynamoDb->queryAsync([
            'TableName' => $this->table,
            'IndexName' => 'lookup-by-channel',
            'KeyConditionExpression' => 'channel = :channel',
            'ExpressionAttributeValues' => [
                ':channel' => ['S' => $channel],
            ],
        ]))->toArray();

        $responses = Utils::all($promises)->wait();

        return collect($responses)
            ->flatmap(fn (\Aws\Result $result): array => $result['Items'])
            ->map(fn (array $item): string => $item['connectionId']['S'])
            ->unique();
    }

    public function clearConnection(string $connectionId): void
    {
        $response = $this->dynamoDb->query([
            'TableName' => $this->table,
            'IndexName' => 'lookup-by-connection',
            'KeyConditionExpression' => 'connectionId = :connectionId',
            'ExpressionAttributeValues' => [
                ':connectionId' => ['S' => $connectionId],
            ],
        ]);

        if (! empty($response['Items'])) {
            $this->dynamoDb->batchWriteItem([
                'RequestItems' => [
                    $this->table => collect($response['Items'])->map(fn ($item) => [
                        'DeleteRequest' => [
                            'Key' => Arr::only($item, ['connectionId', 'channel']),
                        ],
                    ])->toArray(),
                ],
            ]);
        }
    }

    public function subscribeToChannel(string $connectionId, string $channel): void
    {
        $this->dynamoDb->putItem([
            'TableName' => $this->table,
            'Item' => [
                'connectionId' => ['S' => $connectionId],
                'channel' => ['S' => $channel],
            ],
        ]);
    }

    public function unsubscribeFromChannel(string $connectionId, string $channel): void
    {
        $this->dynamoDb->deleteItem([
            'TableName' => $this->table,
            'Key' => [
                'connectionId' => ['S' => $connectionId],
                'channel' => ['S' => $channel],
            ],
        ]);
    }
}
