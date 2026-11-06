<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Exceptions\BudgetExceededException;
use CPointLabs\HostYourAI\Exceptions\ConfigurationException;
use CPointLabs\HostYourAI\Exceptions\ConnectionException;
use CPointLabs\HostYourAI\Exceptions\ModelNotAllowedException;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;
use CPointLabs\HostYourAI\Exceptions\RateLimitedException;
use CPointLabs\HostYourAI\Exceptions\UnexpectedResponseException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

const CHAT_URL = 'https://api.test/api/v1/chat/completions';

it('registers the provider without touching other Prism providers', function (): void {
    expect(config('prism.providers.openai'))->toBeArray()
        ->and(config('prism.providers.hostyourai'))->toBeNull();
});

it('generates text', function (): void {
    Http::fake([CHAT_URL => Http::response(completion(['content' => 'Hello']), 200, [
        'x-ratelimit-limit-requests' => '50',
        'x-ratelimit-remaining-requests' => '47',
    ])]);

    $response = Prism::text()
        ->using('hostyourai', 'hyai/auto')
        ->withSystemPrompt('Be brief.')
        ->withPrompt('Say hello')
        ->withMaxTokens(100)
        ->usingTemperature(0.2)
        ->asText();

    expect($response->text)->toBe('Hello')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5)
        ->and($response->meta->model)->toBe('test/model')
        ->and($response->meta->rateLimits[0]->name)->toBe('requests')
        ->and($response->meta->rateLimits[0]->remaining)->toBe(47);

    Http::assertSent(fn (Request $request): bool => $request->url() === CHAT_URL
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'hyai/auto'
        && $request['max_tokens'] === 100
        && $request['temperature'] === 0.2
        && $request['messages'] === [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'Say hello'],
        ]
        && ! isset($request['stream'], $request['tools']));
});

it('sends images as content parts', function (): void {
    Http::fake([CHAT_URL => Http::response(completion(['content' => 'A cat']))]);

    Prism::text()
        ->using('hostyourai', 'hyai/auto')
        ->withMessages([new UserMessage('What is this?', [Image::fromUrl('https://example.test/cat.png')])])
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request['messages'][0]['content'] === [
        ['type' => 'text', 'text' => 'What is this?'],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/cat.png']],
    ]);
});

it('keeps reasoning out of text responses', function (): void {
    Http::fake([CHAT_URL => Http::response(completion([
        'content' => "\n\nOK",
        'reasoning_content' => 'private reasoning',
        'provider_specific_fields' => ['reasoning_content' => 'private reasoning'],
    ]))]);

    $response = Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Say OK')->asText();

    expect($response->text)->toBe('OK')
        ->and(json_encode($response))->not->toContain('private reasoning');
});

it('returns empty text when reasoning used up max_tokens', function (): void {
    Http::fake([CHAT_URL => Http::response(completion(['content' => null, 'reasoning_content' => 'thinking'], 'length'))]);

    $response = Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Say OK')->withMaxTokens(5)->asText();

    expect($response->text)->toBe('')
        ->and($response->finishReason)->toBe(FinishReason::Length);
});

it('reports reasoning tokens in usage', function (): void {
    $body = completion(['content' => 'OK']);
    $body['usage'] = ['prompt_tokens' => 54, 'completion_tokens' => 23, 'completion_tokens_details' => ['reasoning_tokens' => 20]];

    Http::fake([CHAT_URL => Http::response($body)]);

    expect(Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Say OK')->asText()->usage->thoughtTokens)->toBe(20);
});

it('runs tools and continues the conversation', function (): void {
    Http::fake([CHAT_URL => Http::sequence()
        ->push(completion(['content' => '', 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}']],
        ]], 'tool_calls'))
        ->push(completion(['content' => 'The answer is 5.']))]);

    $add = Tool::as('add')
        ->for('Add numbers')
        ->withNumberParameter('a', 'First')
        ->withNumberParameter('b', 'Second')
        ->using(fn (int $a, int $b): string => (string) ($a + $b));

    $response = Prism::text()
        ->using('hostyourai', 'hyai/auto')
        ->withPrompt('What is 2+3?')
        ->withTools([$add])
        ->withToolChoice(ToolChoice::Any)
        ->withMaxSteps(3)
        ->asText();

    expect($response->text)->toBe('The answer is 5.')
        ->and($response->steps)->toHaveCount(2)
        ->and($response->steps[0]->toolResults[0]->result)->toBe('5');

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect($requests[0]['tool_choice'])->toBe('required')
        ->and($requests[0]['tools'][0]['function']['name'])->toBe('add')
        ->and($requests[0]['tools'][0]['function']['parameters']['required'])->toBe(['a', 'b'])
        ->and($requests[1]['tool_choice'])->toBe('auto')
        ->and($requests[1]['messages'][1]['tool_calls'][0]['id'])->toBe('call_1')
        ->and($requests[1]['messages'][2])->toBe(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '5']);
});

it('maps API errors to typed exceptions', function (int $status, array $body, array $headers, string $class): void {
    Http::fake([CHAT_URL => Http::response($body, $status, $headers)]);

    Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();
})->with([
    '402' => [402, ['error' => ['message' => 'Budget', 'code' => 'team_budget_exceeded']], [], BudgetExceededException::class],
    '403' => [403, ['error' => ['message' => 'No', 'code' => 'model_not_allowed']], [], ModelNotAllowedException::class],
    '503' => [503, ['error' => ['message' => 'Busy', 'code' => 'model_unavailable']], ['Retry-After' => '9'], ModelUnavailableException::class],
])->throws(CPointLabs\HostYourAI\Exceptions\HostYourAIException::class);

it('exposes Retry-After from Prism calls', function (): void {
    Http::fake([CHAT_URL => Http::response(['error' => ['message' => 'Busy', 'code' => 'model_unavailable']], 503, ['Retry-After' => '9'])]);

    try {
        Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();
        $this->fail('Expected an exception.');
    } catch (ModelUnavailableException $e) {
        expect($e->retryAfter)->toBe(9)
            ->and($e->errorCode)->toBe('model_unavailable');
    }
});

it('throws on an error inside a 200 response', function (): void {
    Http::fake([CHAT_URL => Http::response(['error' => ['message' => 'Busy', 'status' => 503]])]);

    Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();
})->throws(ModelUnavailableException::class);

it('throws on a completion without choices', function (): void {
    Http::fake([CHAT_URL => Http::response(['id' => 'x'])]);

    Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();
})->throws(UnexpectedResponseException::class);

it('wraps connection failures', function (): void {
    Http::fake(fn () => throw new HttpConnectionException('cURL error 28: timed out'));

    Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();
})->throws(ConnectionException::class);

it('needs an API key', function (): void {
    config()->set('hostyourai.api_key', null);

    Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();
})->throws(ConfigurationException::class);

it('takes provider config per call', function (): void {
    Http::fake(['https://other.test/v1/chat/completions' => Http::response(completion(['content' => 'Hi']))]);

    Prism::text()
        ->using('hostyourai', 'hyai/auto', ['api_key' => 'other-key', 'url' => 'https://other.test/v1'])
        ->withPrompt('Hi')
        ->asText();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer other-key'));
});

it('keeps prompt and key out of exceptions', function (): void {
    Http::fake([CHAT_URL => Http::response(['error' => ['message' => 'Busy']], 503)]);

    try {
        Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('secret prompt')->asText();
        $this->fail('Expected an exception.');
    } catch (ModelUnavailableException $e) {
        expect($e->getMessage().print_r($e->getPrevious(), true))->not->toContain('secret prompt')->not->toContain('test-key')
            ->and($e->responseBody)->toBeNull();
    }
});

describe('with retries configured', function (): void {
    beforeEach(function (): void {
        config()->set('hostyourai.retry', ['times' => 2, 'max_wait' => 5]);
        Illuminate\Support\Sleep::fake();

        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Slow down']], 429, ['Retry-After' => '1'])
            ->push(completion(['content' => 'Hello']))]);
    });

    it('retries a Prism call', function (): void {
        $response = Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->asText();

        expect($response->text)->toBe('Hello');

        Http::assertSentCount(2);
    });

    it('lets a call turn them off', function (): void {
        expect(fn () => Prism::text()->using('hostyourai', 'hyai/auto')->withPrompt('Hi')->withClientRetry(0)->asText())
            ->toThrow(RateLimitedException::class);

        Http::assertSentCount(1);
    });
});
