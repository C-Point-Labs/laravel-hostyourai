<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Testing;

use Closure;
use CPointLabs\HostYourAI\Data\Balance;
use CPointLabs\HostYourAI\Data\Usage;
use CPointLabs\HostYourAI\Decisions\Answers\Answer;
use CPointLabs\HostYourAI\Decisions\Answers\ChoiceAnswer;
use CPointLabs\HostYourAI\Decisions\Answers\NoulAnswer;
use CPointLabs\HostYourAI\Decisions\Answers\ScoreAnswer;
use CPointLabs\HostYourAI\Decisions\DecisionRequest;
use CPointLabs\HostYourAI\Decisions\DecisionResult;
use CPointLabs\HostYourAI\Decisions\PendingDecision;
use CPointLabs\HostYourAI\Decisions\Questions\Choice;
use CPointLabs\HostYourAI\Decisions\Questions\Noul;
use CPointLabs\HostYourAI\Decisions\Questions\Score;
use CPointLabs\HostYourAI\HostYourAI;
use CPointLabs\HostYourAI\Http\ClientFactory;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A stand-in for the client in your tests.
 *
 * $decisions is one of:
 *  - a map of question id to answer: an Answer, or a shorthand of a float for
 *    noul and score questions and a string for choice questions;
 *  - a Throwable, thrown on every decision;
 *  - a Closure that takes the DecisionRequest and returns either of those.
 */
class HostYourAIFake extends HostYourAI
{
    /** @var list<DecisionRequest> */
    private array $recorded = [];

    /**
     * @param array<string, mixed>|(Closure(DecisionRequest): (array<string, mixed>|Throwable))|Throwable $decisions
     * @param array<int, \CPointLabs\HostYourAI\Data\Model> $fakeModels
     * @param array<int, \CPointLabs\HostYourAI\Data\DecisionModel> $fakeDecisionModels
     */
    public function __construct(
        private readonly array|Closure|Throwable $decisions = [],
        private readonly ?Balance $fakeBalance = null,
        private readonly array $fakeModels = [],
        private readonly array $fakeDecisionModels = [],
    ) {
        // Never used: every method that would reach the API is overridden.
        parent::__construct(new ClientFactory('fake-key', 'https://hostyourai.invalid/api/v1'));
    }

    public function decision(): PendingDecision
    {
        return new PendingDecision($this, 'fake/model');
    }

    public function sendDecision(DecisionRequest $request): DecisionResult
    {
        $this->recorded[] = $request;

        // What the real client would send, so a request it can't send fails here too.
        json_encode($request->toArray(), JSON_THROW_ON_ERROR);

        $answers = $this->decisions instanceof Closure ? ($this->decisions)($request) : $this->decisions;

        if ($answers instanceof Throwable) {
            throw $answers;
        }

        $mapped = [];

        foreach ($request->questions as $id => $question) {
            if (! array_key_exists($id, $answers)) {
                throw new LogicException("No fake answer for question [{$id}].");
            }

            $mapped[$id] = $this->toAnswer($answers[$id], $question);
        }

        return new DecisionResult($request->model, $mapped, new Usage(0, 0));
    }

    public function balance(): Balance
    {
        return $this->fakeBalance ?? new Balance('EUR', 0.0, 0.0, false);
    }

    public function models(): Collection
    {
        return collect($this->fakeModels);
    }

    public function decisionModels(): Collection
    {
        return collect($this->fakeDecisionModels);
    }

    /**
     * @return list<DecisionRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * @param (Closure(DecisionRequest): bool)|null $callback
     */
    public function assertDecided(?Closure $callback = null): void
    {
        $matching = $callback === null ? $this->recorded : array_filter($this->recorded, $callback);

        Assert::assertNotEmpty($matching, 'The expected decision was not sent.');
    }

    public function assertNothingDecided(): void
    {
        Assert::assertEmpty($this->recorded, 'A decision was sent unexpectedly.');
    }

    public function assertDecisionCount(int $count): void
    {
        Assert::assertCount($count, $this->recorded);
    }

    private function toAnswer(mixed $answer, object $question): Answer
    {
        if ($answer instanceof Answer) {
            return $answer;
        }

        return match (true) {
            $question instanceof Noul && is_numeric($answer) => new NoulAnswer((float) $answer),
            $question instanceof Score && is_numeric($answer) && (float) $answer >= 0.0 && (float) $answer <= (float) (count($question->levels) - 1) => new ScoreAnswer((float) $answer, 1.0, $question->levels, []),
            $question instanceof Choice && is_string($answer) && array_key_exists($answer, $question->criteria) => new ChoiceAnswer($answer, 1.0, [$answer => 1.0]),
            default => throw new LogicException('The fake answer does not fit the question type.'),
        };
    }
}
