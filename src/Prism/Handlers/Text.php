<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Handlers;

use CPointLabs\HostYourAI\Prism\Concerns\ReadsChatCompletions;
use CPointLabs\HostYourAI\Prism\Maps\MessageMap;
use CPointLabs\HostYourAI\Prism\Maps\ToolChoiceMap;
use CPointLabs\HostYourAI\Prism\Maps\ToolMap;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;

/**
 * @internal
 */
final class Text
{
    use CallsTools;
    use ReadsChatCompletions;

    private ResponseBuilder $responseBuilder;

    public function __construct(private readonly PendingRequest $client)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(Request $request): TextResponse
    {
        $response = $this->send($request);
        $data = $this->decode($response);

        $text = $this->content($data);
        $toolCalls = $this->toolCalls($data);
        $toolResults = [];

        $request->addMessage(new AssistantMessage($text, $toolCalls));

        if ($toolCalls !== []) {
            $toolResults = $this->callTools($request->tools(), $toolCalls);

            $request->addMessage(new ToolResultMessage($toolResults));
        }

        $this->responseBuilder->addStep(new Step(
            text: $text,
            finishReason: $this->finishReason(Payload::string($data, 'choices.0.finish_reason')),
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            providerToolCalls: [],
            usage: $this->usage(Payload::map($data, 'usage')),
            meta: $this->meta($data, $response),
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
        ));

        if ($toolCalls !== [] && $this->responseBuilder->steps->count() < $request->maxSteps()) {
            $request->resetToolChoice();

            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    private function send(Request $request): Response
    {
        return $this->client->post('chat/completions', Payload::stringKeys([
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
