<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Tests\TestCase;
use Illuminate\Support\Facades\Http;

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Unit', 'Feature');

pest()->extend(TestCase::class)->in('Live');

/**
 * Builds a server-sent events body from chat completion chunks.
 *
 * @param array<int, array<string, mixed>> $chunks
 */
function sse(array $chunks, bool $done = true): string
{
    $body = '';

    foreach ($chunks as $chunk) {
        $body .= 'data: '.json_encode($chunk)."\n\n";
    }

    return $done ? $body."data: [DONE]\n\n" : $body;
}

/**
 * @param array<string, mixed> $delta
 * @return array<string, mixed>
 */
function chunk(array $delta, ?string $finishReason = null, ?array $usage = null): array
{
    return array_filter([
        'id' => 'chatcmpl-1',
        'model' => 'test/model',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        'usage' => $usage,
    ], fn ($value) => $value !== null);
}

/**
 * @param array<string, mixed> $message
 * @return array<string, mixed>
 */
function completion(array $message, string $finishReason = 'stop'): array
{
    return [
        'id' => 'chatcmpl-1',
        'model' => 'test/model',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', ...$message], 'finish_reason' => $finishReason]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];
}
