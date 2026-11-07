<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use CPointLabs\HostYourAI\Exceptions\AuthenticationException;
use CPointLabs\HostYourAI\Exceptions\BudgetExceededException;
use CPointLabs\HostYourAI\Exceptions\ExceptionFactory;
use CPointLabs\HostYourAI\Exceptions\HostYourAIException;
use CPointLabs\HostYourAI\Exceptions\InvalidRequestException;
use CPointLabs\HostYourAI\Exceptions\ModelNotAllowedException;
use CPointLabs\HostYourAI\Exceptions\ModelUnavailableException;
use CPointLabs\HostYourAI\Exceptions\PersonalKeyRequiredException;
use CPointLabs\HostYourAI\Exceptions\RateLimitedException;
use CPointLabs\HostYourAI\Exceptions\ServerException;
use CPointLabs\HostYourAI\Exceptions\SovereigntyViolationException;
use Prism\Prism\Exceptions\PrismException;

it('maps errors to typed exceptions', function (int $status, array $error, string $class): void {
    $exception = ExceptionFactory::make($status, $error);

    expect($exception)->toBeInstanceOf($class)
        ->and($exception)->toBeInstanceOf(PrismException::class)
        ->and($exception->status)->toBe($status)
        ->and($exception->httpStatus)->toBe($status);
})->with([
    'missing key' => [401, ['type' => 'authentication_error'], AuthenticationException::class],
    'budget' => [402, ['code' => 'team_budget_exceeded'], BudgetExceededException::class],
    'model not allowed' => [403, ['code' => 'model_not_allowed'], ModelNotAllowedException::class],
    'personal key' => [403, ['code' => 'personal_key_required'], PersonalKeyRequiredException::class],
    'sovereignty violation' => [403, ['code' => 'sovereignty_violation'], SovereigntyViolationException::class],
    'other 403' => [403, ['code' => 'something_else'], HostYourAIException::class],
    'bad request' => [400, ['type' => 'invalid_request_error'], InvalidRequestException::class],
    'validation without a code' => [422, ['type' => 'invalid_request_error'], InvalidRequestException::class],
    'rate limit' => [429, [], RateLimitedException::class],
    'model unavailable' => [503, ['code' => 'model_unavailable'], ModelUnavailableException::class],
    'decision unavailable by type' => [503, ['type' => 'decision_unavailable'], ModelUnavailableException::class],
    'sovereignty unavailable' => [503, ['code' => 'sovereignty_unavailable'], ModelUnavailableException::class],
    'router unavailable' => [503, ['code' => 'team_router_unavailable'], ModelUnavailableException::class],
    'loop detected' => [508, ['code' => 'loop_detected'], ServerException::class],
    'server error' => [500, [], ServerException::class],
]);

it('keeps the error fields and the API message', function (): void {
    $exception = ExceptionFactory::make(503, ['message' => 'Try later.', 'type' => 'x', 'code' => 'y'], 'req-1', 30);

    expect($exception->getMessage())->toBe('Try later.')
        ->and($exception->errorType)->toBe('x')
        ->and($exception->errorCode)->toBe('y')
        ->and($exception->requestId)->toBe('req-1')
        ->and($exception->retryAfter)->toBe(30);
});

it('falls back to a generic message', function (): void {
    expect(ExceptionFactory::make(500, [])->getMessage())->toBe('HostYourAI request failed with status 500.');
});

it('parses Retry-After as seconds or a date', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00 UTC');

    expect(ExceptionFactory::parseRetryAfter('120'))->toBe(120)
        ->and(ExceptionFactory::parseRetryAfter('Thu, 01 Jan 2026 12:00:30 GMT'))->toBe(30)
        ->and(ExceptionFactory::parseRetryAfter('Thu, 01 Jan 2026 11:00:00 GMT'))->toBe(0)
        ->and(ExceptionFactory::parseRetryAfter(''))->toBeNull()
        ->and(ExceptionFactory::parseRetryAfter(null))->toBeNull()
        ->and(ExceptionFactory::parseRetryAfter('not a date'))->toBeNull();

    CarbonImmutable::setTestNow();
});

it('gives the moment to retry at', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00 UTC');

    expect(ExceptionFactory::make(429, [], null, 30)->retryAt()?->toDateTimeString())->toBe('2026-01-01 12:00:30')
        ->and(ExceptionFactory::make(429, [])->retryAt())->toBeNull();

    CarbonImmutable::setTestNow();
});

it('maps an error inside a 200 response by its code', function (array $error, string $class, ?int $status = null): void {
    $exception = ExceptionFactory::fromErrorPayload($error);

    expect($exception)->toBeInstanceOf($class);

    if ($status !== null) {
        expect($exception->status)->toBe($status);
    }
})->with([
    'budget' => [['code' => 'team_budget_exceeded'], BudgetExceededException::class, 402],
    'model budget' => [['code' => 'team_model_budget_exceeded'], BudgetExceededException::class, 402],
    'sovereignty violation' => [['code' => 'sovereignty_violation'], SovereigntyViolationException::class, 403],
    'sovereignty unavailable' => [['code' => 'sovereignty_unavailable'], ModelUnavailableException::class, 503],
    'rate limit' => [['code' => 'rate_limit_exceeded'], RateLimitedException::class, 429],
    'router unavailable' => [['code' => 'team_router_unavailable'], ModelUnavailableException::class, 503],
    'loop detected' => [['code' => 'loop_detected'], ServerException::class, 508],
    'model not allowed' => [['code' => 'model_not_allowed'], ModelNotAllowedException::class],
    'personal key' => [['code' => 'personal_key_required'], PersonalKeyRequiredException::class],
    'unavailable' => [['code' => 'model_unavailable'], ModelUnavailableException::class],
    'numeric status' => [['status' => 429], RateLimitedException::class],
    'numeric code' => [['code' => 429], RateLimitedException::class],
    'unknown code' => [['code' => 'something_else'], ServerException::class],
]);
