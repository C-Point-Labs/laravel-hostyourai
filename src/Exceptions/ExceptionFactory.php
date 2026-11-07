<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

use Carbon\CarbonImmutable;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Turns API errors into typed exceptions. The API identifies an error by its
 * HTTP status, and by "code" or "type" in the body, so all of them are read.
 *
 * @internal
 */
final class ExceptionFactory
{
    /**
     * The status a code stands for, in a stream that already answered 200.
     */
    private const STATUS_BY_CODE = [
        'team_budget_exceeded' => 402,
        'team_model_budget_exceeded' => 402,
        'model_not_allowed' => 403,
        'personal_key_required' => 403,
        'sovereignty_violation' => 403,
        'rate_limit_exceeded' => 429,
        'model_unavailable' => 503,
        'sovereignty_unavailable' => 503,
        'team_router_unavailable' => 503,
        'loop_detected' => 508,
    ];

    public static function fromRequestException(RequestException $e): HostYourAIException
    {
        // Not chained: the response's transfer stats hold the request, with its headers and body.
        $response = $e->response;

        // Read the body once: a streamed error response is not seekable.
        $data = json_decode($response->body(), true);
        $requestId = $response->header('X-Request-Id');

        return self::make(
            $response->status(),
            is_array($data) ? Payload::map($data, 'error') : [],
            $requestId === '' ? null : $requestId,
            self::parseRetryAfter($response->header('Retry-After')),
        );
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function make(int $status, array $error, ?string $requestId = null, ?int $retryAfter = null): HostYourAIException
    {
        $type = is_string($error['type'] ?? null) ? $error['type'] : null;
        $code = is_scalar($error['code'] ?? null) ? (string) $error['code'] : null;
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "HostYourAI request failed with status {$status}.";

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 402 => BudgetExceededException::class,
            $status === 403 && $code === 'model_not_allowed' => ModelNotAllowedException::class,
            $status === 403 && $code === 'personal_key_required' => PersonalKeyRequiredException::class,
            $status === 403 && $code === 'sovereignty_violation' => SovereigntyViolationException::class,
            in_array($status, [400, 404, 413, 422], true) => InvalidRequestException::class,
            $status === 429 => RateLimitedException::class,
            $status === 503 => ModelUnavailableException::class,
            $status >= 500 => ServerException::class,
            default => HostYourAIException::class,
        };

        return new $class($message, $status, $type, $code, $requestId, $retryAfter);
    }

    /**
     * An error sent inside a 200 response, for example as a stream chunk.
     *
     * @param array<string, mixed> $error
     */
    public static function fromErrorPayload(array $error): HostYourAIException
    {
        $status = $error['status'] ?? $error['code'] ?? null;

        if (is_numeric($status)) {
            return self::make((int) $status, $error);
        }

        return self::make(is_string($status) ? self::STATUS_BY_CODE[$status] ?? 500 : 500, $error);
    }

    public static function fromConnectionError(HttpConnectionException $e): ConnectionException
    {
        // Not chained: the underlying exception holds the request, with its headers and body.
        return new ConnectionException('Could not reach HostYourAI: '.$e->getMessage());
    }

    /**
     * Retry-After holds either a number of seconds or an HTTP date.
     */
    public static function parseRetryAfter(?string $value): ?int
    {
        $value = trim($value ?? '');

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            return max(0, (int) ceil(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($value), false)));
        } catch (Throwable) {
            return null;
        }
    }
}
