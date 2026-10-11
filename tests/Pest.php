<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(\Tests\TestCase::class)->in(__DIR__);

/**
 * Build the Lambda event API Gateway sends when a client posts a websocket message.
 */
function websocketMessage(array $body, string $connectionId = 'connection-id-1'): array
{
    return [
        'requestContext' => [
            'routeKey' => 'my-test-route-key',
            'eventType' => 'MESSAGE',
            'connectionId' => $connectionId,
            'domainName' => 'test-domain',
            'apiId' => 'api-id-1',
            'stage' => 'stage-test',
        ],
        'body' => json_encode($body),
    ];
}

function lambdaContext(): \Bref\Context\Context
{
    return new \Bref\Context\Context('request-id-1', 50_000, 'function-arn', 'trace-id-1');
}
