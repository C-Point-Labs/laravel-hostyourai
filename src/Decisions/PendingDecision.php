<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions;

use CPointLabs\HostYourAI\Contracts\SendsDecisions;
use CPointLabs\HostYourAI\Decisions\Questions\Question;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Fluent builder for one decision call. The class is final and only reaches the
 * API through DecisionRequest, so new options can be added without breaking
 * existing callers.
 */
final class PendingDecision
{
    public const MAX_QUESTIONS = 16;

    /** @var string|array<mixed>|null */
    private string|array|null $state = null;

    /** @var array<string, Question> */
    private array $questions = [];

    private ?int $timeout = null;

    public function __construct(
        private readonly SendsDecisions $sender,
        private ?string $model = null,
    ) {}

    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * The content every question refers to.
     *
     * @param string|array<mixed> $state
     */
    public function state(#[SensitiveParameter] string|array $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Adds a question. The answer comes back under the same id.
     */
    public function ask(string $id, Question $question): self
    {
        if ($id === '') {
            throw new InvalidArgumentException('A question needs a non-empty id.');
        }

        if (! isset($this->questions[$id]) && count($this->questions) >= self::MAX_QUESTIONS) {
            throw new InvalidArgumentException(sprintf('A decision takes at most %d questions.', self::MAX_QUESTIONS));
        }

        $this->questions[$id] = $question;

        return $this;
    }

    /**
     * Overrides the configured request timeout, in seconds, for this call.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function send(): DecisionResult
    {
        return $this->sender->sendDecision($this->toRequest());
    }

    public function toRequest(): DecisionRequest
    {
        if ($this->model === null || $this->model === '') {
            throw new LogicException('A decision needs a model.');
        }

        if ($this->state === null) {
            throw new LogicException('A decision needs state.');
        }

        if ($this->questions === []) {
            throw new LogicException('A decision needs at least one question.');
        }

        return new DecisionRequest($this->model, $this->state, $this->questions, $this->timeout);
    }
}
