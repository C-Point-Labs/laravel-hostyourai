<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Http;

use CPointLabs\HostYourAI\Exceptions\ConfigurationException;
use CPointLabs\HostYourAI\Exceptions\ExceptionFactory;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Builds the authenticated HTTP client shared by chat, decisions and account
 * calls. It adds no logging, so prompts and keys stay out of your logs.
 */
final class ClientFactory
{
    public readonly string $baseUrl;

    public readonly string $decisionUrl;

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        string $baseUrl,
        ?string $decisionUrl = null,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 5,
        private readonly int $retryTimes = 0,
        private readonly int $retryMaxWait = 5,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->decisionUrl = rtrim($decisionUrl === null || $decisionUrl === '' ? self::deriveDecisionUrl($this->baseUrl) : $decisionUrl, '/');
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(#[SensitiveParameter] array $config): self
    {
        return new self(
            apiKey: Payload::string($config, 'api_key') ?? '',
            baseUrl: Payload::string($config, 'url') ?? Payload::string($config, 'base_url') ?? 'https://hostyourai.com/api/v1',
            decisionUrl: Payload::string($config, 'decision_url'),
            timeout: Payload::int($config, 'timeout') ?? 30,
            connectTimeout: Payload::int($config, 'connect_timeout') ?? 5,
            retryTimes: Payload::int($config, 'retry.times') ?? 0,
            retryMaxWait: Payload::int($config, 'retry.max_wait') ?? 5,
        );
    }

    public function chat(): PendingRequest
    {
        return $this->make($this->baseUrl);
    }

    public function decisions(): PendingRequest
    {
        return $this->make($this->decisionUrl);
    }

    private function make(string $baseUrl): PendingRequest
    {
        if ($this->apiKey === '') {
            throw new ConfigurationException('No HostYourAI API key configured. Set HOSTYOURAI_API_KEY.');
        }

        $client = Http::baseUrl($baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->throw();

        if ($this->retryTimes < 1) {
            return $client;
        }

        return $client->retry(
            $this->retryTimes + 1,
            fn (int $attempt, mixed $e): int => $this->secondsToWait($e) * 1000,
            fn (Throwable $e): bool => $this->shouldRetry($e),
        );
    }

    private function shouldRetry(Throwable $e): bool
    {
        return $e instanceof RequestException
            && in_array($e->response->status(), [429, 503], true)
            && $this->secondsToWait($e) <= $this->retryMaxWait;
    }

    private function secondsToWait(mixed $e): int
    {
        if (! $e instanceof RequestException) {
            return 1;
        }

        return ExceptionFactory::parseRetryAfter($e->response->header('Retry-After')) ?? 1;
    }

    private static function deriveDecisionUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $parts = is_array($parts) ? $parts : [];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'hostyourai.com').$port.'/api/decision/v1';
    }
}
