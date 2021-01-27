<?php

namespace Georgeboot\LaravelEchoApiGateway\Commands;

use Bref\Context\Context;
use Georgeboot\LaravelEchoApiGateway\Handler;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class VaporHandle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vapor:handle
                            {message : The Base64 encoded message payload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Handle custom lambda events in Vapor';

    protected Handler $websocketHandler;

    public function __construct(Handler $websocketHandler)
    {
        parent::__construct();

        $this->websocketHandler = $websocketHandler;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->laravel->isDownForMaintenance()) {
            return 0;
        }

        // fake a context
        $context = new Context($_ENV['AWS_REQUEST_ID'] ?? 'request-1', 0, $_ENV['AWS_LAMBDA_FUNCTION_NAME'] ?? 'arn-1', $_ENV['_X_AMZN_TRACE_ID'] ?? '');

        if (Arr::get($this->message(), 'requestContext.connectionId')) {
            $this->handleWebsocketEvent($this->message(), $context);
        }

        return 0;
    }

    protected function handleWebsocketEvent(array $event, Context $context): void
    {
        $this->websocketHandler->handle($event, $context);
    }

    /**
     * Get the decoded message payload.
     *
     * @return array
     */
    protected function message()
    {
        /** @var string $message */
        $message = $this->argument('message');

        return tap(json_decode(base64_decode($message), true), function ($message) {
            if ($message === false) {
                throw new InvalidArgumentException('Unable to unserialize message.');
            }
        });
    }
}
