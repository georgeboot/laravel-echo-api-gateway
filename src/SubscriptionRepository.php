<?php

namespace Georgeboot\LaravelEchoApiGateway;

use Aws\DynamoDb\DynamoDbClient;
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

    /**
     * @return Collection<int, string>
     */
    public function getConnectionIdsForChannel(string ...$channels): Collection
    {
        $connectionIds = [];

        foreach ($channels as $channel) {
            foreach ($this->queryItems('lookup-by-channel', 'channel', $channel) as $item) {
                $connectionIds[] = $item['connectionId']['S'];
            }
        }

        return collect($connectionIds)->unique()->values();
    }

    public function clearConnection(string $connectionId): void
    {
        $keys = array_map(
            fn (array $item): array => Arr::only($item, ['connectionId', 'channel']),
            $this->queryItems('lookup-by-connection', 'connectionId', $connectionId),
        );

        // BatchWriteItem accepts at most 25 requests per call.
        foreach (array_chunk($keys, 25) as $chunk) {
            $this->dynamoDb->batchWriteItem([
                'RequestItems' => [
                    $this->table => array_map(fn (array $key): array => [
                        'DeleteRequest' => [
                            'Key' => $key,
                        ],
                    ], $chunk),
                ],
            ]);
        }
    }

    /**
     * Query an index and return the items of every result page.
     *
     * @return array<int, array<string, array<string, string>>>
     */
    protected function queryItems(string $index, string $attribute, string $value): array
    {
        $paginator = $this->dynamoDb->getPaginator('Query', [
            'TableName' => $this->table,
            'IndexName' => $index,
            'KeyConditionExpression' => "{$attribute} = :value",
            'ExpressionAttributeValues' => [
                ':value' => ['S' => $value],
            ],
        ]);

        $items = [];

        foreach ($paginator as $page) {
            foreach ($page['Items'] ?? [] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function isSubscribed(string $connectionId, string $channel): bool
    {
        $response = $this->dynamoDb->getItem([
            'TableName' => $this->table,
            'Key' => [
                'connectionId' => ['S' => $connectionId],
                'channel' => ['S' => $channel],
            ],
        ]);

        return ! empty($response['Item']);
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
