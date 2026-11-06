<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Handlers;

use CPointLabs\HostYourAI\Exceptions\StructuredOutputException;
use CPointLabs\HostYourAI\Prism\Concerns\ReadsChatCompletions;
use CPointLabs\HostYourAI\Prism\Maps\MessageMap;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use JsonException;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * @internal
 */
final class Structured
{
    use ReadsChatCompletions;

    public function __construct(private readonly PendingRequest $client) {}

    public function handle(Request $request): StructuredResponse
    {
        if ($request->tools() !== []) {
            throw new StructuredOutputException('Structured output with tools is not supported by this provider.');
        }

        $response = $this->send($request);
        $data = $this->decode($response);

        $text = $this->content($data);
        $finishReason = $this->finishReason(Payload::string($data, 'choices.0.finish_reason'));

        if ($finishReason !== FinishReason::Stop) {
            throw new StructuredOutputException(
                "The model stopped before finishing its structured output (finish reason: {$finishReason->value})."
            );
        }

        $structured = $this->decodeObject($text);

        $request->addMessage(new AssistantMessage($text));

        return (new ResponseBuilder)->addStep(new Step(
            text: $text,
            finishReason: $finishReason,
            usage: $this->usage(Payload::map($data, 'usage')),
            meta: $this->meta($data, $response),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            structured: $structured,
        ))->toResponse();
    }

    private function send(Request $request): Response
    {
        $schema = $request->schema()->toArray();

        // Also spelled out in a system prompt, so that models that only have
        // the output constrained still know what they are answering in.
        $instruction = new SystemMessage(
            "Respond only with JSON that matches this schema:\n".json_encode($schema, JSON_PRETTY_PRINT)
        );

        return $this->client->post('chat/completions', Payload::stringKeys([
            'model' => $request->model(),
            'messages' => MessageMap::map($request->messages(), [...$request->systemPrompts(), $instruction]),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->schema()->name(),
                    'strict' => $request->providerOptions('schema.strict') ?? true,
                    'schema' => $schema,
                ],
            ],
            ...Arr::whereNotNull([
                'max_tokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
            ]),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $text): array
    {
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/s', trim($text), $matches) === 1) {
            $text = trim($matches[1]);
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // The text stays out of the message: it may repeat what the user sent.
            throw new StructuredOutputException('The model did not return valid JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new StructuredOutputException('The model did not return a JSON object.');
        }

        return Payload::stringKeys($decoded);
    }
}
