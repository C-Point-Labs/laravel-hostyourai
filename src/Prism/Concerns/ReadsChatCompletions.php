<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Concerns;

use CPointLabs\HostYourAI\Exceptions\ExceptionFactory;
use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Http\Client\Response;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

/**
 * Reads the parts every chat completion shares. Reasoning is left out on
 * purpose: only the streaming handler exposes it, and only when asked to.
 *
 * @internal
 */
trait ReadsChatCompletions
{
    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new UnexpectedResponseException('HostYourAI returned a response that is not JSON.');
        }

        $data = Payload::stringKeys($data);

        if (is_array($data['error'] ?? null)) {
            throw ExceptionFactory::fromErrorPayload(Payload::map($data, 'error'));
        }

        if (! is_array(data_get($data, 'choices.0'))) {
            throw new UnexpectedResponseException('HostYourAI returned a completion without choices.');
        }

        return $data;
    }

    /**
     * Some models start the answer with blank lines, or send no content at all
     * when reasoning used up max_tokens.
     *
     * @param array<string, mixed> $data
     */
    protected function content(array $data): string
    {
        $content = data_get($data, 'choices.0.message.content');

        return is_string($content) ? ltrim($content) : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ToolCall>
     */
    protected function toolCalls(array $data): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: Payload::string($toolCall, 'id') ?? '',
            name: Payload::string($toolCall, 'function.name') ?? '',
            arguments: $this->toolArguments(data_get($toolCall, 'function.arguments')),
        ), Payload::maps($data, 'choices.0.message.tool_calls'));
    }

    /**
     * Arguments usually arrive as a JSON string, and sometimes as a decoded object.
     */
    protected function toolArguments(mixed $arguments): string
    {
        if (is_array($arguments)) {
            return json_encode($arguments, JSON_THROW_ON_ERROR);
        }

        return is_string($arguments) && $arguments !== '' ? $arguments : '{}';
    }

    protected function finishReason(?string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * @param array<string, mixed> $usage
     */
    protected function usage(array $usage): Usage
    {
        $cached = Payload::int($usage, 'prompt_tokens_details.cached_tokens');

        return new Usage(
            promptTokens: max(0, (Payload::int($usage, 'prompt_tokens') ?? 0) - ($cached ?? 0)),
            completionTokens: Payload::int($usage, 'completion_tokens') ?? 0,
            cacheReadInputTokens: $cached,
            thoughtTokens: Payload::int($usage, 'completion_tokens_details.reasoning_tokens'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function meta(array $data, Response $response): Meta
    {
        return new Meta(
            id: Payload::string($data, 'id') ?? '',
            model: Payload::string($data, 'model') ?? '',
            rateLimits: $this->rateLimits($response),
        );
    }

    /**
     * @return list<ProviderRateLimit>
     */
    private function rateLimits(Response $response): array
    {
        $limits = [];

        foreach (['requests', 'tokens'] as $name) {
            $limit = $response->header("x-ratelimit-limit-{$name}");
            $remaining = $response->header("x-ratelimit-remaining-{$name}");

            if ($limit === '' && $remaining === '') {
                continue;
            }

            $limits[] = new ProviderRateLimit(
                name: $name,
                limit: $limit === '' ? null : (int) $limit,
                remaining: $remaining === '' ? null : (int) $remaining,
            );
        }

        return $limits;
    }
}
