# laravel-echo-api-gateway

```
composer require georgeboot/laravel-echo-api-gateway
```

```
yarn add georgeboot/laravel-echo-api-gateway
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
