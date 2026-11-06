<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * The model cannot serve this request right now (503). Check retryAfter, which may be null.
 */
class ModelUnavailableException extends HostYourAIException {}
