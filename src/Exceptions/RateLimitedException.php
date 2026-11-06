<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * Too many requests (429). Check retryAfter.
 */
class RateLimitedException extends HostYourAIException {}
