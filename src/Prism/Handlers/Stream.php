<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Handlers;

use CPointLabs\HostYourAI\Exceptions\ConnectionException;
use CPointLabs\HostYourAI\Exceptions\ExceptionFactory;
use CPointLabs\HostYourAI\HostYourAI;
use CPointLabs\HostYourAI\Prism\Concerns\ReadsChatCompletions;
use CPointLabs\HostYourAI\Prism\Maps\MessageMap;
use CPointLabs\HostYourAI\Prism\Maps\ToolChoiceMap;
use CPointLabs\HostYourAI\Prism\Maps\ToolMap;
use CPointLabs\HostYourAI\Prism\Streaming\PrivateThinkingEvent;
use CPointLabs\HostYourAI\Support\Payload;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use JsonException;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;

final class Stream
{
    use CallsTools;
    use ReadsChatCompletions;

    private StreamState $state;

    private float $deadline;

    public function __construct(
        private readonly PendingRequest $client,
        private readonly int $streamTimeout = 120,
    ) {
        $this->state = new StreamState;
        $this->deadline = microtime(true) + $this->streamTimeout;
    }

    /**
     * @return Generator<StreamEvent>
     */
    public function handle(Request $request): Generator
    {
        $this->state->reset();

        yield from $this->processStep($request, 0);
    }

    /**
     * @return Generator<StreamEvent>
     */
    private function processStep(Request $request, int $depth): Generator
    {
        $response = $this->send($request);
        $body = $response->toPsrResponse()->getBody();

        $this->state->withMessageId(EventID::generate('msg'));

        $emitReasoning = $request->providerOptions('reasoning') !== false;
        $text = '';
        $toolCalls = [];
        $rawUsage = null;
        $finishReason = null;
        $complete = false;

        while (! $body->eof()) {
            if (microtime(true) >= $this->deadline) {
                throw new ConnectionException('The HostYourAI stream exceeded its time limit.');
            }

            $data = $this->nextChunk($body, $complete);

            if ($data === null) {
                continue;
            }

            if (is_array($data['error'] ?? null)) {
                throw ExceptionFactory::fromErrorPayload(Payload::map($data, 'error'));
            }

            $chunkUsage = Payload::map($data, 'usage');
            $rawUsage = $chunkUsage === [] ? $rawUsage : $chunkUsage;

            yield from $this->startEvents($data, $request);

            $delta = Payload::map($data, 'choices.0.delta');

            $reasoning = $delta['reasoning_content'] ?? null;

            if ($emitReasoning && is_string($reasoning) && $reasoning !== '') {
                yield from $this->reasoningEvents($reasoning);
            }

            $content = $delta['content'] ?? null;

            // Some models start the answer with blank lines after their reasoning.
            if (is_string($content) && $text === '') {
                $content = ltrim($content);
            }

            if (is_string($content) && $content !== '') {
                yield from $this->completeReasoning();

                if ($this->state->shouldEmitTextStart()) {
                    $this->state->markTextStarted();

                    yield new TextStartEvent(EventID::generate(), time(), $this->state->messageId());
                }

                $text .= $content;

                yield new TextDeltaEvent(EventID::generate(), time(), $content, $this->state->messageId());
            }

            $toolCalls = $this->collectToolCalls(Payload::maps($delta, 'tool_calls'), $toolCalls);

            $rawFinishReason = Payload::string($data, 'choices.0.finish_reason');

            if ($rawFinishReason !== null) {
                $finishReason = $this->finishReason($rawFinishReason);
                $complete = true;
            }
        }

        if (! $complete) {
            throw new ConnectionException('The HostYourAI stream ended before the answer was complete.');
        }

        yield from $this->completeReasoning();
        yield from $this->completeText();

        $usage = $rawUsage === null ? null : $this->usage($rawUsage);

        if ($usage instanceof Usage) {
            $this->state->addUsage($usage);
        }

        if ($toolCalls !== []) {
            yield from $this->runTools($request, $text, $toolCalls, $depth, $usage);

            return;
        }

        $this->state->withFinishReason($finishReason ?? FinishReason::Stop)->markStepFinished();

        yield new StepFinishEvent(id: EventID::generate(), timestamp: time(), finishReason: $this->state->finishReason(), usage: $usage);

        yield $this->streamEnd();
    }

    /**
     * @param array<string, mixed> $data
     * @return Generator<StreamEvent>
     */
    private function startEvents(array $data, Request $request): Generator
    {
        if ($this->state->shouldEmitStreamStart()) {
            $this->state->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: Payload::string($data, 'model') ?? $request->model(),
                provider: HostYourAI::PROVIDER,
            );
        }

        if ($this->state->shouldEmitStepStart()) {
            $this->state->markStepStarted();

            yield new StepStartEvent(EventID::generate(), time());
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    private function reasoningEvents(string $reasoning): Generator
    {
        if ($this->state->shouldEmitThinkingStart()) {
            $this->state->withReasoningId(EventID::generate('reasoning'))->markThinkingStarted();

            yield new ThinkingStartEvent(EventID::generate(), time(), $this->state->reasoningId());
        }

        yield new PrivateThinkingEvent(EventID::generate(), time(), $reasoning, $this->state->reasoningId());
    }

    /**
     * @return Generator<StreamEvent>
     */
    private function completeReasoning(): Generator
    {
        if ($this->state->hasThinkingStarted()) {
            $this->state->markThinkingCompleted();

            yield new ThinkingCompleteEvent(EventID::generate(), time(), $this->state->reasoningId());
        }
    }

    /**
     * @return Generator<StreamEvent>
     */
    private function completeText(): Generator
    {
        if ($this->state->hasTextStarted()) {
            $this->state->markTextCompleted();

            yield new TextCompleteEvent(EventID::generate(), time(), $this->state->messageId());
        }
    }

    /**
     * @param array<int, array{id: string, name: string, arguments: string}> $toolCalls
     * @return Generator<StreamEvent>
     */
    private function runTools(Request $request, string $text, array $toolCalls, int $depth, ?Usage $usage): Generator
    {
        $mapped = array_values(array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: $toolCall['id'],
            name: $toolCall['name'],
            arguments: $this->toolArguments($toolCall['arguments']),
        ), $toolCalls));

        foreach ($mapped as $toolCall) {
            yield new ToolCallEvent(EventID::generate(), time(), $toolCall, $this->state->messageId());
        }

        $toolResults = [];

        yield from $this->callToolsAndYieldEvents($request->tools(), $mapped, $this->state->messageId(), $toolResults);

        $this->state->withFinishReason(FinishReason::ToolCalls)->markStepFinished();

        yield new StepFinishEvent(id: EventID::generate(), timestamp: time(), finishReason: FinishReason::ToolCalls, usage: $usage);

        $request->addMessage(new AssistantMessage($text, $mapped));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $depth++;

        if ($depth >= $request->maxSteps()) {
            yield $this->streamEnd();

            return;
        }

        $this->state->resetTextState();

        yield from $this->processStep($request, $depth);
    }

    private function streamEnd(): StreamEndEvent
    {
        // Reasoning stays out of additionalContent, so it never reaches serialized output.
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage() ?? new Usage(0, 0),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $deltas
     * @param array<int, array{id: string, name: string, arguments: string}> $toolCalls
     * @return array<int, array{id: string, name: string, arguments: string}>
     */
    private function collectToolCalls(array $deltas, array $toolCalls): array
    {
        foreach ($deltas as $delta) {
            $index = Payload::int($delta, 'index') ?? 0;

            $toolCalls[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

            $id = Payload::string($delta, 'id');
            $name = Payload::string($delta, 'function.name');

            $toolCalls[$index]['id'] = $id === null || $id === '' ? $toolCalls[$index]['id'] : $id;
            $toolCalls[$index]['name'] = $name === null || $name === '' ? $toolCalls[$index]['name'] : $name;
            $toolCalls[$index]['arguments'] .= Payload::string($delta, 'function.arguments') ?? '';
        }

        return $toolCalls;
    }

    /**
     * Reads one SSE line and returns its JSON, or null for anything else.
     *
     * @return array<string, mixed>|null
     */
    private function nextChunk(StreamInterface $body, bool &$complete): ?array
    {
        $line = trim($this->readLine($body));

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $payload = trim(substr($line, 5));

        if ($payload === '[DONE]') {
            $complete = true;

            return null;
        }

        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PrismStreamDecodeException('HostYourAI', $e);
        }

        return is_array($data) ? Payload::stringKeys($data) : null;
    }

    private function readLine(StreamInterface $body): string
    {
        $buffer = '';

        while (! $body->eof()) {
            $byte = $body->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }

    private function send(Request $request): Response
    {
        return $this->client
            ->withOptions(['stream' => true])
            ->post('chat/completions', Payload::stringKeys([
                'stream' => true,
                'stream_options' => ['include_usage' => true],
                'model' => $request->model(),
                'messages' => MessageMap::map($request->messages(), $request->systemPrompts()),
                ...Arr::whereNotNull([
                    'max_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => $request->tools() === [] ? null : ToolMap::map($request->tools()),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                ]),
            ]));
    }
}
