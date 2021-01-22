# laravel-echo-api-gateway

## Installation

Install the composer package:

```
composer require georgeboot/laravel-echo-api-gateway
```

And also the npm package:

```
yarn add georgeboot/laravel-echo-api-gateway
```

Edit your `serverless.yml` file:

```yaml
service: turbo-playground
#org: georgeboot
#app: bref-test

provider:
    name: aws
    region: eu-central-1
    runtime: provided.al2
    stage: production

    environment:
        # Add this line
        BROADCAST_API_GATEWAY_URL: !Join [ '', [ 'wss://', !Ref "WebsocketsApi", '.execute-api.', "${self:provider.region}", '.', !Ref "AWS::URLSuffix", '/', "${self:provider.stage}" ] ]

    iamRoleStatements:
        # Add this role statement
        - Effect: Allow
          Action: [ dynamodb:GetItem, dynamodb:PutItem, dynamodb:UpdateItem, dynamodb:DeleteItem, dynamodb:Query ]
          Resource: !GetAtt ConnectionsTable.Arn

functions:
    # Add this function
    websocket:
        handler: handlers/websocket.php
        layers:
            - ${bref:layer.php-80}
        events:
            - websocket: $connect
            - websocket: $disconnect
            - websocket: $default

resources:
    Resources:
        # Add this resource
        ConnectionsTable:
            Type: AWS::DynamoDB::Table
            Properties:
                TableName: connections
                AttributeDefinitions:
                    - AttributeName: connectionId
                      AttributeType: S
                    - AttributeName: channel
                      AttributeType: S
                KeySchema:
                    - AttributeName: connectionId
                      KeyType: HASH
                    - AttributeName: channel
                      KeyType: RANGE
                GlobalSecondaryIndexes:
                    - IndexName: lookup-by-channel
                      KeySchema:
                          - AttributeName: channel
                            KeyType: HASH
                      Projection:
                          ProjectionType: ALL
                    - IndexName: lookup-by-connection
                      KeySchema:
                          - AttributeName: connectionId
                            KeyType: HASH
                      Projection:
                          ProjectionType: ALL
                BillingMode: PAY_PER_REQUEST
```

Edit your `.env`:

```dotenv
MIX_BROADCAST_API_GATEWAY_URL=wss://1234567890.execute-api.your-region.amazonaws.com/stage-name
MIX_BROADCAST_API_GATEWAY_URL="${BROADCAST_API_GATEWAY_URL}"
```

Add to your javascript file:

```js
import Echo from 'laravel-echo';
import LaravelEchoApiGatewayConnector from 'laravel-echo-api-gateway';

const echo = new Echo({
    broadcaster: options => new LaravelEchoApiGatewayConnector(options),
    host: process.env.MIX_BROADCAST_API_GATEWAY_URL,
});
```

In `handlers/websocket.php`:

```php
<?php

use Georgeboot\LaravelEchoApiGateway\LaravelEchoApiGatewayHandler;
use Illuminate\Foundation\Application;

require __DIR__ . '/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

return $app->make(LaravelEchoApiGatewayHandler::class);
```
