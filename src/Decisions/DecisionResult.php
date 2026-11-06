<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Decisions;

use CPointLabs\HostYourAI\Data\Usage;
use CPointLabs\HostYourAI\Decisions\Answers\Answer;
use CPointLabs\HostYourAI\Decisions\Answers\ChoiceAnswer;
use CPointLabs\HostYourAI\Decisions\Answers\NoulAnswer;
use CPointLabs\HostYourAI\Decisions\Answers\ScoreAnswer;
use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use CPointLabs\HostYourAI\Support\Payload;
use OutOfBoundsException;

final readonly class DecisionResult
{
    /**
     * @param array<string, Answer> $answers
     */
    public function __construct(
        public string $model,
        public array $answers,
        public Usage $usage,
        public ?float $costCredits = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (! is_array($data['answers'] ?? null)) {
            throw new UnexpectedResponseException('The decision API returned no answers.');
        }

        $answers = [];

        foreach (Payload::stringKeys($data['answers']) as $id => $answer) {
            $answers[$id] = Answer::fromArray(is_array($answer) ? Payload::stringKeys($answer) : []);
        }

        return new self(
            model: Payload::string($data, 'model') ?? '',
            answers: $answers,
            usage: Usage::fromArray(Payload::map($data, 'usage')),
            costCredits: Payload::float($data, 'hyai.billing.cost_credits'),
        );
    }

    public function answer(string $id): Answer
    {
        return $this->answers[$id] ?? throw new OutOfBoundsException("No answer for question [{$id}].");
    }

    public function choice(string $id): ChoiceAnswer
    {
        return $this->typed($id, ChoiceAnswer::class);
    }

    public function noul(string $id): NoulAnswer
    {
        return $this->typed($id, NoulAnswer::class);
    }

    public function score(string $id): ScoreAnswer
    {
        return $this->typed($id, ScoreAnswer::class);
    }

    /**
     * @template T of Answer
     *
     * @param class-string<T> $type
     * @return T
     */
    private function typed(string $id, string $type): Answer
    {
        $answer = $this->answer($id);

        if (! $answer instanceof $type) {
            throw new OutOfBoundsException("Question [{$id}] was not answered as ".class_basename($type).'.');
        }

        return $answer;
    }
}
