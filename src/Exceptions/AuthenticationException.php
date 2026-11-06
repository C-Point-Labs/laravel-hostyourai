<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Exceptions;

/**
 * The API key is missing, invalid or revoked (401).
 */
class AuthenticationException extends HostYourAIException {}
