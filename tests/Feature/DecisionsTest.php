<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Decisions\Answers\NoulAnswer;
use CPointLabs\HostYourAI\Decisions\Questions\Choice;
use CPointLabs\HostYourAI\Decisions\Questions\Noul;
use CPointLabs\HostYourAI\Decisions\Questions\Score;
use CPointLabs\HostYourAI\Exceptions\AuthenticationException;
use CPointLabs\HostYourAI\Exceptions\BudgetExceededException;
use CPointLabs\HostYourAI\Exceptions\ConfigurationException;
use CPointLabs\HostYourAI\Exceptions\ConnectionException;
use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;
use CPointLabs\HostYourAI\Exceptions\RateLimitedException;
use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use CPointLabs\HostYourAI\Facades\HostYourAI;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

const DECISION_URL = 'https://api.test/api/decision/v1/systemone';

function decisionResponse(array $answers): array
{
    return [
        'model' => 'hyai/test',
        'answers' => $answers,
        'usage' => ['input_tokens' => 64, 'output_tokens' => 0],
        'hyai' => ['billing' => ['cost_credits' => 0.00004]],
    ];
}

it('sends the questions and returns typed answers', function (): void {
    Http::fake([DECISION_URL => Http::response(decisionResponse([
        'team' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.88, 'probabilities' => ['billing' => 0.92, 'other' => 0.08]],
        'refund' => ['type' => 'noul', 'noul' => 0.24],
        'urgency' => ['type' => 'score', 'score' => 1.7, 'confidence' => 0.55, 'legend' => ['0' => 'Low', '1' => 'Mid', '2' => 'High'], 'probabilities' => ['0' => 0.1, '1' => 0.1, '2' => 0.8]],
    ]))]);

    $result = HostYourAI::decision()
        ->model('hyai/test')
        ->state('I was charged twice.')
        ->ask('team', Choice::make('Which team?', ['billing' => 'Payments', 'other' => 'Anything else']))
        ->ask('refund', Noul::make('Is a refund requested?'))
        ->ask('urgency', Score::make('How urgent?', ['Low', 'Mid', 'High']))
        ->send();

    expect($result->model)->toBe('hyai/test')
        ->and($result->usage->inputTokens)->toBe(64)
        ->and($result->costCredits)->toBe(0.00004)
        ->and($result->choice('team')->choice)->toBe('billing')
        ->and($result->choice('team')->confidence)->toBe(0.88)
        ->and($result->choice('team')->probabilityOf('other'))->toBe(0.08)
        ->and($result->noul('refund')->probability)->toBe(0.24)
        ->and($result->noul('refund')->isYes(0.8))->toBeFalse()
        ->and($result->noul('refund')->isYes(0.2))->toBeTrue()
        ->and($result->score('urgency')->score)->toBe(1.7)
        ->and($result->score('urgency')->legend[2])->toBe('High');

    Http::assertSent(fn (Request $request): bool => $request->url() === DECISION_URL
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'hyai/test'
        && $request['state'] === 'I was charged twice.'
        && $request['questions']['refund'] === ['type' => 'noul', 'instructions' => 'Is a refund requested?']
        && $request['questions']['urgency']['criteria'] === ['Low', 'Mid', 'High']);
});

it('uses the configured default model', function (): void {
    Http::fake([DECISION_URL => Http::response(decisionResponse(['q' => ['type' => 'noul', 'noul' => 1]]))]);

    HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'hyai/loes-beslis');
});

it('accepts structured state', function (): void {
    Http::fake([DECISION_URL => Http::response(decisionResponse(['q' => ['type' => 'noul', 'noul' => 1]]))]);

    HostYourAI::decision()->state(['subject' => 'Hi', 'body' => 'Text'])->ask('q', Noul::make('Yes?'))->send();

    Http::assertSent(fn (Request $request): bool => $request['state'] === ['subject' => 'Hi', 'body' => 'Text']);
});

it('derives the decision URL from the host of the base URL', function (): void {
    config()->set('hostyourai.base_url', 'https://eu.example.test:8443/api/v1/');
    app()->forgetInstance(CPointLabs\HostYourAI\HostYourAI::class);
    HostYourAI::clearResolvedInstances();

    Http::fake(['https://eu.example.test:8443/api/decision/v1/systemone' => Http::response(decisionResponse(['q' => ['type' => 'noul', 'noul' => 1]]))]);

    expect(HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send()->noul('q'))->toBeInstanceOf(NoulAnswer::class);
});

it('checks the request before sending it', function (Closure $build, string $message): void {
    Http::fake();

    expect(fn () => $build(HostYourAI::decision()))->toThrow(LogicException::class, $message);

    Http::assertNothingSent();
})->with([
    'no state' => [fn ($decision) => $decision->ask('q', Noul::make('Yes?'))->send(), 'state'],
    'no questions' => [fn ($decision) => $decision->state('Text')->send(), 'question'],
]);

it('allows at most 16 questions', function (): void {
    $decision = HostYourAI::decision()->state('Text');

    foreach (range(1, 16) as $i) {
        $decision->ask("q{$i}", Noul::make('Yes?'));
    }

    $decision->ask('q17', Noul::make('Yes?'));
})->throws(InvalidArgumentException::class);

it('maps API errors to typed exceptions', function (int $status, array $body, array $headers, string $class, ?int $retryAfter): void {
    Http::fake([DECISION_URL => Http::response($body, $status, $headers)]);

    try {
        HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
        $this->fail('Expected an exception.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->retryAfter)->toBe($retryAfter);
    }
})->with([
    '401' => [401, ['error' => ['message' => 'Invalid key', 'type' => 'authentication_error']], [], AuthenticationException::class, null],
    '402' => [402, ['error' => ['message' => 'Budget', 'type' => 'x', 'code' => 'team_budget_exceeded']], [], BudgetExceededException::class, null],
    '422' => [422, ['error' => ['message' => 'Bad', 'type' => 'invalid_request_error']], [], InvalidRequestException::class, null],
    '429' => [429, ['error' => ['message' => 'Slow down']], ['Retry-After' => '7'], RateLimitedException::class, 7],
    '503 with Retry-After' => [503, ['error' => ['message' => 'Busy', 'code' => 'model_unavailable']], ['Retry-After' => '30'], ModelUnavailableException::class, 30],
    '503 without Retry-After' => [503, ['error' => ['message' => 'Cold', 'type' => 'decision_unavailable']], [], ModelUnavailableException::class, null],
]);

it('reports a request id when the API sends one', function (): void {
    Http::fake([DECISION_URL => Http::response(['error' => ['message' => 'Busy']], 503, ['X-Request-Id' => 'req-9'])]);

    try {
        HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
    } catch (ModelUnavailableException $e) {
        expect($e->requestId)->toBe('req-9');
    }
});

it('throws on a response it cannot read', function (mixed $body): void {
    Http::fake([DECISION_URL => Http::response($body)]);

    HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
})->with([
    'not JSON' => ['<html>'],
    'no answers' => [['model' => 'x']],
    'unknown answer type' => [['answers' => ['q' => ['type' => 'box']]]],
    'missing value' => [['answers' => ['q' => ['type' => 'noul']]]],
])->throws(UnexpectedResponseException::class);

it('wraps connection failures', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28: timed out'));

    HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
})->throws(ConnectionException::class, 'timed out');

it('applies a timeout per call', function (): void {
    Http::fake([DECISION_URL => Http::response(decisionResponse(['q' => ['type' => 'noul', 'noul' => 1]]))]);

    $timeouts = [];
    Http::globalRequestMiddleware(fn ($request) => $request);

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(decisionResponse(['q' => ['type' => 'noul', 'noul' => 1]]));
    });

    HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->timeout(3)->send();

    expect($timeouts)->toBe([3]);
});

it('does not retry by default', function (): void {
    Http::fake([DECISION_URL => Http::response(['error' => ['message' => 'Busy']], 503, ['Retry-After' => '1'])]);

    expect(fn () => HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send())
        ->toThrow(ModelUnavailableException::class);

    Http::assertSentCount(1);
});

describe('with retries enabled', function (): void {
    beforeEach(function (): void {
        config()->set('hostyourai.retry', ['times' => 2, 'max_wait' => 5]);
        app()->forgetInstance(CPointLabs\HostYourAI\HostYourAI::class);
        HostYourAI::clearResolvedInstances();
        Sleep::fake();
    });

    it('waits for Retry-After and tries again', function (): void {
        Http::fake([DECISION_URL => Http::sequence()
            ->push(['error' => ['message' => 'Busy']], 503, ['Retry-After' => '3'])
            ->push(decisionResponse(['q' => ['type' => 'noul', 'noul' => 0.5]]))]);

        $result = HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();

        expect($result->noul('q')->probability)->toBe(0.5);

        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 3);
    });

    it('gives up when Retry-After is longer than the maximum wait', function (): void {
        Http::fake([DECISION_URL => Http::response(['error' => ['message' => 'Busy']], 503, ['Retry-After' => '60'])]);

        try {
            HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send();
            $this->fail('Expected an exception.');
        } catch (ModelUnavailableException $e) {
            expect($e->retryAfter)->toBe(60);
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    });

    it('does not retry other errors', function (): void {
        Http::fake([DECISION_URL => Http::response(['error' => ['message' => 'Bad']], 422)]);

        expect(fn () => HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send())
            ->toThrow(InvalidRequestException::class);

        Http::assertSentCount(1);
    });
});

it('needs an API key', function (): void {
    config()->set('hostyourai.api_key', null);
    app()->forgetInstance(CPointLabs\HostYourAI\HostYourAI::class);
    HostYourAI::clearResolvedInstances();
    Http::fake();

    expect(fn () => HostYourAI::decision()->state('Text')->ask('q', Noul::make('Yes?'))->send())
        ->toThrow(ConfigurationException::class);

    Http::assertNothingSent();
});

it('keeps state and key out of logs, exceptions and dumps', function (): void {
    Log::spy();
    Http::fake([DECISION_URL => Http::response(['error' => ['message' => 'Busy']], 503)]);

    $decision = HostYourAI::decision()->state('secret question')->ask('q', Noul::make('Yes?'));

    try {
        $decision->send();
        $this->fail('Expected an exception.');
    } catch (ModelUnavailableException $e) {
        $report = $e->getMessage().$e->getTraceAsString().print_r($e->getPrevious(), true);

        expect($report)->not->toContain('secret question')->not->toContain('test-key');
    }

    expect(print_r($decision->toRequest(), true))->not->toContain('secret question');

    Log::shouldNotHaveReceived('log');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('error');
});
