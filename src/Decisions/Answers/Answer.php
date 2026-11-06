<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions\Answers;

use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;

abstract readonly class Answer
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return match ($data['type'] ?? null) {
            'choice' => ChoiceAnswer::fromArray($data),
            'noul' => NoulAnswer::fromArray($data),
            'score' => ScoreAnswer::fromArray($data),
            default => throw new UnexpectedResponseException('The decision API returned an answer of an unknown type.'),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function number(array $data, string $key): float
    {
        if (! is_numeric($data[$key] ?? null)) {
            throw new UnexpectedResponseException("A decision answer has no numeric \"{$key}\".");
        }

        return (float) $data[$key];
    }
}
