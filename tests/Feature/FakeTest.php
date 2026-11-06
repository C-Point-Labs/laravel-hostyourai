<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Data\Balance;
use CPointLabs\HostYourAI\Decisions\Answers\ChoiceAnswer;
use CPointLabs\HostYourAI\Decisions\DecisionRequest;
use CPointLabs\HostYourAI\Decisions\Questions\Choice;
use CPointLabs\HostYourAI\Decisions\Questions\Noul;
use CPointLabs\HostYourAI\Decisions\Questions\Score;
use CPointLabs\HostYourAI\Exceptions\BudgetExceededException;
use CPointLabs\HostYourAI\Facades\HostYourAI;
use CPointLabs\HostYourAI\HostYourAI as Client;
use Illuminate\Support\Facades\Http;

it('answers from shorthands without touching the network', function (): void {
    Http::fake();

    $fake = HostYourAI::fake(['n' => 0.9, 'c' => 'billing', 's' => 0.5]);

    $result = HostYourAI::decision()
        ->state('Text')
        ->ask('n', Noul::make('Yes?'))
        ->ask('c', Choice::make('Which?', ['billing', 'other']))
        ->ask('s', Score::make('How much?', ['Low', 'High']))
        ->send();

    expect($result->noul('n')->probability)->toBe(0.9)
        ->and($result->choice('c')->choice)->toBe('billing')
        ->and($result->score('s')->score)->toBe(0.5);

    $fake->assertDecisionCount(1);
    $fake->assertDecided(fn (DecisionRequest $request): bool => $request->state === 'Text');
    Http::assertNothingSent();
});

it('accepts full answers and closures', function (): void {
    HostYourAI::fake(fn (DecisionRequest $request): array => [
        'c' => new ChoiceAnswer('other', 0.4, ['other' => 0.6, 'billing' => 0.4]),
    ]);

    $result = HostYourAI::decision()->state('Text')->ask('c', Choice::make('Which?', ['billing', 'other']))->send();

    expect($result->choice('c')->confidence)->toBe(0.4);
});

it('throws a given exception', function (): void {
    HostYourAI::fake(new BudgetExceededException('Used up', 402));

    HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
})->throws(BudgetExceededException::class);

it('fails loudly on a question without a fake answer', function (): void {
    HostYourAI::fake([]);

    HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
})->throws(LogicException::class, 'q');

it('asserts that nothing was decided', function (): void {
    HostYourAI::fake()->assertNothingDecided();

    expect(true)->toBeTrue();
});

it('fakes balance and models', function (): void {
    HostYourAI::fake(balance: new Balance('EUR', 5.0, 1.0, false));

    expect(HostYourAI::balance()->balance)->toBe(5.0)
        ->and(HostYourAI::models())->toBeEmpty()
        ->and(HostYourAI::decisionModels())->toBeEmpty();
});

it('is also what the container hands out', function (): void {
    $fake = HostYourAI::fake();

    expect(app(Client::class))->toBe($fake);
});

it('refuses an answer the API could not give', function (array $answers, CPointLabs\HostYourAI\Decisions\Questions\Question $question): void {
    HostYourAI::fake($answers);

    HostYourAI::decision()->state('Text')->ask('q', $question)->send();
})->with([
    'unknown choice' => [['q' => 'other'], Choice::make('Which?', ['a', 'b'])],
    'score above the levels' => [['q' => 2], Score::make('How much?', ['Low', 'High'])],
    'score below the levels' => [['q' => -1], Score::make('How much?', ['Low', 'High'])],
])->throws(LogicException::class);

it('fails on a request the client could not send', function (): void {
    HostYourAI::fake(['q' => 0.5]);

    HostYourAI::decision()->state("\xB1")->ask('q', Noul::make('Yes?'))->send();
})->throws(JsonException::class);
