<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

use Carbon\CarbonImmutable;
use Prism\Prism\Exceptions\PrismException;
use Throwable;

/**
 * Base class for every error raised by this package. It holds the message the
 * API returned, never the request, so it is safe to report.
 */
class HostYourAIException extends PrismException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);

        $this->httpStatus = $status;
    }

    /**
     * The moment the API asked you to wait until, when it said so.
     */
    public function retryAt(): ?CarbonImmutable
    {
        return $this->retryAfter === null ? null : CarbonImmutable::now()->addSeconds($this->retryAfter);
    }
}
