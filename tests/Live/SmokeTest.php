<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Data\DecisionModel;
use CPointLabs\HostYourAI\Decisions\Questions\Noul;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;
use CPointLabs\HostYourAI\Facades\HostYourAI;
use CPointLabs\HostYourAI\Prism\VisibleText;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;

/*
 * Calls the real API with neutral text and very small token limits. Skipped
 * unless HOSTYOURAI_LIVE_TESTS=true. Needs HOSTYOURAI_API_KEY. Cost: a fraction
 * of a cent per run.
 */

beforeEach(function (): void {
    if (getenv('HOSTYOURAI_LIVE_TESTS') !== 'true') {
        $this->markTestSkipped('Set HOSTYOURAI_LIVE_TESTS=true to run the live smoke test.');
    }

    config()->set('hostyourai.api_key', getenv('HOSTYOURAI_API_KEY') ?: null);
    config()->set('hostyourai.base_url', getenv('HOSTYOURAI_BASE_URL') ?: 'https://hostyourai.com/api/v1');
    config()->set('hostyourai.timeout', 60);
    app()->forgetInstance(CPointLabs\HostYourAI\HostYourAI::class);
    HostYourAI::clearResolvedInstances();
});

const LIVE_CHAT_MODEL = 'mistral-small-4-119b';

it('reads the balance and model lists', function (): void {
    expect(HostYourAI::balance()->currency)->toBe('EUR')
        ->and(HostYourAI::models()->pluck('id'))->toContain('hyai/auto')
        ->and(HostYourAI::decisionModels())->not->toBeEmpty();
});

it('gets a decision from a warm model', function (): void {
    $model = HostYourAI::decisionModels()->first(fn (DecisionModel $model): bool => $model->ready);

    if ($model === null) {
        $this->markTestSkipped('No decision model is warm right now.');
    }

    $result = HostYourAI::decision()
        ->model($model->id)
        ->state('The sky is blue.')
        ->ask('colour', Noul::make('Does the text mention a colour?'))
        ->send();

    expect($result->noul('colour')->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

it('generates and streams text', function (): void {
    $text = Prism::text()->using('hostyourai', LIVE_CHAT_MODEL)->withPrompt('Say OK')->withMaxTokens(8)->asText();

    expect($text->text)->not->toBe('');

    $stream = Prism::text()->using('hostyourai', LIVE_CHAT_MODEL)->withPrompt('Say OK')->withMaxTokens(8)->asStream();

    expect(implode('', iterator_to_array(VisibleText::from($stream), false)))->not->toBe('');
});

it('returns structured output', function (): void {
    $schema = new ObjectSchema('result', 'The result', [new BooleanSchema('ok', 'Answer true')], ['ok']);

    $response = Prism::structured()
        ->using('hostyourai', LIVE_CHAT_MODEL)
        ->withSchema($schema)
        ->withPrompt('Answer with ok set to true.')
        ->withMaxTokens(30)
        ->asStructured();

    expect($response->structured)->toHaveKey('ok');
});

it('completes a tool call round trip', function (): void {
    $add = Tool::as('add')
        ->for('Add two numbers')
        ->withNumberParameter('a', 'First number')
        ->withNumberParameter('b', 'Second number')
        ->using(fn (int|float $a, int|float $b): string => (string) ($a + $b));

    $response = Prism::text()
        ->using('hostyourai', LIVE_CHAT_MODEL)
        ->withPrompt('What is 2 + 3? Use the tool, then answer with the number.')
        ->withTools([$add])
        ->withToolChoice(ToolChoice::Any)
        ->withMaxSteps(2)
        ->withMaxTokens(40)
        ->asText();

    expect($response->steps)->toHaveCount(2)
        ->and($response->steps[0]->toolResults[0]->result)->toBe('5')
        ->and($response->text)->toContain('5');
});

it('keeps real reasoning apart from the answer', function (): void {
    try {
        $events = collect(iterator_to_array(
            Prism::text()->using('hostyourai', 'moonshotai/Kimi-K3')->withPrompt('Say OK')->withMaxTokens(120)->asStream(),
            false,
        ));
    } catch (ModelUnavailableException) {
        $this->markTestSkipped('The reasoning model is not available right now.');
    }

    $reasoning = $events->whereInstanceOf(ThinkingEvent::class)->map->delta->implode('');
    $answer = $events->whereInstanceOf(TextDeltaEvent::class)->map->delta->implode('');

    expect($reasoning)->not->toBe('')
        ->and($answer)->toContain('OK')
        ->and($answer)->not->toContain($reasoning)
        ->and($events->map(fn ($event): array => $event->toArray())->toJson())->not->toContain($reasoning);
});
