<?php

declare(strict_types=1);

use CPointLabs\HostYourAI\Exceptions\PersonalKeyRequiredException;
use CPointLabs\HostYourAI\Facades\HostYourAI;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads the balance', function (): void {
    Http::fake(['https://api.test/api/v1/balance' => Http::response([
        'object' => 'balance',
        'currency' => 'EUR',
        'balance' => 41.25,
        'credit_limit' => 0,
        'available' => 41.25,
        'spend_month' => 12.8,
        'auto_topup' => false,
        'unlimited' => false,
        'as_of' => '2026-11-06T18:49:25Z',
    ])]);

    $balance = HostYourAI::balance();

    expect($balance->currency)->toBe('EUR')
        ->and($balance->balance)->toBe(41.25)
        ->and($balance->available)->toBe(41.25)
        ->and($balance->spendMonth)->toBe(12.8)
        ->and($balance->autoTopup)->toBeFalse()
        ->and($balance->unlimited)->toBeFalse()
        ->and($balance->asOf?->toDateString())->toBe('2026-11-06');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

it('reads a balance with only the documented fields', function (): void {
    Http::fake(['*/balance' => Http::response(['currency' => 'EUR', 'balance' => 1, 'spend_month' => 0, 'auto_topup' => true, 'as_of' => '2026-11-06T00:00:00Z'])]);

    expect(HostYourAI::balance()->available)->toBeNull();
});

it('reports a shared key on the balance endpoint', function (): void {
    Http::fake(['*/balance' => Http::response(['error' => ['message' => 'Personal key needed', 'type' => 'x', 'code' => 'personal_key_required']], 403)]);

    HostYourAI::balance();
})->throws(PersonalKeyRequiredException::class);

it('lists chat models', function (): void {
    Http::fake(['https://api.test/api/v1/models' => Http::response(['object' => 'list', 'data' => [
        ['id' => 'hyai/auto', 'display_name' => 'Autopilot', 'available' => true, 'context_length' => null, 'supports_tools' => true, 'supports_images' => true, 'pricing' => ['input_per_million' => null, 'currency' => 'EUR']],
        ['id' => 'small', 'available' => false, 'context_length' => 8192, 'supports_tools' => false, 'supports_images' => false, 'pricing' => ['input_per_million' => 0.1, 'output_per_million' => 0.2]],
    ]])]);

    $models = HostYourAI::models();

    expect($models)->toHaveCount(2)
        ->and($models[0]->id)->toBe('hyai/auto')
        ->and($models[0]->contextLength)->toBeNull()
        ->and($models[0]->supportsTools)->toBeTrue()
        ->and($models[0]->supportsImages)->toBeTrue()
        ->and($models[1]->contextLength)->toBe(8192)
        ->and($models[1]->supportsTools)->toBeFalse()
        ->and($models[1]->outputPerMillion)->toBe(0.2);
});

it('lists decision models', function (): void {
    Http::fake(['https://api.test/api/decision/v1/models' => Http::response(['data' => [
        ['id' => 'hyai/loes-beslis', 'ready' => true, 'state' => 'warm', 'capabilities' => ['question_types' => ['choice', 'noul', 'score'], 'min_choices' => 2, 'max_choices' => 16, 'max_questions' => 16, 'context_tokens_per_question' => 16383]],
        ['id' => 'hyai/laya', 'ready' => false, 'state' => 'cold'],
    ]])]);

    $models = HostYourAI::decisionModels();

    expect($models->firstWhere('ready', true)->id)->toBe('hyai/loes-beslis')
        ->and($models[0]->questionTypes)->toBe(['choice', 'noul', 'score'])
        ->and($models[0]->contextTokensPerQuestion)->toBe(16383)
        ->and($models[1]->state)->toBe('cold')
        ->and($models[1]->maxQuestions)->toBeNull();
});
