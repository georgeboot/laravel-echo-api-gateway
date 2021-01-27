# laravel-echo-api-gateway

## !! Work in progress !!

Note that this package is a work in progress. As soon as version 0.1 will be tagged, it will be usable for testing
purposes. As soon as version 1.0 gets released, it should be usable for production purposes.

## About

This package enables you to use API Gateway‘s Websockets as a driver for [Laravel Echo](https://github.com/laravel/echo)
, so you don’t have to use services like Pusher or Socket.io.

It works by setting up a websocket API in API Gateway, and configure it to invoke a Lambda function, every time a
message is sent to the websocket. This package includes and autoconfigures a handler to respond to these websocket
messages. We also configure Laravel to use this connection as a broadcast driver.

This package currently only works if you deploy your app using [Bref](https://bref.sh), but it could theoretically also
be deployed alongside a [Laravel Vapor](https://vapor.laravel.com) project.

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
npn install --save laravel-echo-api-gateway
```

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
          Action: [ dynamodb:GetItem, dynamodb:PutItem, dynamodb:UpdateItem, dynamodb:DeleteItem, dynamodb:Query ]
          Resource: !GetAtt ConnectionsTable.Arn
```

Add an environment variable to autogenerate our websocket URL:

```yaml
provider:
    name: aws

    environment:
        # Add this line
        BROADCAST_API_GATEWAY_URL: !Join [ '', [ 'wss://', !Ref "WebsocketsApi", '.execute-api.', "${self:provider.region}", '.', !Ref "AWS::URLSuffix", '/', "${self:provider.stage}" ] ]
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

Edit your `.env`:

```dotenv
BROADCAST_DRIVER=laravel-echo-api-gateway
MIX_BROADCAST_API_GATEWAY_URL="${BROADCAST_API_GATEWAY_URL}"
```

Add to your javascript file:

```js
import Echo from 'laravel-echo';
import {broadcaster} from 'laravel-echo-api-gateway';

const echo = new Echo({
    broadcaster,
    host: process.env.MIX_BROADCAST_API_GATEWAY_URL,
});
```

Lastly, you have to generate your assets by running Laravel Mix. After this step, you should be up and running.
