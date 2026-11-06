<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Data;

use CPointLabs\HostYourAI\Support\Payload;

/**
 * A decision model from GET /api/decision/v1/models.
 */
final readonly class DecisionModel
{
    /**
     * @param list<string> $questionTypes
     */
    public function __construct(
        public string $id,
        public bool $ready = false,
        public ?string $state = null,
        public array $questionTypes = [],
        public ?int $minChoices = null,
        public ?int $maxChoices = null,
        public ?int $maxQuestions = null,
        public ?int $contextTokensPerQuestion = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id') ?? '',
            ready: Payload::bool($data, 'ready') ?? false,
            state: Payload::string($data, 'state'),
            questionTypes: Payload::strings($data, 'capabilities.question_types'),
            minChoices: Payload::int($data, 'capabilities.min_choices'),
            maxChoices: Payload::int($data, 'capabilities.max_choices'),
            maxQuestions: Payload::int($data, 'capabilities.max_questions'),
            contextTokensPerQuestion: Payload::int($data, 'capabilities.context_tokens_per_question'),
        );
    }
}
