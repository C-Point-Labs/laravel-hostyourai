<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions;

use CPointLabs\HostYourAI\Decisions\Questions\Question;
use SensitiveParameter;

/**
 * A complete decision call. toArray() is the only place the request body is
 * built, so new request features only need a new optional property.
 */
final readonly class DecisionRequest
{
    /**
     * @param string|array<mixed> $state
     * @param array<string, Question> $questions
     */
    public function __construct(
        public string $model,
        #[SensitiveParameter] public string|array $state,
        public array $questions,
        public ?int $timeout = null,
    ) {}

    /**
     * Keeps the visitor's text out of dumps and error pages.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['model' => $this->model, 'state' => '[hidden]', 'questions' => array_keys($this->questions), 'timeout' => $this->timeout];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'state' => $this->state,
            'questions' => array_map(fn (Question $question): array => $question->toArray(), $this->questions),
        ];
    }
}
