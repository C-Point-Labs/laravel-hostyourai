<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism;

use CPointLabs\HostYourAI\Exceptions\ExceptionFactory;
use CPointLabs\HostYourAI\Http\ClientFactory;
use CPointLabs\HostYourAI\Prism\Handlers\Stream;
use CPointLabs\HostYourAI\Prism\Handlers\Structured;
use CPointLabs\HostYourAI\Prism\Handlers\Text;
use CPointLabs\HostYourAI\Support\ProviderOptions;
use Generator;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Override;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class HostYourAIProvider extends Provider
{
    public function __construct(
        private readonly ClientFactory $clients,
        private readonly int $streamTimeout = 120,
    ) {}

    #[Override]
    public function text(TextRequest $request): TextResponse
    {
        ProviderOptions::assertSupported($request->providerOptions(), ProviderOptions::TEXT);

        try {
            return (new Text($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
        } catch (HttpConnectionException $e) {
            throw ExceptionFactory::fromConnectionError($e);
        }
    }

    #[Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        ProviderOptions::assertSupported($request->providerOptions(), ProviderOptions::STRUCTURED);

        try {
            return (new Structured($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
        } catch (HttpConnectionException $e) {
            throw ExceptionFactory::fromConnectionError($e);
        }
    }

    #[Override]
    public function stream(TextRequest $request): Generator
    {
        ProviderOptions::assertSupported($request->providerOptions(), ProviderOptions::STREAM);

        try {
            yield from (new Stream(
                $this->client($request->clientOptions(), $request->clientRetry()),
                $this->streamTimeout,
            ))->handle($request);
        } catch (HttpConnectionException $e) {
            throw ExceptionFactory::fromConnectionError($e);
        }
    }

    #[Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        throw ExceptionFactory::fromRequestException($e);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<mixed> $retry
     */
    private function client(array $options, array $retry): PendingRequest
    {
        $client = $this->clients->chat()->withOptions($options);

        // Prism's default is [0]: no opinion, so the configured retries stay in place.
        if ($retry !== [] && $retry !== [0]) {
            call_user_func_array($client->retry(...), $retry);
        }

        return $client;
    }
}
