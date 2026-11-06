<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Prism\Maps;

use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;
use InvalidArgumentException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use stdClass;

/**
 * Maps Prism messages to the OpenAI chat format.
 *
 * @internal
 */
final class MessageMap
{
    /**
     * @param array<Message> $messages
     * @param array<SystemMessage> $systemPrompts
     * @return list<array<string, mixed>>
     */
    public static function map(array $messages, array $systemPrompts = []): array
    {
        $mapped = [];

        foreach ([...$systemPrompts, ...$messages] as $message) {
            array_push($mapped, ...self::mapMessage($message));
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function mapMessage(Message $message): array
    {
        return match (true) {
            $message instanceof SystemMessage => [['role' => 'system', 'content' => $message->content]],
            $message instanceof UserMessage => [['role' => 'user', 'content' => self::userContent($message)]],
            $message instanceof AssistantMessage => [self::assistant($message)],
            $message instanceof ToolResultMessage => self::toolResults($message),
            default => throw new InvalidArgumentException('Could not map message type '.$message::class),
        };
    }

    /**
     * @return string|list<array<string, mixed>>
     */
    private static function userContent(UserMessage $message): string|array
    {
        if ($message->documents() !== [] || $message->audios() !== [] || $message->videos() !== []) {
            throw new InvalidRequestException('This provider only accepts text and images, not documents, audio or video.');
        }

        if ($message->images() === []) {
            return $message->text();
        }

        $parts = [['type' => 'text', 'text' => $message->text()]];

        foreach ($message->images() as $image) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image->isUrl()
                        ? $image->url()
                        : sprintf('data:%s;base64,%s', $image->mimeType(), $image->base64()),
                ],
            ];
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function assistant(AssistantMessage $message): array
    {
        $mapped = ['role' => 'assistant', 'content' => $message->content];

        if ($message->toolCalls !== []) {
            $mapped['tool_calls'] = array_map(fn (ToolCall $toolCall): array => [
                'id' => $toolCall->id,
                'type' => 'function',
                'function' => [
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments() === [] ? new stdClass : $toolCall->arguments(), JSON_THROW_ON_ERROR),
                ],
            ], $message->toolCalls);
        }

        return $mapped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolResults(ToolResultMessage $message): array
    {
        $mapped = [];

        foreach ($message->toolResults as $toolResult) {
            $mapped[] = [
                'role' => 'tool',
                'tool_call_id' => $toolResult->toolCallId,
                'content' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result, JSON_THROW_ON_ERROR),
            ];
        }

        return $mapped;
    }
}
