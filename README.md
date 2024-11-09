# laravel-echo-api-gateway

[![CI](https://github.com/georgeboot/laravel-echo-api-gateway/workflows/CI/badge.svg?event=push)](https://github.com/georgeboot/laravel-echo-api-gateway/actions?query=workflow%3ACI)
[![codecov](https://codecov.io/gh/georgeboot/laravel-echo-api-gateway/branch/master/graph/badge.svg?token=UVIA3FBQPP)](https://codecov.io/gh/georgeboot/laravel-echo-api-gateway)

This package enables you to use API Gateway‘s Websockets as a driver for [Laravel Echo](https://github.com/laravel/echo)
, so you don’t have to use services like Pusher or Socket.io.

It works by setting up a websocket API in API Gateway, and configure it to invoke a Lambda function, every time a
message is sent to the websocket. This package includes and autoconfigures a handler to respond to these websocket
messages. We also configure Laravel to use this connection as a broadcast driver.

This package currently only works with either [Bref](https://bref.sh) or [Laravel Vapor](https://vapor.laravel.com),
though the latter one involves some manual set-up.

As soon as version 1.0 will be released, this package will be suitable for production workloads. Until then, it is
advised to only use this package for non-critical / non-production projects.

## Requirements

In order to use this package, your project needs to meet the following criteria:

- PHP 7.4 or 8.x
- Laravel 6, 7 or 8
- Uses either [bref](https://bref.sh) or [Laravel Vapor](https://vapor.laravel.com) to deploy to AWS
- Has a working queue
- Uses Laravel Mix or any other tool to bundle your assets

## Installation

Installation of this package is fairly simply.

First we have to install both the composer and npm package:

```shell
composer require georgeboot/laravel-echo-api-gateway

yarn add laravel-echo-api-gateway
# or
npm install --save-dev laravel-echo-api-gateway
```

### Platform-specific instructions

#### A. When using Bref

Next, when using Bref, we have to add some elements to our `serverless.yml` file. If using Vapor, these resources have
to be created by hand using the AWS CLI or console.

Add a new function that will handle websocket events (messages etc):

```yaml
functions:
    # Add this function
    websocket:
        handler: handlers/websocket.php
        layers:
            - ${bref:layer.php-80}
        events:
            - websocket: $disconnect
            - websocket: $default
```

Add a resource to create and configure our DynamoDB table, where connections will be stored in:

```yaml
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

Add the following `iamRoleStatement` to enable our Lambda function to access the table:

```yaml
provider:
    name: aws

    iamRoleStatements:
        # Add this iamRoleStatement
        - Effect: Allow
          Action: [ dynamodb:Query, dynamodb:GetItem, dynamodb:PutItem, dynamodb:UpdateItem, dynamodb:DeleteItem, dynamodb:BatchWriteItem ]
          Resource:
              - !GetAtt ConnectionsTable.Arn
              - !Join [ '', [ !GetAtt ConnectionsTable.Arn, '/index/*' ] ]
```

Add an environment variable to autogenerate our websocket URL:

```yaml
provider:
    name: aws

    environment:
        # Add these variables
        BROADCAST_DRIVER: laravel-echo-api-gateway
        LARAVEL_ECHO_API_GATEWAY_DYNAMODB_TABLE: !Ref ConnectionsTable
        LARAVEL_ECHO_API_GATEWAY_API_ID: !Ref WebsocketsApi
        LARAVEL_ECHO_API_GATEWAY_API_STAGE: "${self:provider.stage}"
```

Next, create the PHP handler file in `handlers/websocket.php`

```php
<?php

use Georgeboot\LaravelEchoApiGateway\Handler;
use Illuminate\Foundation\Application;

require __DIR__ . '/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

return $app->make(Handler::class);
```

Now, deploy your app by running `serverless deploy` or similar. Write down the websocket url the output gives you.

#### B. When using Vapor

When using Vapor, you will have to create these required resources by hand using the AWS CLI or Console:

##### B1. DynamoDB table for connections

Create a DynamoDB table for the connections. Use `connectionId` (string) as a HASH key, and `channel` (string) as a SORT
key. Set the capacity setting to whatever you like (probably on-demand).

Create 2 indexes:

1. Name: `lookup-by-connection`, key: `connectionId`, no sort key, projected: ALL
2. Name: `lookup-by-channel`, key: `channel`, no sort key, projected: ALL

##### B2. API Gateway

Create a new Websocket API. Enter a name and leave the route selection expression to what it is. Add a `$disconnect`
and `$default`. Set both integrations to `Lambda` and select your CLI lambda from the list. Set the name of the stage to
what you desire and create the API. Once created, write down the ID, as we'll need it later.

##### B3. IAM Permissions

In IAM, go to roles and open `laravel-vapor-role`. Open the inline policy and edit it. On the JSON tab,
add `"execute-api:*"` to the list of actions.

Then, login to [Laravel Vapor](https://vapor.laravel.com/app), go to team settings, AWS Accounts, click on Role next to
the correct account and deselect Receive Updates.

Edit your `.env`:

```dotenv
BROADCAST_DRIVER=laravel-echo-api-gateway
LARAVEL_ECHO_API_GATEWAY_DYNAMODB_TABLE=the-table-name-you-entered-when-creating-it
LARAVEL_ECHO_API_GATEWAY_API_ID=your-websocket-api-id
LARAVEL_ECHO_API_GATEWAY_API_STAGE=your-api-stage-name
```

### Generate front-end code

Add to your javascript file:

```js
import Echo from 'laravel-echo';
import {broadcaster} from 'laravel-echo-api-gateway';

window.Echo = new Echo({
    broadcaster,
    // replace the placeholders
    host: 'wss://{api-ip}.execute-api.{region}.amazonaws.com/{stage}',
});
```

Lastly, you have to generate your assets by running Laravel Mix. After this step, you should be up and running.
