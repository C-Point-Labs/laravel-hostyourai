<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Exceptions\BudgetExceededException;
use CPointLabs\HostYourAI\Exceptions\ConnectionException;
use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;
use CPointLabs\HostYourAI\Prism\VisibleText;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Events\Broadcasting\TextDeltaBroadcast;
use Prism\Prism\Events\Broadcasting\ThinkingBroadcast;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

const STREAM_URL = 'https://api.test/api/v1/chat/completions';

function fakeStream(array $chunks, bool $done = true): void
{
    Http::fake([STREAM_URL => Http::response(sse($chunks, $done), 200, ['Content-Type' => 'text/event-stream'])]);
}

function reasoningChunks(): array
{
    return [
        chunk(['role' => 'assistant', 'content' => '']),
        chunk(['reasoning_content' => 'private ']),
        chunk(['reasoning_content' => 'reasoning']),
        chunk(['content' => "\n\nHel"]),
        chunk(['content' => 'lo']),
        chunk(['content' => ''], 'stop', ['prompt_tokens' => 10, 'completion_tokens' => 5, 'completion_tokens_details' => ['reasoning_tokens' => 2]]),
    ];
}

function hostyouraiStream(): \Prism\Prism\Text\PendingRequest
{
    return Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Say hello')->withMaxTokens(50);
}

it('streams text deltas with usage', function (): void {
    fakeStream([
        chunk(['content' => 'Hel']),
        chunk(['content' => 'lo']),
        chunk(['content' => ''], 'stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
    ]);

    $events = collect(iterator_to_array(hostyouraiStream()->asStream(), false));

    expect($events->first())->toBeInstanceOf(StreamStartEvent::class)
        ->and($events->first()->model)->toBe('test/model')
        ->and($events->first()->provider)->toBe('hostyourai')
        ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe('Hello')
        ->and($events->last())->toBeInstanceOf(StreamEndEvent::class)
        ->and($events->last()->finishReason)->toBe(FinishReason::Stop)
        ->and($events->last()->usage->completionTokens)->toBe(5);

    Http::assertSent(fn (Request $request): bool => $request['stream'] === true
        && $request['stream_options'] === ['include_usage' => true]
        && $request['max_tokens'] === 50);
});

it('leaves out reasoning by default', function (): void {
    fakeStream(reasoningChunks());

    $events = collect(iterator_to_array(hostyouraiStream()->asStream(), false));

    expect($events->whereInstanceOf(ThinkingStartEvent::class))->toBeEmpty()
        ->and($events->whereInstanceOf(ThinkingEvent::class))->toBeEmpty()
        ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe('Hello')
        ->and($events->last()->usage->thoughtTokens)->toBe(2);
});

it('emits reasoning as thinking events when asked to', function (): void {
    fakeStream(reasoningChunks());

    $events = collect(iterator_to_array(hostyouraiStream()->withProviderOptions(['reasoning' => true])->asStream(), false));

    expect($events->whereInstanceOf(ThinkingStartEvent::class))->toHaveCount(1)
        ->and($events->whereInstanceOf(ThinkingCompleteEvent::class))->toHaveCount(1)
        ->and($events->whereInstanceOf(ThinkingEvent::class)->map->delta->implode(''))->toBe('private reasoning')
        ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe('Hello');
});

it('gives only the answer through VisibleText', function (): void {
    fakeStream(reasoningChunks());

    expect(implode('', iterator_to_array(VisibleText::from(hostyouraiStream()->asStream()), false)))->toBe('Hello');
});

it('sends no reasoning through Prism\'s stream responses', function (string $method): void {
    fakeStream(reasoningChunks());

    // A callback, because the responses flush the output buffer after each event.
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    hostyouraiStream()->{$method}()->sendContent();

    ob_end_clean();

    expect($output)->toContain('Hel')
        ->and($output)->not->toContain('private')
        ->and($output)->not->toContain('reasoning"');
})->with(['asEventStreamResponse', 'asDataStreamResponse']);

it('runs tools while streaming', function (): void {
    Http::fake([STREAM_URL => Http::sequence()
        ->push(sse([
            chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'add', 'arguments' => '{"a":2,']]]]),
            chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"b":3}']]]], 'tool_calls'),
        ]), 200)
        ->push(sse([
            chunk(['content' => '5']),
            chunk(['content' => ''], 'stop'),
        ]), 200)]);

    $add = Tool::as('add')
        ->for('Add numbers')
        ->withNumberParameter('a', 'First')
        ->withNumberParameter('b', 'Second')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    $events = collect(iterator_to_array(
        Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('2+3?')->withTools([$add])->withMaxSteps(3)->asStream(),
        false,
    ));

    expect($events->whereInstanceOf(ToolCallEvent::class)->first()->toolCall->arguments())->toBe(['a' => 2, 'b' => 3])
        ->and($events->whereInstanceOf(ToolResultEvent::class)->first()->toolResult->result)->toBe('5')
        ->and($events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode(''))->toBe('5')
        ->and($events->last())->toBeInstanceOf(StreamEndEvent::class);

    $requests = Http::recorded();

    expect($requests[1][0]['messages'][2]['role'])->toBe('tool');
});

it('maps HTTP errors to typed exceptions', function (): void {
    Http::fake([STREAM_URL => Http::response(['error' => ['message' => 'Budget', 'code' => 'team_budget_exceeded']], 402)]);

    iterator_to_array(hostyouraiStream()->asStream(), false);
})->throws(BudgetExceededException::class);

it('throws on an error sent mid-stream', function (): void {
    fakeStream([
        chunk(['content' => 'Hel']),
        ['error' => ['message' => 'Model went away', 'status' => 503]],
    ], done: false);

    iterator_to_array(hostyouraiStream()->asStream(), false);
})->throws(ModelUnavailableException::class);

it('throws when the stream ends before completing', function (): void {
    fakeStream([chunk(['content' => 'Hel'])], done: false);

    iterator_to_array(hostyouraiStream()->asStream(), false);
})->throws(ConnectionException::class, 'before the answer was complete');

it('accepts a stream that ends with DONE but no finish reason', function (): void {
    fakeStream([chunk(['content' => 'Hi'])]);

    expect(iterator_to_array(hostyouraiStream()->asStream(), false))->not->toBeEmpty();
});

it('broadcasts the answer without reasoning', function (): void {
    Event::fake();
    fakeStream(reasoningChunks());

    hostyouraiStream()->asBroadcast(new Channel('answers'));

    Event::assertDispatched(TextDeltaBroadcast::class);
    Event::assertNotDispatched(ThinkingBroadcast::class);
});

it('refuses provider options it would otherwise ignore, naming them', function (array $options, string $message): void {
    Http::fake();

    expect(fn () => iterator_to_array(hostyouraiStream()->withProviderOptions($options)->asStream(), false))
        ->toThrow(InvalidRequestException::class, $message);

    Http::assertNothingSent();
})->with([
    'undocumented field' => [['knowledge_base_id' => 12], '"knowledge_base_id"'],
    'structured only' => [['schema' => ['strict' => false]], '"schema.strict"'],
    'not a boolean' => [['reasoning' => 'yes'], 'must be true or false'],
]);

it('identifies the package on a stream', function (): void {
    fakeStream([chunk(['content' => 'Hi'], 'stop')]);

    iterator_to_array(hostyouraiStream()->asStream(), false);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('User-Agent')[0] ?? '', 'c-point-labs/laravel-hostyourai/'));
});

it('sends the hyai options in the body', function (): void {
    fakeStream([chunk(['content' => 'Hi'], 'stop')]);

    iterator_to_array(hostyouraiStream()->withProviderOptions(['hyai' => ['task' => 'chat', 'policy' => 'speed'], 'reasoning' => true])->asStream(), false);

    Http::assertSent(fn (Request $request): bool => $request['hyai'] === ['task' => 'chat', 'policy' => 'speed']);
});

it('refuses a hyai value that the API does not list', function (): void {
    Http::fake();

    expect(fn () => iterator_to_array(hostyouraiStream()->withProviderOptions(['hyai' => ['policy' => 'fast']])->asStream(), false))
        ->toThrow(InvalidRequestException::class, '"hyai.policy"');

    Http::assertNothingSent();
});
